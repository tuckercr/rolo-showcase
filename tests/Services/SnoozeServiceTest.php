<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Models\ContactModel;
use App\Services\SnoozeService;
use Tests\Database\DatabaseTestCase;

/**
 * The shared reschedule path every snooze surface goes through. The trace
 * summary's exact format is load-bearing — it is what the contact history
 * shows — so it is pinned here.
 */
final class SnoozeServiceTest extends DatabaseTestCase
{
    private function insertContact(?string $next): array
    {
        $stmt = self::db()->pdo()->prepare(
            'INSERT INTO contacts (name, relationship_status, next_touch_date, created_by, updated_by)
             VALUES (?, ?, ?, 1, 1)'
        );
        $stmt->execute(['Pat Example', 'Conversation Started', $next]);

        return (array) (new ContactModel(self::db()))->find((int) self::db()->pdo()->lastInsertId());
    }

    private function lastActivitySummary(int $contactId): string
    {
        $stmt = self::db()->pdo()->prepare(
            'SELECT activity_type, summary FROM activities WHERE contact_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$contactId]);
        $row = (array) $stmt->fetch();

        self::assertSame('snoozed', $row['activity_type']);

        return (string) $row['summary'];
    }

    public function testRescheduleMovesTheDateAndLeavesTheTrace(): void
    {
        $contact = $this->insertContact('2026-11-10');

        (new SnoozeService(self::db()))->reschedule($contact, '2026-11-21', 1, '2026-11-18');

        $fresh = (new ContactModel(self::db()))->find((int) $contact['id']);
        self::assertSame('2026-11-21', (string) $fresh['next_touch_date']);
        self::assertSame(
            'Modified follow up date from: 2026-11-10, to: 2026-11-21',
            $this->lastActivitySummary((int) $contact['id']),
        );
        // A snooze is not a touch.
        self::assertNull($fresh['last_touch_date']);
    }

    public function testNoteAndMissingDatesRenderInTheTrace(): void
    {
        $contact = $this->insertContact(null);

        (new SnoozeService(self::db()))->reschedule($contact, null, 1, '2026-11-18', note: 'via /rolo snooze');

        self::assertSame(
            'Modified follow up date from: none, to: none (via /rolo snooze)',
            $this->lastActivitySummary((int) $contact['id']),
        );
    }
}
