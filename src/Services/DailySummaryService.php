<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\ProposalModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Support\Config;
use App\Support\Database;
use App\Support\Mailer;
use App\Support\TaskLabels;
use App\Support\View;
use DateTimeImmutable;

/**
 * The 9 AM Eastern daily email: overdue + due-today follow-ups and
 * yesterday's logged activity, topped with a "have you logged what you did
 * yesterday?" nudge linking back to the app.
 *
 * Per-recipient since 2026-11-19: a recipient whose email matches a users
 * row gets THEIR follow-ups (contacts they own plus shared ones — the same
 * lens as the dashboard's Mine view) and, since 2026-11-20, THEIR tasks
 * that are overdue or due today under the same lens, while yesterday's
 * activity and the approvals count stay workspace-wide. A recipient with no users row gets
 * the combined everyone view. A recipient whose email would say nothing at
 * all (no follow-ups, no activity, no approvals) is skipped for the day.
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
            return sprintf(
                'skipped: %s %s is not a weekday after %02d:00 local',
                $now->format('D'),
                $now->format('H:i'),
                self::SEND_HOUR_LOCAL,
            );
        }

        if (!$force && $this->alreadySentFor($today)) {
            return 'skipped: already sent today';
        }

        $users = new UserModel($this->db);
        $sent = [];
        $quiet = [];
        $failed = [];

        foreach ($this->config->summaryRecipients as $recipient) {
            $user = $users->findByEmail($recipient);
            $data = $this->gather($now, $user === null ? null : (int) $user['id']);

            if (!self::hasNews($data)) {
                $quiet[] = $recipient;
                continue;
            }

            [$photoCids, $inlineImages] = $this->photoEmbeds($data);
            $data['photoCids'] = $photoCids;
            $html = View::render('emails/daily_summary', $data);
            $subject = self::subject($data, $now);

            try {
                $this->mailer->send([$recipient], $subject, $html, $inlineImages);
                $sent[] = $recipient;
            } catch (\Throwable $e) {
                // One bad mailbox must not cost the other person their
                // email; the failure still surfaces in the cron outcome.
                $failed[] = $recipient . ' (' . $e->getMessage() . ')';
            } finally {
                foreach ($inlineImages as $tmp) {
                    @unlink($tmp);
                }
            }
        }

        // Record the day even when nobody had news, so the second DST cron
        // tick doesn't re-evaluate an hour later.
        $this->recordSent($today, $sent);

        $parts = [];

        if ($sent !== []) {
            $parts[] = 'sent to ' . implode(', ', $sent);
        }

        if ($quiet !== []) {
            $parts[] = 'nothing to report for ' . implode(', ', $quiet);
        }

        if ($failed !== []) {
            $parts[] = 'FAILED for ' . implode(', ', $failed);
        }

        return implode('; ', $parts);
    }

    /**
     * The subject line: follow-up counts always, tasks and approvals only
     * when there are some.
     *
     * @param array<string, mixed> $data gather() output
     */
    public static function subject(array $data, DateTimeImmutable $now): string
    {
        $taskCount = count($data['tasks'] ?? []);

        return sprintf(
            'Rolo: %d overdue, %d due today%s%s (%s)',
            count($data['overdue']),
            count($data['dueToday']),
            $taskCount > 0 ? sprintf(', %d task%s', $taskCount, $taskCount === 1 ? '' : 's') : '',
            $data['pendingApprovals'] > 0
                ? sprintf(', %d to approve', $data['pendingApprovals'])
                : '',
            $now->format('D M j'),
        );
    }

    /**
     * Whether a recipient's email would say anything at all. Going-cold is
     * deliberately not news on its own: it is a standing condition that
     * would nag identically every day, and the dashboard already carries it.
     *
     * @param array<string, mixed> $data gather() output
     */
    public static function hasNews(array $data): bool
    {
        return $data['overdue'] !== []
            || $data['dueToday'] !== []
            || ($data['tasks'] ?? []) !== []
            || $data['yesterdayActivities'] !== []
            || (int) $data['pendingApprovals'] > 0;
    }

    /**
     * Tiny inline-attachment thumbnails for every pictured contact in the
     * email (CID embedding: the photo route is session-gated, so mail
     * clients could never fetch it, and Gmail strips data: URIs).
     *
     * @param array<string, mixed> $data gather() output
     * @return array{0: array<int, string>, 1: array<string, string>}
     *         [contact id => cid, cid => temp file path]
     */
    private function photoEmbeds(array $data): array
    {
        $photos = PhotoService::forApp();
        $cids = [];
        $files = [];

        $candidates = [];

        foreach (array_merge($data['overdue'], $data['dueToday']) as $contact) {
            $candidates[(int) $contact['id']] = $contact['photo'] ?? null;
        }

        foreach ($data['yesterdayActivities'] as $activity) {
            $candidates[(int) $activity['contact_id']] ??= $activity['contact_photo'] ?? null;
        }

        foreach ($candidates as $contactId => $photo) {
            if ($photo === null || $photo === '') {
                continue;
            }

            $tmp = $photos->thumbnail((string) $photo, 56);

            if ($tmp === null) {
                continue;
            }

            $cid = 'photo-c' . $contactId;
            $cids[$contactId] = $cid;
            $files[$cid] = $tmp;
        }

        return [$cids, $files];
    }

    public static function isSendTime(DateTimeImmutable $nowLocal): bool
    {
        // Weekdays only (Colin, 2026-11-10): no follow-up email on Saturday
        // or Sunday. Monday's email covers the weekend's logged activity via
        // its "yesterday" section being Sunday, and anything due stays on
        // the dashboard.
        $weekday = (int) $nowLocal->format('N') <= 5;

        return $weekday && (int) $nowLocal->format('G') >= self::SEND_HOUR_LOCAL;
    }

    /**
     * Build one recipient's email data. $forUserId applies the dashboard's
     * Mine lens (their contacts plus shared ones) to the follow-up
     * sections; null keeps the combined everyone view. Yesterday's
     * activity and the approvals count are workspace-wide either way.
     * Public for the database-backed tests.
     *
     * @return array{
     *     appUrl: string,
     *     lensLabel: string,
     *     overdue: list<array<string, mixed>>,
     *     dueToday: list<array<string, mixed>>,
     *     tasks: list<array<string, mixed>>,
     *     goingColdCount: int,
     *     pendingApprovals: int,
     *     yesterdayActivities: list<array<string, mixed>>,
     *     activityTypes: array<string, string>,
     *     todayLabel: string,
     *     yesterdayLabel: string
     * }
     */
    public function gather(DateTimeImmutable $nowLocal, ?int $forUserId = null): array
    {
        $today = $nowLocal->format('Y-m-d');
        $yesterday = $nowLocal->modify('-1 day')->format('Y-m-d');

        $contacts = new ContactModel($this->db);
        $due = $contacts->dueBy($today, 0, $forUserId);
        $goingCold = $contacts->goingCold($today, forUserId: $forUserId);
        $coldIds = array_map(static fn(array $c): int => (int) $c['id'], $goingCold);

        // Same split as the dashboard: going-cold contacts leave the
        // Overdue list so the email's counts match what she sees there.
        $overdue = array_values(array_filter(
            $due,
            static fn(array $c): bool => (string) $c['next_touch_date'] < $today
                && !in_array((int) $c['id'], $coldIds, true),
        ));
        $dueToday = array_values(array_filter(
            $due,
            static fn(array $c): bool => (string) $c['next_touch_date'] === $today,
        ));

        return [
            'appUrl' => $this->config->baseUrl(),
            'lensLabel' => $forUserId === null
                ? ''
                : 'Your follow-ups and tasks: the ones you own plus shared ones.',
            'overdue' => $overdue,
            'dueToday' => $dueToday,
            // Open tasks that are overdue or due today, same lens. Undated
            // and later-dated tasks stay on the dashboard: this email is
            // what needs doing today.
            'tasks' => TaskLabels::decorate(
                (new TaskModel($this->db))->open($forUserId, $today, includeUndated: false),
                $today,
                $this->config->appTimezone,
            ),
            'goingColdCount' => count($goingCold),
            'pendingApprovals' => (new ProposalModel($this->db))->pendingCount(),
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

    /**
     * @param list<string> $recipients who actually got an email today
     */
    private function recordSent(string $date, array $recipients): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO daily_summary_log (summary_date, recipients)
             VALUES (:date, :recipients)
             ON DUPLICATE KEY UPDATE sent_at = CURRENT_TIMESTAMP, recipients = :recipients2'
        );
        $stmt->execute([
            'date' => $date,
            'recipients' => implode(',', $recipients) ?: 'none',
            'recipients2' => implode(',', $recipients) ?: 'none',
        ]);
    }
}
