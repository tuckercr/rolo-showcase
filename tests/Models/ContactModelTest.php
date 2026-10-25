<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\ContactModel;
use App\Models\StageModel;
use Tests\Database\DatabaseTestCase;

/**
 * Real-SQL coverage for the two dashboard queries. Both have burned us in
 * subtle ways before (boundary dates, stage exclusions, the hidden flag),
 * so every rule gets a row that would fail if the WHERE clause regressed.
 */
final class ContactModelTest extends DatabaseTestCase
{
    private const TODAY = '2026-10-24';

    private ContactModel $contacts;
    private StageModel $stages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contacts = new ContactModel(self::db());
        $this->stages = new StageModel(self::db());
    }

    public function testDueByIncludesOverdueThroughHorizonInDateOrder(): void
    {
        $this->insertContact('Overdue Olive', next: '2026-10-20');
        $this->insertContact('Today Tess', next: self::TODAY);
        $this->insertContact('Horizon Hank', next: '2026-10-30');   // today + 6: included
        $this->insertContact('Too Far Fiona', next: '2026-10-31');  // today + 7: excluded
        $this->insertContact('Unscheduled Uma', next: null);

        $names = array_column($this->contacts->dueBy(self::TODAY), 'name');

        self::assertSame(['Overdue Olive', 'Today Tess', 'Horizon Hank'], $names);
    }

    public function testDueByOrdersByDateThenName(): void
    {
        $this->insertContact('Zoe Zimmer', next: self::TODAY);
        $this->insertContact('Amy Adams', next: self::TODAY);

        $names = array_column($this->contacts->dueBy(self::TODAY), 'name');

        self::assertSame(['Amy Adams', 'Zoe Zimmer'], $names);
    }

    public function testDueBySkipsSoftDeletedContacts(): void
    {
        $id = $this->insertContact('Deleted Dana', next: '2026-10-01');
        self::db()->pdo()->exec("UPDATE contacts SET deleted_at = NOW() WHERE id = $id");

        self::assertSame([], $this->contacts->dueBy(self::TODAY));
    }

    public function testDueBySkipsHiddenStagesAndSetHiddenControlsIt(): void
    {
        $this->insertContact('Lead Larry', next: '2026-10-01', stage: 'Leads');

        self::assertSame([], $this->contacts->dueBy(self::TODAY), 'Leads is hidden by default');

        $this->stages->setHidden($this->stageId('Leads'), false);
        self::assertSame(
            ['Lead Larry'],
            array_column($this->contacts->dueBy(self::TODAY), 'name'),
            'un-hiding the stage brings its contacts back',
        );

        $this->stages->setHidden($this->stageId('Leads'), true);
        self::assertSame([], $this->contacts->dueBy(self::TODAY));
    }

    public function testDueByHidingAnotherStageRemovesItsContacts(): void
    {
        $this->insertContact('Dormant Dave', next: self::TODAY, stage: 'Dormant');

        self::assertCount(1, $this->contacts->dueBy(self::TODAY), 'Dormant is visible by default');

        $this->stages->setHidden($this->stageId('Dormant'), true);
        self::assertSame([], $this->contacts->dueBy(self::TODAY));
    }

    public function testGoingColdIncludesLongOverdueWithAStrictThirtyDayBoundary(): void
    {
        $this->insertContact('Cold Carl', next: '2026-09-01');       // 53 days late
        $this->insertContact('Boundary Bea', next: '2026-09-24');    // exactly 30: not cold yet
        $this->insertContact('Just Cold Jo', next: '2026-09-23');    // 31 days late
        $this->insertContact('Fresh Fred', next: self::TODAY);

        $names = array_column($this->contacts->goingCold(self::TODAY), 'name');

        sort($names);
        self::assertSame(['Cold Carl', 'Just Cold Jo'], $names);
    }

    public function testGoingColdIncludesNeverScheduledAfterTheGracePeriod(): void
    {
        $this->insertContact('Old Oscar', next: null, createdAt: '2026-10-01 12:00:00');
        $this->insertContact('New Nora', next: null, createdAt: '2026-10-21 12:00:00');

        $names = array_column($this->contacts->goingCold(self::TODAY), 'name');

        self::assertSame(['Old Oscar'], $names, 'a contact created 3 days ago is still in the grace period');
    }

    public function testGoingColdExcludesParkedAndHiddenStages(): void
    {
        $this->insertContact('Dormant Dora', next: '2026-01-01', stage: 'Dormant');
        $this->insertContact('Closed Cleo', next: '2026-01-01', stage: 'Closed / Not a Fit');
        $this->insertContact('Hidden Hugo', next: '2026-01-01', stage: 'Intro Sent');
        $this->insertContact('Visible Vera', next: '2026-01-01', stage: 'Conversation Started');

        $this->stages->setHidden($this->stageId('Intro Sent'), true);

        $names = array_column($this->contacts->goingCold(self::TODAY), 'name');

        self::assertSame(['Visible Vera'], $names);
    }

    public function testGoingColdOrdersNeverContactedFirstThenOldestTouch(): void
    {
        $this->insertContact('Touched Tina', next: '2026-09-01', lastTouch: '2026-08-20');
        $this->insertContact('Older Omar', next: '2026-09-01', lastTouch: '2026-07-01');
        $this->insertContact('Never Nia', next: '2026-09-01', lastTouch: null);

        $names = array_column($this->contacts->goingCold(self::TODAY), 'name');

        self::assertSame(['Never Nia', 'Older Omar', 'Touched Tina'], $names);
    }

    public function testFollowUpNoteComesFromTheLatestNonSnoozedActivity(): void
    {
        $id = $this->insertContact('Noted Nate', next: self::TODAY);
        $this->insertActivity($id, '2026-10-01', followUpNeeded: true, note: 'old note');
        $this->insertActivity($id, '2026-10-10', followUpNeeded: true, note: 'current note');
        $this->insertActivity($id, '2026-10-15', followUpNeeded: false, note: null, type: 'snoozed');

        $rows = $this->contacts->dueBy(self::TODAY);

        self::assertSame('current note', $rows[0]['follow_up_note']);
    }

    public function testFollowUpNoteClearsWhenTheLatestActivityNeedsNoFollowUp(): void
    {
        $id = $this->insertContact('Done Dina', next: self::TODAY);
        $this->insertActivity($id, '2026-10-01', followUpNeeded: true, note: 'stale note');
        $this->insertActivity($id, '2026-10-10', followUpNeeded: false, note: null);

        $rows = $this->contacts->dueBy(self::TODAY);

        self::assertNull($rows[0]['follow_up_note']);
    }

    private function insertContact(
        string $name,
        ?string $next,
        string $stage = 'Conversation Started',
        ?string $lastTouch = null,
        string $createdAt = '2026-08-01 12:00:00',
    ): int {
        $stmt = self::db()->pdo()->prepare(
            'INSERT INTO contacts
                (name, relationship_status, last_touch_date, next_touch_date,
                 created_by, updated_by, created_at)
             VALUES (:name, :stage, :last_touch, :next_touch, 1, 1, :created_at)'
        );
        $stmt->execute([
            'name' => $name,
            'stage' => $stage,
            'last_touch' => $lastTouch,
            'next_touch' => $next,
            'created_at' => $createdAt,
        ]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    private function insertActivity(
        int $contactId,
        string $date,
        bool $followUpNeeded,
        ?string $note,
        string $type = 'email',
    ): void {
        $stmt = self::db()->pdo()->prepare(
            'INSERT INTO activities
                (contact_id, activity_type, activity_date, summary,
                 follow_up_needed, follow_up_note, created_by)
             VALUES (:contact_id, :type, :date, :summary, :needed, :note, 1)'
        );
        $stmt->execute([
            'contact_id' => $contactId,
            'type' => $type,
            'date' => $date,
            'summary' => 'test activity',
            'needed' => (int) $followUpNeeded,
            'note' => $note,
        ]);
    }

    private function stageId(string $name): int
    {
        $stmt = self::db()->pdo()->prepare('SELECT id FROM stages WHERE name = :name');
        $stmt->execute(['name' => $name]);

        return (int) $stmt->fetchColumn();
    }
}
