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

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly SlackClient $slack,
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

        $due = (new ContactModel($this->db))->dueBy($today, 0);
        $sent = 0;
        $skipped = 0;

        foreach ($this->config->slackDebriefUsers as $slackUser) {
            if (!$force && $this->alreadySentFor($today, $slackUser)) {
                $skipped++;
                continue;
            }

            [$text, $blocks] = $this->buildMessage($due, $today);
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
        $due = (new ContactModel($this->db))->dueBy($today, 0);

        [$text, $blocks] = $this->buildMessage($due, $today);
        $this->slack->postMessage($slackUser, $text, $blocks);

        return sprintf('debrief: sent on demand to %s (%d due)', $slackUser, count($due));
    }

    public static function isSendTime(DateTimeImmutable $nowLocal): bool
    {
        $weekday = (int) $nowLocal->format('N') <= 5;

        return $weekday && (int) $nowLocal->format('G') >= self::SEND_HOUR_LOCAL;
    }

    /**
     * @param list<array<string, mixed>> $due
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function buildMessage(array $due, string $today): array
    {
        if ($due === []) {
            return [
                'Nothing was due today. Anything worth logging?',
                [[
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => ':tada: *Nothing was due today.* Anything worth logging? '
                            . $this->appLink('Open Rolo'),
                    ],
                ]],
            ];
        }

        $text = sprintf('You had %d follow-up%s due today.', count($due), count($due) === 1 ? '' : 's');
        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => '*' . $text . '* How did it go?'],
        ]];

        foreach (array_slice($due, 0, self::MAX_PROMPTS) as $contact) {
            $id = (int) $contact['id'];
            $name = (string) $contact['name'];
            $org = (string) ($contact['organization_name'] ?? '');
            $dueDate = (string) $contact['next_touch_date'];

            $line = sprintf(
                'Did you reach *%s*%s?%s',
                $name,
                $org !== '' ? ' (' . $org . ')' : '',
                $dueDate < $today ? sprintf(' _(due %s)_', $dueDate) : '',
            );

            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $line]];
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    $this->button('Log touch', 'log', $id, 'primary'),
                    $this->openButton($id),
                    $this->button('Snooze 3 days', 'snooze', $id),
                    $this->button('Skip', 'skip', $id),
                ],
            ];
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

        $blocks[] = [
            'type' => 'context',
            'elements' => [[
                'type' => 'mrkdwn',
                'text' => 'Log touch opens a short form (type, note, follow-up). '
                    . 'Skip leaves the contact for the next morning email.',
            ]],
        ];

        return [$text, $blocks];
    }

    /**
     * @return array<string, mixed>
     */
    private function button(string $label, string $action, int $contactId, ?string $style = null): array
    {
        $button = [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => $label],
            'action_id' => sprintf('%s_%d', $action, $contactId),
            'value' => json_encode(['a' => $action, 'c' => $contactId], JSON_THROW_ON_ERROR),
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
