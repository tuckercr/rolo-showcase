<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Models\ContactModel;
use App\Models\ProposalModel;
use App\Models\TagModel;
use App\Models\TaskModel;
use App\Services\ProposalService;
use App\Support\Config;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Tests\Database\DatabaseTestCase;

/**
 * End-to-end queue → apply coverage for the ownership default (a contact
 * proposed through a per-user token belongs to that person on approval,
 * while the legacy anonymous tokens keep creating shared contacts) and
 * for the three task actions.
 */
final class ProposalServiceTest extends DatabaseTestCase
{
    private ProposalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProposalService(
            new Config(
                appEnv: 'test',
                appTimezone: new DateTimeZone('America/New_York'),
                dbHost: '',
                dbName: '',
                dbUser: '',
                dbPassword: '',
            ),
            self::db(),
        );
    }

    public function testApprovedContactFromAPerUserTokenBelongsToThatUser(): void
    {
        // Seeded users: 1 = Colin, 2 = Dana (migration 0002).
        [$proposal] = $this->service->queue(
            'user:2',
            'create_contact',
            ['name' => 'Proposed Pia', 'stage' => 'New/Captured'],
            proposedBy: 2,
        );

        $this->service->apply($proposal, 1);

        $contact = $this->findContactByName('Proposed Pia');
        self::assertSame(2, (int) $contact['owner_id'], 'owner defaults to the proposing person');
        self::assertSame(1, (int) $contact['created_by'], 'audit trail still records the approver');
    }

    public function testApprovedContactFromALegacyTokenStaysShared(): void
    {
        [$proposal] = $this->service->queue(
            'write',
            'create_contact',
            ['name' => 'Anon Andy', 'stage' => 'New/Captured'],
        );

        $this->service->apply($proposal, 1);

        self::assertNull($this->findContactByName('Anon Andy')['owner_id']);
        self::assertNull((new ProposalModel(self::db()))->find((int) $proposal['id'])['proposed_by']);
    }

    public function testProposedTaskBelongsToTheProposerAndIsCreatedByTheApprover(): void
    {
        $steve = $this->insertContact('Steve Marsh');

        [$proposal, $isNew] = $this->service->queue(
            'user:2',
            'create_task',
            ['title' => 'Send the deck', 'due_date' => '2026-11-24', 'contact_id' => $steve],
            proposedBy: 2,
        );

        self::assertTrue($isNew);
        self::assertSame(
            'Create task "Send the deck" (due 2026-11-24, about Steve Marsh, for Dana)',
            $proposal['summary'],
        );
        self::assertSame([], (new TaskModel(self::db()))->open(), 'nothing exists until a human approves');

        $this->service->apply($proposal, 1);

        $tasks = (new TaskModel(self::db()))->open();
        self::assertCount(1, $tasks);
        self::assertSame(2, (int) $tasks[0]['owner_id'], 'owner defaults to the proposing person');
        self::assertSame(1, (int) $tasks[0]['created_by'], 'the trail records the approver');
        self::assertSame($steve, (int) $tasks[0]['contact_id']);
    }

    public function testTaskOwnerWords(): void
    {
        // 'shared' beats the proposer default.
        [$proposal] = $this->service->queue('user:2', 'create_task', ['title' => 'A', 'owner' => 'shared'], null, 2);
        self::assertNull(json_decode((string) $proposal['payload'], true)['owner_id']);

        // An email names a colleague.
        [$proposal] = $this->service->queue(
            'user:2',
            'create_task',
            ['title' => 'B', 'owner' => 'colin@example.com'],
            null,
            2,
        );
        self::assertSame(1, json_decode((string) $proposal['payload'], true)['owner_id']);

        // A legacy anonymous token has no "me", and its tasks are shared.
        [$proposal] = $this->service->queue('write', 'create_task', ['title' => 'C']);
        self::assertNull(json_decode((string) $proposal['payload'], true)['owner_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->queue('write', 'create_task', ['title' => 'D', 'owner' => 'me']);
    }

    public function testInvalidTaskPayloadsNeverReachTheQueue(): void
    {
        foreach (
            [
                [],
                ['title' => '  '],
                ['title' => 'T', 'due_date' => 'friday'],
                ['title' => 'T', 'contact_id' => 999999],
                ['title' => 'T', 'contact_id' => 'steve'],
            ] as $payload
        ) {
            try {
                $this->service->queue('user:2', 'create_task', $payload, null, 2);
                self::fail('expected a rejection for ' . json_encode($payload));
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        self::assertSame(0, (new ProposalModel(self::db()))->pendingCount());
    }

    public function testUpdateTaskStoresOnlyWhatChangedAndMergesAtApproval(): void
    {
        $taskId = $this->createTask(['title' => 'Send the deck', 'due_date' => '2026-11-24', 'owner_id' => 2]);

        [$proposal] = $this->service->queue(
            'user:2',
            'update_task',
            ['task_id' => $taskId, 'due_date' => '2026-12-01'],
            null,
            2,
        );

        self::assertSame(
            ['task_id' => $taskId, 'due_date' => '2026-12-01'],
            json_decode((string) $proposal['payload'], true),
        );
        self::assertSame('Update task "Send the deck": due_date', $proposal['summary']);

        // Someone retitles the task while the proposal waits...
        self::db()->pdo()->prepare("UPDATE tasks SET title = 'Send the long deck' WHERE id = ?")
            ->execute([$taskId]);

        $this->service->apply($proposal, 1);

        // ...and approval does not revert it.
        $task = (new TaskModel(self::db()))->find($taskId);
        self::assertSame('Send the long deck', $task['title']);
        self::assertSame('2026-12-01', $task['due_date']);
        self::assertSame(2, (int) $task['owner_id']);
        self::assertSame(1, (int) $task['updated_by']);
    }

    public function testUpdateTaskNeedsSomethingToChangeAndARealTask(): void
    {
        $taskId = $this->createTask(['title' => 'Send the deck']);

        try {
            $this->service->queue('user:2', 'update_task', ['task_id' => $taskId], null, 2);
            self::fail('expected a rejection');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('No updatable fields', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown task_id.');
        $this->service->queue('user:2', 'update_task', ['task_id' => 999999, 'title' => 'X'], null, 2);
    }

    public function testCompleteAndReopenTask(): void
    {
        $taskId = $this->createTask(['title' => 'Send the deck']);

        [$proposal] = $this->service->queue('user:2', 'complete_task', ['task_id' => $taskId], null, 2);
        self::assertSame('Complete task "Send the deck"', $proposal['summary']);

        $this->service->apply($proposal, 1);

        $task = (new TaskModel(self::db()))->find($taskId);
        self::assertNotNull($task['completed_at']);
        self::assertSame(1, (int) $task['completed_by'], 'ticked by the approver');

        // Approving a second, stale "complete" is a no-op, not an error.
        $this->service->apply($proposal, 2);
        self::assertSame(1, (int) (new TaskModel(self::db()))->find($taskId)['completed_by']);

        [$reopen] = $this->service->queue(
            'user:2',
            'complete_task',
            ['task_id' => $taskId, 'completed' => false],
            null,
            2,
        );
        self::assertSame('Reopen task "Send the deck"', $reopen['summary']);

        $this->service->apply($reopen, 1);
        self::assertNull((new TaskModel(self::db()))->find($taskId)['completed_at']);
    }

    public function testCompletedMustBeARealBoolean(): void
    {
        $taskId = $this->createTask(['title' => 'Send the deck']);

        // (bool) "false" is true: a reopen request must never be queued as
        // a completion, so anything but a boolean is refused outright.
        foreach (['false', 'true', 0, 1, 'no', null] as $notABoolean) {
            try {
                $this->service->queue(
                    'user:2',
                    'complete_task',
                    ['task_id' => $taskId, 'completed' => $notABoolean],
                    null,
                    2,
                );

                // An explicit null reads as "not given", which defaults to true.
                self::assertNull($notABoolean, 'expected a rejection for ' . json_encode($notABoolean));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('completed must be true or false', $e->getMessage());
            }
        }

        self::assertSame(1, (new ProposalModel(self::db()))->pendingCount(), 'only the null one queued');
        self::assertNull((new TaskModel(self::db()))->find($taskId)['completed_at']);
    }

    public function testUpdateTaskShowsTheLinkItReplaces(): void
    {
        $steve = $this->insertContact('Steve Marsh');
        self::db()->pdo()->exec("INSERT INTO organizations (name) VALUES ('Northfield Labs')");
        $org = (int) self::db()->pdo()->lastInsertId();
        $taskId = $this->createTask(['title' => 'Renew the contract', 'contact_id' => $steve]);

        [$proposal] = $this->service->queue(
            'user:2',
            'update_task',
            ['task_id' => $taskId, 'organization_id' => $org],
            null,
            2,
        );

        // Only what the agent asked for is stored; the rest is re-derived.
        self::assertSame(
            ['task_id' => $taskId, 'organization_id' => $org],
            json_decode((string) $proposal['payload'], true),
        );
        self::assertSame(
            'Update task "Renew the contract": organization_id (replaces its contact link)',
            $proposal['summary'],
        );

        $card = $this->service->describe($proposal);
        self::assertSame(
            [
                ['label' => 'Organization', 'before' => '(empty)', 'after' => 'Northfield Labs', 'changed' => true],
                ['label' => 'Contact', 'before' => 'Steve Marsh', 'after' => '(empty)', 'changed' => true],
            ],
            $card['rows'],
        );
        self::assertSame(
            'A task is about one record, so this also removes its link to Steve Marsh.',
            $card['consequence'],
        );

        // The card told the truth.
        $this->service->apply($proposal, 1);
        $task = (new TaskModel(self::db()))->find($taskId);
        self::assertNull($task['contact_id']);
        self::assertSame($org, (int) $task['organization_id']);

        // And the reverse direction.
        $payload = ['task_id' => $taskId, 'contact_id' => $steve];
        [$back] = $this->service->queue('user:2', 'update_task', $payload, null, 2);
        self::assertStringEndsWith('contact_id (replaces its organization link)', $back['summary']);
        self::assertSame(
            ['Contact' => 'Steve Marsh', 'Organization' => '(empty)'],
            array_column($this->service->describe($back)['rows'], 'after', 'label'),
        );
    }

    public function testUpdateTaskThatReplacesNoLinkSaysNothingExtra(): void
    {
        $steve = $this->insertContact('Steve Marsh');
        $maria = $this->insertContact('Maria Santos');
        $taskId = $this->createTask(['title' => 'Send the deck', 'contact_id' => $steve]);

        // Swapping one contact for another clears nothing else.
        $payload = ['task_id' => $taskId, 'contact_id' => $maria];
        [$swap] = $this->service->queue('user:2', 'update_task', $payload, null, 2);
        self::assertSame('Update task "Send the deck": contact_id', $swap['summary']);
        $card = $this->service->describe($swap);
        self::assertCount(1, $card['rows']);
        self::assertNull($card['consequence']);

        // Clearing a link explicitly is already a row of its own.
        $payload = ['task_id' => $taskId, 'contact_id' => null];
        [$clear] = $this->service->queue('user:2', 'update_task', $payload, null, 2);
        self::assertSame('Update task "Send the deck": contact_id', $clear['summary']);
        self::assertCount(1, $this->service->describe($clear)['rows']);
    }

    public function testApprovingATaskProposalWhoseTargetVanishedFails(): void
    {
        $steve = $this->insertContact('Steve Marsh');
        [$create] = $this->service->queue('user:2', 'create_task', ['title' => 'A', 'contact_id' => $steve], null, 2);

        $taskId = $this->createTask(['title' => 'Doomed']);
        [$complete] = $this->service->queue('user:2', 'complete_task', ['task_id' => $taskId], null, 2);

        self::db()->pdo()->prepare('UPDATE contacts SET deleted_at = NOW(), deleted_by = 1 WHERE id = ?')
            ->execute([$steve]);
        (new TaskModel(self::db()))->delete($taskId);

        try {
            $this->service->apply($create, 1);
            self::fail('a task about a trashed contact must not be created');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('does not exist', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Task no longer exists.');
        $this->service->apply($complete, 1);
    }

    public function testTaskReviewCards(): void
    {
        $steve = $this->insertContact('Steve Marsh');
        [$create] = $this->service->queue(
            'user:2',
            'create_task',
            ['title' => 'Send the deck', 'due_date' => '2026-11-24', 'contact_id' => $steve],
            null,
            2,
        );

        $card = $this->service->describe($create);
        self::assertSame('create_task', $card['kind']);
        self::assertSame('New task: Send the deck', $card['title']);
        self::assertSame(
            ['Due' => '2026-11-24', 'Owner' => 'Dana', 'Contact' => 'Steve Marsh'],
            array_column($card['rows'], 'after', 'label'),
        );
        self::assertStringContainsString('no follow-up date', $card['consequence']);

        $taskId = $this->createTask(['title' => 'Old title', 'owner_id' => 2]);
        [$update] = $this->service->queue(
            'user:2',
            'update_task',
            ['task_id' => $taskId, 'title' => 'New title', 'owner' => 'shared'],
            null,
            2,
        );

        $card = $this->service->describe($update);
        self::assertSame('Update task: Old title', $card['title']);
        self::assertSame(
            [
                ['label' => 'Task', 'before' => 'Old title', 'after' => 'New title', 'changed' => true],
                ['label' => 'Owner', 'before' => 'Dana', 'after' => 'Shared', 'changed' => true],
            ],
            $card['rows'],
        );

        [$complete] = $this->service->queue('user:2', 'complete_task', ['task_id' => $taskId], null, 2);
        self::assertSame('Complete task: Old title', $this->service->describe($complete)['title']);

        (new TaskModel(self::db()))->delete($taskId);
        self::assertSame('missing_target', $this->service->describe($update)['kind']);
        self::assertSame('missing_target', $this->service->describe($complete)['kind']);
    }

    public function testApproveAppliesOnceAndReportsWhatHappened(): void
    {
        [$proposal] = $this->service->queue('write', 'create_contact', ['name' => 'Once Only']);
        $id = (int) $proposal['id'];

        self::assertSame(['outcome' => 'approved', 'error' => null], $this->service->approve($id, 1));
        self::assertSame(
            ['outcome' => 'already_decided', 'error' => null],
            $this->service->approve($id, 2),
            'a second approval (another tab, the Slack button) must not apply it again',
        );
        self::assertCount(1, (new ContactModel(self::db()))->search(query: 'Once Only'));
        self::assertSame(
            ['outcome' => 'already_decided', 'error' => null],
            $this->service->approve(999999, 1),
        );

        $row = (new ProposalModel(self::db()))->find($id);
        self::assertSame('approved', $row['status']);
        self::assertSame(1, (int) $row['reviewed_by'], 'the first approver stays on record');
    }

    public function testAFailedApprovalIsRejectedWithItsReason(): void
    {
        $contact = $this->insertContact('Soon Gone');
        [$proposal] = $this->service->queue('write', 'change_tags', ['contact_id' => $contact, 'add' => ['vip']]);
        $this->trash($contact);

        $result = $this->service->approve((int) $proposal['id'], 1);

        self::assertSame('failed', $result['outcome']);
        self::assertNotSame('', (string) $result['error']);

        $row = (new ProposalModel(self::db()))->find((int) $proposal['id']);
        self::assertSame('rejected', $row['status']);
        self::assertSame($result['error'], $row['error']);
    }

    public function testApproveAllWorksOldestFirstSoTheLaterProposalWins(): void
    {
        $contact = $this->insertContact('Ordered Olive');

        // Queued in this order; pending() must hand them back in it.
        $this->service->queue('write', 'update_contact', ['contact_id' => $contact, 'title' => 'First title']);
        $this->service->queue('write', 'change_tags', ['contact_id' => $contact, 'add' => ['alpha', 'beta']]);
        $this->service->queue('write', 'update_contact', ['contact_id' => $contact, 'title' => 'Final title']);
        [$last] = $this->service->queue('write', 'change_tags', ['contact_id' => $contact, 'remove' => ['alpha']]);

        $result = $this->service->approveAllPending(2, (int) $last['id']);

        self::assertSame(['approved' => 4, 'skipped' => 0, 'failed' => []], $result);
        self::assertSame(0, (new ProposalModel(self::db()))->pendingCount());

        $row = (new ContactModel(self::db()))->find($contact);
        self::assertSame('Final title', $row['title'], 'newest-first would have left "First title"');
        self::assertSame(2, (int) $row['updated_by'], 'recorded under the approver');
        self::assertSame(
            ['beta'],
            (new TagModel(self::db()))->namesForContact($contact),
            'the add was applied before the remove',
        );
    }

    public function testApproveAllLeavesWhatTheReviewerNeverSaw(): void
    {
        [$shown] = $this->service->queue('write', 'create_contact', ['name' => 'Seen Sam']);
        $this->service->queue('write', 'create_contact', ['name' => 'Arrived Later']);

        $result = $this->service->approveAllPending(1, (int) $shown['id']);

        self::assertSame(1, $result['approved']);
        self::assertSame(1, (new ProposalModel(self::db()))->pendingCount(), 'the newer proposal still waits');
        self::assertCount(0, (new ContactModel(self::db()))->search(query: 'Arrived Later'));
    }

    public function testApproveAllCarriesOnPastAFailure(): void
    {
        $doomed = $this->insertContact('Doomed Dee');
        $fine = $this->insertContact('Fine Fay');

        [$first] = $this->service->queue('write', 'change_tags', ['contact_id' => $doomed, 'add' => ['vip']]);
        [$second] = $this->service->queue('write', 'change_tags', ['contact_id' => $fine, 'add' => ['vip']]);
        $this->trash($doomed);

        $result = $this->service->approveAllPending(1, (int) $second['id']);

        self::assertSame(1, $result['approved']);
        self::assertCount(1, $result['failed']);
        self::assertSame((int) $first['id'], $result['failed'][0]['id']);
        self::assertSame('Tags for Doomed Dee: +vip', $result['failed'][0]['summary']);
        self::assertNotSame('', $result['failed'][0]['error']);

        self::assertSame(['vip'], (new TagModel(self::db()))->namesForContact($fine));
        self::assertSame('rejected', (new ProposalModel(self::db()))->find((int) $first['id'])['status']);
        self::assertSame(0, (new ProposalModel(self::db()))->pendingCount());
    }

    public function testBreakdownCountsByKindMostNumerousFirst(): void
    {
        self::assertSame('', ProposalService::breakdown([]));
        self::assertSame(
            '3 tag changes, 1 contact update, 1 new task, 1 other change',
            ProposalService::breakdown([
                ['action' => 'update_contact'],
                ['action' => 'change_tags'],
                ['action' => 'change_tags'],
                ['action' => 'create_task'],
                ['action' => 'change_tags'],
                ['action' => 'something_new'],
            ]),
        );
    }

    private function trash(int $contactId): void
    {
        self::db()->pdo()->prepare('UPDATE contacts SET deleted_at = NOW(), deleted_by = 1 WHERE id = ?')
            ->execute([$contactId]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createTask(array $fields): int
    {
        return (new TaskModel(self::db()))->create($fields + [
            'notes' => null,
            'due_date' => null,
            'owner_id' => null,
            'contact_id' => null,
            'organization_id' => null,
        ], 1);
    }

    private function insertContact(string $name): int
    {
        $stmt = self::db()->pdo()->prepare(
            "INSERT INTO contacts (name, relationship_status, created_by, updated_by)
             VALUES (:name, 'Conversation Started', 1, 1)"
        );
        $stmt->execute(['name' => $name]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function findContactByName(string $name): array
    {
        $rows = (new ContactModel(self::db()))->search(query: $name);
        self::assertCount(1, $rows);

        return $rows[0];
    }
}
