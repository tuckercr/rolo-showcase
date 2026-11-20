<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContactModel;
use App\Support\Config;
use App\Support\Database;
use App\Support\Mrkdwn;
use App\Support\SlackClient;
use DateTimeImmutable;

/**
 * Monday-morning DM listing the contacts that went cold in the last week —
 * the dashboard's Going cold section, delivered. "New" is computed
 * statelessly: in today's goingCold() set but not in the set as of a week
 * ago. Rides the morning cron tick; slack_notice_log dedupes the UTC
 * double-fire. No new-cold contacts = no message.
 */
final class SlackColdAlertService
{
    public const KIND = 'cold';

    /** At most this many contacts are listed; the rest are a count. */
    public const MAX_ROWS = 8;

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
        if ($this->config->slackBotToken === '' || $this->config->slackDebriefUsers === []) {
            return 'cold alert: skipped, not configured';
        }

        $now = new DateTimeImmutable('now', $this->config->appTimezone);

        if (!$force && (int) $now->format('N') !== 1) {
            return 'cold alert: skipped, only runs on Mondays';
        }

        $today = $now->format('Y-m-d');
        $lastWeek = $now->modify('-7 days')->format('Y-m-d');
        $contacts = new ContactModel($this->db);

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $quiet = 0;
        $newCounts = [];

        foreach ($this->config->slackDebriefUsers as $slackUser) {
            if (!$force && $this->alreadySentFor($today, $slackUser)) {
                $skipped++;
                continue;
            }

            // Each person is alerted about THEIR newly cold contacts plus
            // shared ones (the dashboard's Mine lens); an unmapped Slack
            // account keeps the combined view.
            $lens = $this->config->slackUserMap[$slackUser] ?? null;
            $cold = $contacts->goingCold($today, forUserId: $lens);
            $lastWeekIds = array_map(
                static fn(array $c): int => (int) $c['id'],
                $contacts->goingCold($lastWeek, forUserId: $lens),
            );

            $new = array_values(array_filter(
                $cold,
                static fn(array $c): bool => !in_array((int) $c['id'], $lastWeekIds, true),
            ));

            if ($new === []) {
                $quiet++;
                continue;
            }

            [$text, $blocks] = $this->buildMessage($new, count($cold) - count($new));

            // One person's broken DM must not stop the other's.
            try {
                $this->slack->postMessage($slackUser, $text, $blocks);
            } catch (\Throwable) {
                $failed++;
                continue;
            }

            // A forced run is a manual test; it must not claim the day's
            // slot and silence the real Monday send (the same rule the
            // debrief learned on 2026-11-10).
            if (!$force) {
                $this->recordSent($today, $slackUser);
            }

            $sent++;
            $newCounts[] = count($new);
        }

        if ($sent === 0 && $failed === 0 && $skipped === 0) {
            return sprintf(
                'cold alert: nothing newly cold (%d already cold)',
                count($contacts->goingCold($today)),
            );
        }

        return sprintf(
            'cold alert: sent %d, already sent %d, quiet %d, newly cold %s%s',
            $sent,
            $skipped,
            $quiet,
            $newCounts === [] ? '0' : implode('/', $newCounts),
            $failed > 0 ? sprintf(', %d DM(s) failed', $failed) : '',
        );
    }

    /**
     * @param list<array<string, mixed>> $new
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function buildMessage(array $new, int $stillCold): array
    {
        $url = $this->config->baseUrl();
        $text = sprintf(
            '%d contact%s went cold this week.',
            count($new),
            count($new) === 1 ? '' : 's',
        );

        $blocks = [[
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => sprintf(
                    ':snowflake: *%s* More than 30 days past their follow-up, or nothing scheduled:',
                    $text,
                ),
            ],
        ]];

        foreach (array_slice($new, 0, self::MAX_ROWS) as $contact) {
            $id = (int) $contact['id'];
            $org = (string) ($contact['organization_name'] ?? '');
            $last = (string) ($contact['last_touch_date'] ?? '');

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        '*%s*%s · last contacted %s',
                        Mrkdwn::esc((string) $contact['name']),
                        $org !== '' ? ' (' . Mrkdwn::esc($org) . ')' : '',
                        $last !== '' ? $last : 'never',
                    ),
                ],
            ];
            // Open + Edit per row: Edit opens the stage form, so a contact
            // that is cold because it is over can be moved to Dormant or
            // Closed right from the alert (Colin, 2026-11-16).
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Open'],
                        'url' => sprintf('%s/contacts/%d', $url, $id),
                        'action_id' => 'open_' . $id,
                        'value' => json_encode(['a' => 'open', 'c' => $id], JSON_THROW_ON_ERROR),
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Edit'],
                        'action_id' => 'edit_' . $id,
                        'value' => json_encode(['a' => 'edit', 'c' => $id], JSON_THROW_ON_ERROR),
                    ],
                ],
            ];
        }

        $footer = [];

        if (count($new) > self::MAX_ROWS) {
            $footer[] = sprintf('Plus %d more newly cold.', count($new) - self::MAX_ROWS);
        }

        if ($stillCold > 0) {
            $footer[] = sprintf('%d older ones are still cold.', $stillCold);
        }

        $footer[] = sprintf('The full list is at the bottom of the <%s|dashboard>.', $url);

        $blocks[] = [
            'type' => 'context',
            'elements' => [['type' => 'mrkdwn', 'text' => implode(' ', $footer)]],
        ];

        return [$text, $blocks];
    }

    private function alreadySentFor(string $date, string $slackUser): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM slack_notice_log
             WHERE kind = :kind AND notice_date = :date AND slack_user = :user'
        );
        $stmt->execute(['kind' => self::KIND, 'date' => $date, 'user' => $slackUser]);

        return $stmt->fetch() !== false;
    }

    private function recordSent(string $date, string $slackUser): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO slack_notice_log (kind, notice_date, slack_user)
             VALUES (:kind, :date, :user)
             ON DUPLICATE KEY UPDATE sent_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['kind' => self::KIND, 'date' => $date, 'user' => $slackUser]);
    }
}
