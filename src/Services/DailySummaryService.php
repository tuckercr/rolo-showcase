<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Support\Config;
use App\Support\Database;
use App\Support\Mailer;
use App\Support\View;
use DateTimeImmutable;

/**
 * The 9 AM Eastern daily email: overdue + due-today follow-ups and
 * yesterday's logged activity, topped with a "have you logged what you did
 * yesterday?" nudge linking back to the app.
 *
 * The cron trigger fires twice a day in UTC (13:00 + 14:00) so one of them
 * is always 9 AM in America/New_York regardless of DST; isSendTime() plus
 * the daily_summary_log unique key turn the other into a no-op.
 */
final class DailySummaryService
{
    public const SEND_HOUR_LOCAL = 9;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Mailer $mailer,
    ) {
    }

    /**
     * @return string human-readable outcome (logged by the caller)
     */
    public function run(bool $force = false): string
    {
        $now = new DateTimeImmutable('now', $this->config->appTimezone);
        $today = $now->format('Y-m-d');

        if ($this->config->summaryRecipients === []) {
            return 'skipped: SUMMARY_RECIPIENTS is not configured';
        }

        if (!$force && !self::isSendTime($now)) {
            return sprintf('skipped: %s is before %02d:00 local', $now->format('H:i'), self::SEND_HOUR_LOCAL);
        }

        if (!$force && $this->alreadySentFor($today)) {
            return 'skipped: already sent today';
        }

        $data = $this->gather($now);
        $html = View::render('emails/daily_summary', $data);
        $subject = sprintf(
            'Rolo: %d overdue, %d due today (%s)',
            count($data['overdue']),
            count($data['dueToday']),
            $now->format('D M j'),
        );

        $this->mailer->send($this->config->summaryRecipients, $subject, $html);
        $this->recordSent($today);

        return 'sent to ' . implode(', ', $this->config->summaryRecipients);
    }

    public static function isSendTime(DateTimeImmutable $nowLocal): bool
    {
        return (int) $nowLocal->format('G') >= self::SEND_HOUR_LOCAL;
    }

    /**
     * @return array{
     *     appUrl: string,
     *     overdue: list<array<string, mixed>>,
     *     dueToday: list<array<string, mixed>>,
     *     yesterdayActivities: list<array<string, mixed>>,
     *     activityTypes: array<string, string>,
     *     todayLabel: string,
     *     yesterdayLabel: string
     * }
     */
    private function gather(DateTimeImmutable $nowLocal): array
    {
        $today = $nowLocal->format('Y-m-d');
        $yesterday = $nowLocal->modify('-1 day')->format('Y-m-d');

        $due = (new ContactModel($this->db))->dueBy($today, 0);

        $overdue = array_values(array_filter(
            $due,
            static fn(array $c): bool => (string) $c['next_touch_date'] < $today,
        ));
        $dueToday = array_values(array_filter(
            $due,
            static fn(array $c): bool => (string) $c['next_touch_date'] === $today,
        ));

        return [
            'appUrl' => $this->config->appUrl !== '' ? $this->config->appUrl : 'https://crm.example.com',
            'overdue' => $overdue,
            'dueToday' => $dueToday,
            'yesterdayActivities' => (new ActivityModel($this->db))->onDate($yesterday),
            'activityTypes' => ActivityModel::DISPLAY_TYPES,
            'todayLabel' => $nowLocal->format('l, F j'),
            'yesterdayLabel' => $nowLocal->modify('-1 day')->format('l'),
        ];
    }

    private function alreadySentFor(string $date): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM daily_summary_log WHERE summary_date = :date'
        );
        $stmt->execute(['date' => $date]);

        return $stmt->fetch() !== false;
    }

    private function recordSent(string $date): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO daily_summary_log (summary_date, recipients)
             VALUES (:date, :recipients)
             ON DUPLICATE KEY UPDATE sent_at = CURRENT_TIMESTAMP, recipients = :recipients2'
        );
        $stmt->execute([
            'date' => $date,
            'recipients' => implode(',', $this->config->summaryRecipients),
            'recipients2' => implode(',', $this->config->summaryRecipients),
        ]);
    }
}
