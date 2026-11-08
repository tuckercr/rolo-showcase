<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\ActivityModel;
use Tests\Database\DatabaseTestCase;

final class ActivityModelTest extends DatabaseTestCase
{
    private ActivityModel $activities;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activities = new ActivityModel(self::db());

        $stmt = self::db()->pdo()->prepare(
            "INSERT INTO contacts (name, relationship_status, created_by, updated_by)
             VALUES ('Busy Beth', 'Conversation Started', 1, 1)"
        );
        $stmt->execute();
        $this->contactId = (int) self::db()->pdo()->lastInsertId();
    }

    public function testLatestTouchForIgnoresSnoozes(): void
    {
        $this->log('email', '2026-10-01', 'real touch');
        $this->log('snoozed', '2026-10-15', 'snoozed from the dashboard');

        $latest = $this->activities->latestTouchFor($this->contactId);

        self::assertNotNull($latest);
        self::assertSame('real touch', $latest['summary'], 'a snooze is bookkeeping, not contact');
    }

    public function testLatestTouchForBreaksSameDayTiesByNewestEntry(): void
    {
        $this->log('call', '2026-10-10', 'logged first');
        $this->log('email', '2026-10-10', 'logged second');

        $latest = $this->activities->latestTouchFor($this->contactId);

        self::assertNotNull($latest);
        self::assertSame('logged second', $latest['summary']);
    }

    public function testLatestTouchForIsNullWithNoRealTouches(): void
    {
        $this->log('snoozed', '2026-10-15', 'only a snooze');

        self::assertNull($this->activities->latestTouchFor($this->contactId));
    }

    private function log(string $type, string $date, string $summary): void
    {
        $this->activities->create(
            contactId: $this->contactId,
            organizationId: null,
            activityType: $type,
            activityDate: $date,
            summary: $summary,
            followUpNeeded: false,
            followUpDate: null,
            userId: 1,
        );
    }
}
