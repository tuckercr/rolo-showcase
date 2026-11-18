<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContactModel;
use App\Support\Config;
use App\Support\Database;
use App\Support\SlackClient;
use DateTimeImmutable;

/**
 * The weekday evening debrief DM (docs/SLACK_BOT_SPEC.md): one message per
 * configured person listing the contacts whose follow-up was due today or
 * earlier, each with Log touch / Snooze / Skip buttons that post back to
 * /slack/interact.
 *
 * The cron trigger double-fires in UTC (21:00 + 22:00) so one of them is
 * always 5 PM Eastern regardless of DST; isSendTime() plus the
 * slack_debrief_log primary key turn the other into a no-op — the same
 * pattern as DailySummaryService.
 */
final class SlackDebriefService
{
    public const SEND_HOUR_LOCAL = 17;

    /** At most this many contacts get a prompt; the rest are summarized. */
    public const MAX_PROMPTS = 8;

    /** At most this many calendar matches get a suggestion row. */
    public const MAX_CALENDAR_SUGGESTIONS = 4;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly SlackClient $slack,
        private readonly ?CalendarSuggestionService $calendar = null,
    ) {
    }

    /**
     * @return string human-readable outcome (logged by the caller)
     */
    public function run(bool $force = false): string
    {
        if (
            $this->config->slackBotToken === ''
            || $this->config->slackDebriefUsers === []
        ) {
            return 'skipped: Slack debrief is not configured';
        }

        $now = new DateTimeImmutable('now', $this->config->appTimezone);
        $today = $now->format('Y-m-d');

        if (!$force && !self::isSendTime($now)) {
            return sprintf(
                'skipped: %s %s is not a weekday after %d:00 local',
                $now->format('D'),
                $now->format('H:i'),
                self::SEND_HOUR_LOCAL,
            );
        }

        $due = $this->dueForDebrief($today);
        $sent = 0;
        $skipped = 0;

        foreach ($this->config->slackDebriefUsers as $slackUser) {
            if (!$force && $this->alreadySentFor($today, $slackUser)) {
                $skipped++;
                continue;
            }

            [$text, $blocks] = $this->buildMessage($due, $today, $this->calendarSuggestions($slackUser, $today));
            $this->slack->postMessage($slackUser, $text, $blocks);

            // A forced run is a manual test; it must not claim the day's
            // slot and silence the scheduled evening send (bitten 11-10).
            if (!$force) {
                $this->recordSent($today, $slackUser);
            }

            $sent++;
        }

        return sprintf('debrief: sent %d, already sent %d, due contacts %d', $sent, $skipped, count($due));
    }

    /**
     * On-demand debrief for one person (the /rolo debrief command): no time
     * gate and no dedupe row, so an explicit ask always answers and never
     * silences the scheduled evening send.
     *
     * @return string human-readable outcome (logged by the caller)
     */
    public function runForUser(string $slackUser): string
    {
        if ($this->config->slackBotToken === '') {
            return 'skipped: Slack debrief is not configured';
        }

        $today = (new DateTimeImmutable('now', $this->config->appTimezone))->format('Y-m-d');
        $due = $this->dueForDebrief($today);

        [$text, $blocks] = $this->buildMessage($due, $today, $this->calendarSuggestions($slackUser, $today));
        $this->slack->postMessage($slackUser, $text, $blocks);

        return sprintf('debrief: sent on demand to %s (%d due)', $slackUser, count($due));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dueForDebrief(string $today): array
    {
        return (new ContactModel($this->db))->dueExcludingCold($today);
    }

    public static function isSendTime(DateTimeImmutable $nowLocal): bool
    {
        $weekday = (int) $nowLocal->format('N') <= 5;

        return $weekday && (int) $nowLocal->format('G') >= self::SEND_HOUR_LOCAL;
    }

    /**
     * One person's calendar suggestions, and never a reason the debrief
     * doesn't send: Google being down, a revoked token or a missing mapping
     * all collapse to "no calendar section today".
     *
     * @return list<array{contact: array<string, mixed>, title: string, time: ?string}>
     */
    private function calendarSuggestions(string $slackUser, string $today): array
    {
        try {
            $service = $this->calendar ?? new CalendarSuggestionService($this->config, $this->db);

            return $service->suggestionsFor($this->config->slackUserMap[$slackUser] ?? null, $today);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $due
     * @param list<array{contact: array<string, mixed>, title: string, time: ?string}> $calendar
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function buildMessage(array $due, string $today, array $calendar = []): array
    {
        if ($due === []) {
            $blocks = [[
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => ':tada: *Nothing was due today.* Anything worth logging? '
                        . $this->appLink('Open Rolo'),
                ],
            ]];

            foreach ($this->calendarBlocks($calendar) as $block) {
                $blocks[] = $block;
            }

            return ['Nothing was due today. Anything worth logging?', $blocks];
        }

        // Name the two buckets honestly ("29 due today" annoyed Colin when
        // 28 of them were overdue, 2026-11-12).
        $overdueCount = count(array_filter(
            $due,
            static fn(array $c): bool => (string) $c['next_touch_date'] < $today,
        ));
        $todayCount = count($due) - $overdueCount;

        $parts = [];

        if ($overdueCount > 0) {
            $parts[] = sprintf('%d overdue follow-up%s', $overdueCount, $overdueCount === 1 ? '' : 's');
        }

        if ($todayCount > 0) {
            $parts[] = $overdueCount > 0
                ? sprintf('%d due today', $todayCount)
                : sprintf('%d follow-up%s due today', $todayCount, $todayCount === 1 ? '' : 's');
        }

        $text = 'You have ' . implode(' and ', $parts) . '.';
        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => '*' . $text . '* How did it go?'],
        ]];

        foreach (array_slice($due, 0, self::MAX_PROMPTS) as $contact) {
            foreach ($this->promptBlocks($contact, $today) as $block) {
                $blocks[] = $block;
            }
        }

        if (count($due) > self::MAX_PROMPTS) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        'Plus %d more on the %s.',
                        count($due) - self::MAX_PROMPTS,
                        $this->appLink('dashboard'),
                    ),
                ]],
            ];
        }

        foreach ($this->calendarBlocks($calendar) as $block) {
            $blocks[] = $block;
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [[
                'type' => 'mrkdwn',
                'text' => 'Log opens a short form (type, date, note, follow-up). '
                    . 'Skip swaps in the next contact not shown; the skipped one stays in the morning email.',
            ]],
        ];

        return [$text, $blocks];
    }

    /**
     * The "from your calendar" section: one row per contact you met today,
     * with a Log button whose modal comes prefilled with the meeting title
     * (v:'cal' in the value routes that in SlackController). Separate
     * cal_-prefixed action_ids so a contact who is also in the due prompts
     * doesn't produce duplicate ids in one message.
     *
     * @param list<array{contact: array<string, mixed>, title: string, time: ?string}> $calendar
     * @return list<array<string, mixed>>
     */
    private function calendarBlocks(array $calendar): array
    {
        if ($calendar === []) {
            return [];
        }

        $url = $this->config->appUrl !== '' ? $this->config->appUrl : 'https://crm.example.com';
        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => ':calendar: *From your calendar today:*'],
        ]];

        foreach (array_slice($calendar, 0, self::MAX_CALENDAR_SUGGESTIONS) as $suggestion) {
            $contact = $suggestion['contact'];
            $id = (int) $contact['id'];
            $org = (string) ($contact['organization_name'] ?? '');
            $title = $suggestion['title'] !== '' ? $suggestion['title'] : 'a meeting';

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        'You met *%s*%s %s: _%s_',
                        (string) $contact['name'],
                        $org !== '' ? ' (' . $org . ')' : '',
                        $suggestion['time'] !== null ? 'at ' . $suggestion['time'] : 'today',
                        $title,
                    ),
                ],
            ];
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Log it'],
                        'style' => 'primary',
                        'action_id' => 'cal_log_' . $id,
                        'value' => json_encode([
                            'a' => 'log',
                            'c' => $id,
                            'v' => 'cal',
                            's' => mb_substr($title, 0, 150),
                        ], JSON_THROW_ON_ERROR),
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Open'],
                        'url' => sprintf('%s/contacts/%d', $url, $id),
                        'action_id' => 'cal_open_' . $id,
                        'value' => json_encode(['a' => 'open', 'c' => $id], JSON_THROW_ON_ERROR),
                    ],
                ],
            ];
        }

        if (count($calendar) > self::MAX_CALENDAR_SUGGESTIONS) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        'Plus %d more meetings that matched contacts today.',
                        count($calendar) - self::MAX_CALENDAR_SUGGESTIONS,
                    ),
                ]],
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * One debrief prompt: the question line plus its Log/Open/Snooze/Skip
     * row. Public because a Skip tap (SlackController) splices the next
     * undisplayed contact's prompt into the message in place of the skipped
     * one. $skippedIds rides the replacement row's Skip button so contacts
     * skipped earlier are not offered again.
     *
     * @param array<string, mixed> $contact
     * @param list<int> $skippedIds
     * @return list<array<string, mixed>> two blocks: section + actions
     */
    public function promptBlocks(array $contact, string $today, array $skippedIds = []): array
    {
        $id = (int) $contact['id'];
        $org = (string) ($contact['organization_name'] ?? '');
        $dueDate = (string) $contact['next_touch_date'];

        $line = sprintf(
            'Did you reach *%s*%s?%s',
            (string) $contact['name'],
            $org !== '' ? ' (' . $org . ')' : '',
            $dueDate < $today ? sprintf(' _(due %s)_', $dueDate) : '',
        );

        return [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $line]],
            [
                'type' => 'actions',
                'elements' => [
                    $this->button('Log', 'log', $id, 'primary'),
                    $this->openButton($id),
                    $this->button('Snooze 3 days', 'snooze', $id),
                    $this->button('Skip', 'skip', $id, extra: $skippedIds === [] ? [] : ['x' => $skippedIds]),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $extra merged into the button's value JSON
     */
    private function button(
        string $label,
        string $action,
        int $contactId,
        ?string $style = null,
        array $extra = [],
    ): array {
        $button = [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => $label],
            'action_id' => sprintf('%s_%d', $action, $contactId),
            'value' => json_encode(['a' => $action, 'c' => $contactId] + $extra, JSON_THROW_ON_ERROR),
        ];

        if ($style !== null) {
            $button['style'] = $style;
        }

        return $button;
    }

    /**
     * A URL button opening the contact in the browser. Slack still sends a
     * block_actions event for URL buttons, so it carries an action the
     * controller knows to ignore (leave the row's buttons in place).
     *
     * @return array<string, mixed>
     */
    private function openButton(int $contactId): array
    {
        $url = $this->config->appUrl !== '' ? $this->config->appUrl : 'https://crm.example.com';

        return [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => 'Open'],
            'url' => sprintf('%s/contacts/%d', $url, $contactId),
            'action_id' => sprintf('open_%d', $contactId),
            'value' => json_encode(['a' => 'open', 'c' => $contactId], JSON_THROW_ON_ERROR),
        ];
    }

    private function appLink(string $label): string
    {
        $url = $this->config->appUrl !== '' ? $this->config->appUrl : 'https://crm.example.com';

        return sprintf('<%s|%s>', $url, $label);
    }

    private function alreadySentFor(string $date, string $slackUser): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM slack_debrief_log WHERE debrief_date = :date AND slack_user = :user'
        );
        $stmt->execute(['date' => $date, 'user' => $slackUser]);

        return $stmt->fetch() !== false;
    }

    private function recordSent(string $date, string $slackUser): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO slack_debrief_log (debrief_date, slack_user) VALUES (:date, :user)
             ON DUPLICATE KEY UPDATE sent_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['date' => $date, 'user' => $slackUser]);
    }
}
