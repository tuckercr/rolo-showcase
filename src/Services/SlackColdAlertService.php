<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContactModel;
use App\Support\Config;
use App\Support\Database;
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
        $contacts = new ContactModel($this->db);
        $cold = $contacts->goingCold($today);
        $coldLastWeek = $contacts->goingCold($now->modify('-7 days')->format('Y-m-d'));
        $lastWeekIds = array_map(static fn(array $c): int => (int) $c['id'], $coldLastWeek);

        $new = array_values(array_filter(
            $cold,
            static fn(array $c): bool => !in_array((int) $c['id'], $lastWeekIds, true),
        ));

        if ($new === []) {
            return sprintf('cold alert: nothing newly cold (%d already cold)', count($cold));
        }

        [$text, $blocks] = $this->buildMessage($new, count($cold) - count($new));
        $sent = 0;
        $skipped = 0;

        foreach ($this->config->slackDebriefUsers as $slackUser) {
            if (!$force && $this->alreadySentFor($today, $slackUser)) {
                $skipped++;
                continue;
            }

            $this->slack->postMessage($slackUser, $text, $blocks);
            $this->recordSent($today, $slackUser);
            $sent++;
        }

        return sprintf(
            'cold alert: sent %d, already sent %d, newly cold %d',
            $sent,
            $skipped,
            count($new),
        );
    }

    /**
     * @param list<array<string, mixed>> $new
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function buildMessage(array $new, int $stillCold): array
    {
        $url = $this->config->appUrl !== '' ? $this->config->appUrl : 'https://crm.example.com';
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
                        (string) $contact['name'],
                        $org !== '' ? ' (' . $org . ')' : '',
                        $last !== '' ? $last : 'never',
                    ),
                ],
                'accessory' => [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Open'],
                    'url' => sprintf('%s/contacts/%d', $url, $id),
                    'action_id' => 'open_' . $id,
                    'value' => '{"a":"open"}',
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
