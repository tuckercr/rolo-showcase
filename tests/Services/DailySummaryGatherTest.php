<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\DailySummaryService;
use App\Support\Config;
use App\Support\Mailer;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Database\DatabaseTestCase;

/**
 * Real-SQL coverage for the per-recipient email: the follow-up sections
 * follow the dashboard's Mine lens (own + shared), while yesterday's
 * activity stays workspace-wide for both recipients.
 */
final class DailySummaryGatherTest extends DatabaseTestCase
{
    private DailySummaryService $service;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $config = new Config(
            appEnv: 'test',
            appTimezone: new DateTimeZone('America/New_York'),
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
        );
        $this->service = new DailySummaryService($config, self::db(), new Mailer($config));
        $this->now = new DateTimeImmutable('2026-10-24 09:00:00', new DateTimeZone('America/New_York'));
    }

    public function testFollowUpsFollowTheLensActivityStaysGlobal(): void
    {
        // Seeded users: 1 = Colin, 2 = Dana (migration 0002).
        $mine = $this->insertContact('Mine Mia', '2026-10-20', 1);
        $theirs = $this->insertContact('Theirs Theo', '2026-10-20', 2);
        $this->insertContact('Shared Sam', '2026-10-24', null);
        $this->insertActivity($theirs, '2026-10-23', 2);

        $forColin = $this->service->gather($this->now, 1);

        self::assertSame(['Mine Mia'], array_column($forColin['overdue'], 'name'));
        self::assertSame(['Shared Sam'], array_column($forColin['dueToday'], 'name'));
        self::assertCount(
            1,
            $forColin['yesterdayActivities'],
            'what the team logged yesterday is news for both people',
        );
        self::assertSame(
            'Your follow-ups and tasks: the ones you own plus shared ones.',
            $forColin['lensLabel'],
        );

        $combined = $this->service->gather($this->now);

        self::assertCount(2, $combined['overdue']);
        self::assertSame('', $combined['lensLabel'], 'the everyone view carries no lens note');
        self::assertSame($mine, (int) $combined['overdue'][0]['id']);
    }

    public function testTasksFollowTheLensAndOnlyTodaysWorkIsListed(): void
    {
        $this->insertTask('Mine overdue', '2026-10-22', 1);
        $this->insertTask('Mine today', '2026-10-24', 1);
        $this->insertTask('Shared today', '2026-10-24', null);
        $this->insertTask('Theirs today', '2026-10-24', 2);
        $this->insertTask('Mine next week', '2026-10-30', 1);
        $this->insertTask('Mine undated', null, 1);
        $done = $this->insertTask('Mine done', '2026-10-24', 1);
        self::db()->pdo()
            ->prepare('UPDATE tasks SET completed_at = NOW(), completed_by = 1 WHERE id = ?')
            ->execute([$done]);

        $forColin = $this->service->gather($this->now, 1);

        self::assertSame(
            ['Mine overdue', 'Mine today', 'Shared today'],
            array_column($forColin['tasks'], 'title'),
        );
        self::assertSame('due Oct 22 (2 days late)', $forColin['tasks'][0]['due_label']);
        self::assertTrue($forColin['tasks'][0]['is_overdue']);
        self::assertSame('due today', $forColin['tasks'][1]['due_label']);

        self::assertSame(
            ['Shared today', 'Theirs today'],
            array_column($this->service->gather($this->now, 2)['tasks'], 'title'),
        );
        self::assertCount(4, $this->service->gather($this->now)['tasks'], 'the everyone view');

        self::assertTrue(
            DailySummaryService::hasNews($forColin),
            'tasks alone are worth an email',
        );
    }

    private function insertTask(string $title, ?string $due, ?int $owner): int
    {
        $stmt = self::db()->pdo()->prepare(
            'INSERT INTO tasks (title, due_date, owner_id, created_by, updated_by)
             VALUES (:title, :due, :owner, 1, 1)'
        );
        $stmt->execute(['title' => $title, 'due' => $due, 'owner' => $owner]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    private function insertContact(string $name, string $next, ?int $owner): int
    {
        $stmt = self::db()->pdo()->prepare(
            "INSERT INTO contacts
                (name, relationship_status, owner_id, next_touch_date,
                 created_by, updated_by, created_at)
             VALUES (:name, 'Conversation Started', :owner, :next, 1, 1, '2026-08-01 12:00:00')"
        );
        $stmt->execute(['name' => $name, 'owner' => $owner, 'next' => $next]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    private function insertActivity(int $contactId, string $date, int $userId): void
    {
        $stmt = self::db()->pdo()->prepare(
            "INSERT INTO activities
                (contact_id, activity_type, activity_date, summary, follow_up_needed, created_by)
             VALUES (:contact_id, 'call', :date, 'caught up', 0, :user)"
        );
        $stmt->execute(['contact_id' => $contactId, 'date' => $date, 'user' => $userId]);
    }
}
