<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\TaskModel;
use Tests\Database\DatabaseTestCase;

/**
 * Real-SQL coverage for the task queries: the owner lens, the due-date
 * cuts, ordering, the trashed-contact rule and the tick compare-and-set.
 * Seeded users: 1 = Colin, 2 = Dana (migration 0002).
 */
final class TaskModelTest extends DatabaseTestCase
{
    private const TODAY = '2026-11-20';

    private TaskModel $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tasks = new TaskModel(self::db());
    }

    public function testMineLensIsOwnPlusSharedAndEveryoneIsAll(): void
    {
        $this->insertTask('Colin task', owner: 1);
        $this->insertTask('Dana task', owner: 2);
        $this->insertTask('Shared task', owner: null);

        self::assertSame(['Colin task', 'Shared task'], $this->titles($this->tasks->open(1)));
        self::assertSame(['Dana task', 'Shared task'], $this->titles($this->tasks->open(2)));
        self::assertCount(3, $this->tasks->open());
        self::assertSame(2, $this->tasks->countOpen(1));
    }

    public function testOpenIsSoonestFirstWithUndatedLast(): void
    {
        $this->insertTask('No date', due: null);
        $this->insertTask('Next week', due: '2026-11-27');
        $this->insertTask('Overdue', due: '2026-11-18');
        $this->insertTask('Today', due: self::TODAY);

        self::assertSame(
            ['Overdue', 'Today', 'Next week', 'No date'],
            $this->titles($this->tasks->open()),
        );
    }

    public function testDueByKeepsOverdueAndCanDropUndated(): void
    {
        $this->insertTask('No date', due: null);
        $this->insertTask('Next week', due: '2026-11-27');
        $this->insertTask('Overdue', due: '2026-11-18');
        $this->insertTask('Today', due: self::TODAY);

        // The dashboard cut: this week plus undated.
        self::assertSame(
            ['Overdue', 'Today', 'No date'],
            $this->titles($this->tasks->open(null, '2026-11-26')),
        );

        // The email and debrief cut: what needs doing today.
        self::assertSame(
            ['Overdue', 'Today'],
            $this->titles($this->tasks->open(null, self::TODAY, includeUndated: false)),
        );
    }

    public function testDoneTasksLeaveTheOpenListAndComeBackOnReopen(): void
    {
        $id = $this->insertTask('Tick me');

        self::assertTrue($this->tasks->setCompleted($id, true, 2));
        self::assertSame([], $this->tasks->open());

        $done = $this->tasks->completed();
        self::assertSame(['Tick me'], $this->titles($done));
        self::assertSame(2, (int) $done[0]['completed_by']);
        self::assertSame('Dana', $done[0]['completed_by_name']);
        self::assertNotNull($done[0]['completed_at']);

        self::assertTrue($this->tasks->setCompleted($id, false, 1));
        $reopened = $this->tasks->find($id);
        self::assertNull($reopened['completed_at']);
        self::assertNull($reopened['completed_by'], 'reopening clears who ticked it');
        self::assertSame(1, (int) $reopened['updated_by']);
    }

    public function testTickingIsACompareAndSet(): void
    {
        $id = $this->insertTask('Double tap');

        self::assertTrue($this->tasks->setCompleted($id, true, 2));
        self::assertFalse($this->tasks->setCompleted($id, true, 1), 'the second tick changes nothing');
        self::assertSame(2, (int) $this->tasks->find($id)['completed_by'], 'the first person stays recorded');

        self::assertFalse($this->tasks->setCompleted(999999, true, 1), 'no such task');
        self::assertTrue($this->tasks->setCompleted($id, false, 1));
        self::assertFalse($this->tasks->setCompleted($id, false, 1), 'already open');
    }

    public function testCompletedSinceAndLimit(): void
    {
        $old = $this->insertTask('Done last week');
        $new = $this->insertTask('Done just now');
        $this->markDone($old, '2026-11-12 15:00:00');
        $this->markDone($new, '2026-11-20 15:00:00');

        self::assertSame(
            ['Done just now'],
            $this->titles($this->tasks->completed(null, '2026-11-19 15:00:00')),
        );
        self::assertSame(
            ['Done just now', 'Done last week'],
            $this->titles($this->tasks->completed()),
            'most recently ticked first',
        );
        self::assertCount(1, $this->tasks->completed(limit: 1));
    }

    public function testTasksOfATrashedContactHideAndReturnWithIt(): void
    {
        $contact = $this->insertContact('Trashed Tom');
        $id = $this->insertTask('About Tom', contact: $contact);
        $this->insertTask('Unrelated');

        self::db()->pdo()->prepare('UPDATE contacts SET deleted_at = NOW(), deleted_by = 1 WHERE id = ?')
            ->execute([$contact]);

        self::assertSame(['Unrelated'], $this->titles($this->tasks->open()));
        self::assertNull($this->tasks->find($id));
        self::assertSame([], $this->tasks->apiList('all', '', $contact, null, '', 0, 25));

        self::db()->pdo()->prepare('UPDATE contacts SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')
            ->execute([$contact]);

        self::assertSame(['About Tom', 'Unrelated'], $this->titles($this->tasks->open()));
    }

    public function testContactPageShowsEveryOwnersTasksForThatContactOnly(): void
    {
        $steve = $this->insertContact('Steve');
        $maria = $this->insertContact('Maria');
        $this->insertTask('Mine about Steve', owner: 1, contact: $steve);
        $this->insertTask('Hers about Steve', owner: 2, contact: $steve);
        $this->insertTask('About Maria', owner: 1, contact: $maria);
        $done = $this->insertTask('Done about Steve', contact: $steve);
        $this->markDone($done, '2026-11-19 15:00:00');

        self::assertSame(
            ['Mine about Steve', 'Hers about Steve'],
            $this->titles($this->tasks->openForContact($steve)),
            'a record page is not lensed',
        );
        self::assertSame(['Done about Steve'], $this->titles($this->tasks->completedForContact($steve)));
    }

    public function testOrganizationTasksIncludeItsContactsTasks(): void
    {
        $org = $this->insertOrganization('Northfield Labs');
        $other = $this->insertOrganization('Other Co');
        $steve = $this->insertContact('Steve', $org);
        $this->insertTask('About the org', organization: $org);
        $this->insertTask('About Steve', contact: $steve);
        $this->insertTask('About another org', organization: $other);
        $this->insertTask('Free floating');

        $rows = $this->tasks->openForOrganization($org);

        self::assertSame(['About the org', 'About Steve'], $this->titles($rows));
        self::assertSame(
            ['Northfield Labs', 'Northfield Labs'],
            array_column($rows, 'organization_name'),
            'a contact task reads its organization through the contact',
        );
        self::assertSame($org, (int) $rows[1]['linked_organization_id']);
        self::assertNull($rows[1]['organization_id'], 'and stores no organization of its own');
    }

    public function testATaskCannotLinkToBothAContactAndAnOrganization(): void
    {
        $org = $this->insertOrganization('Northfield Labs');
        $steve = $this->insertContact('Steve', $org);

        $this->expectException(\PDOException::class);
        $this->insertTask('Both', contact: $steve, organization: $org);
    }

    public function testApiListFilters(): void
    {
        $steve = $this->insertContact('Steve');
        $a = $this->insertTask('Colin open overdue', owner: 1, due: '2026-11-18');
        $this->insertTask('Dana open later', owner: 2, due: '2026-12-01', contact: $steve);
        $this->insertTask('Shared undated', owner: null);
        $done = $this->insertTask('Colin done', owner: 1, due: '2026-11-10');
        $this->markDone($done, '2026-11-11 15:00:00');

        $list = fn(string $status, string $owner = '', ?int $contact = null, string $dueBefore = ''): array
            => $this->titles($this->tasks->apiList($status, $owner, $contact, null, $dueBefore, 0, 25));

        self::assertSame(['Colin open overdue', 'Dana open later', 'Shared undated'], $list('open'));
        self::assertSame(['Colin done'], $list('done'));
        self::assertCount(4, $list('all'));

        // The exact filter, not the lens: 'me' (resolved to 1) excludes shared.
        self::assertSame(['Colin open overdue'], $list('open', '1'));
        self::assertSame(['Shared undated'], $list('open', 'shared'));
        self::assertSame(['Dana open later'], $list('open', '', $steve));
        self::assertSame(['Colin open overdue'], $list('open', '', null, self::TODAY), 'undated never matches');

        // Cursor: rows after the given id, in id order.
        self::assertSame(
            ['Dana open later', 'Shared undated'],
            $this->titles($this->tasks->apiList('open', '', null, null, '', $a, 25)),
        );
        self::assertCount(1, $this->tasks->apiList('all', '', null, null, '', 0, 1));
    }

    public function testCreateUpdateDeleteAndTrails(): void
    {
        $steve = $this->insertContact('Steve');
        $id = $this->tasks->create([
            'title' => 'Send the deck',
            'notes' => 'The short version',
            'due_date' => '2026-11-24',
            'owner_id' => 2,
            'contact_id' => $steve,
            'organization_id' => null,
        ], 1);

        $task = $this->tasks->find($id);
        self::assertSame('Send the deck', $task['title']);
        self::assertSame('Steve', $task['contact_name']);
        self::assertSame('Dana', $task['owner_name']);
        self::assertSame('Colin', $task['created_by_name']);

        $this->tasks->update($id, [
            'title' => 'Send the long deck',
            'notes' => null,
            'due_date' => null,
            'owner_id' => null,
            'contact_id' => null,
            'organization_id' => null,
        ], 2);

        $task = $this->tasks->find($id);
        self::assertSame('Send the long deck', $task['title']);
        self::assertNull($task['due_date']);
        self::assertNull($task['owner_id']);
        self::assertSame(1, (int) $task['created_by'], 'created_by never moves');
        self::assertSame('Dana', $task['updated_by_name']);

        self::assertTrue($this->tasks->delete($id));
        self::assertNull($this->tasks->find($id));
        self::assertFalse($this->tasks->delete($id));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function titles(array $rows): array
    {
        return array_map(static fn(array $r): string => (string) $r['title'], $rows);
    }

    private function insertTask(
        string $title,
        ?int $owner = 1,
        ?string $due = null,
        ?int $contact = null,
        ?int $organization = null,
    ): int {
        $stmt = self::db()->pdo()->prepare(
            'INSERT INTO tasks (title, due_date, owner_id, contact_id, organization_id, created_by, updated_by)
             VALUES (:title, :due, :owner, :contact, :organization, 1, 1)'
        );
        $stmt->execute([
            'title' => $title,
            'due' => $due,
            'owner' => $owner,
            'contact' => $contact,
            'organization' => $organization,
        ]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    private function markDone(int $taskId, string $completedAt): void
    {
        self::db()->pdo()
            ->prepare('UPDATE tasks SET completed_at = :at, completed_by = 1 WHERE id = :id')
            ->execute(['at' => $completedAt, 'id' => $taskId]);
    }

    private function insertContact(string $name, ?int $organizationId = null): int
    {
        $stmt = self::db()->pdo()->prepare(
            "INSERT INTO contacts (name, organization_id, relationship_status, created_by, updated_by)
             VALUES (:name, :organization_id, 'Conversation Started', 1, 1)"
        );
        $stmt->execute(['name' => $name, 'organization_id' => $organizationId]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    private function insertOrganization(string $name): int
    {
        self::db()->pdo()->prepare('INSERT INTO organizations (name) VALUES (:name)')->execute(['name' => $name]);

        return (int) self::db()->pdo()->lastInsertId();
    }
}
