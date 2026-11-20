<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Models\TaskModel;
use App\Services\TaskService;
use App\Support\Config;
use DateTimeZone;
use Tests\Database\DatabaseTestCase;

/**
 * The task rules every surface shares (web forms, Slack, approved agent
 * proposals). Seeded users: 1 = Colin, 2 = Dana (migration 0002).
 */
final class TaskServiceTest extends DatabaseTestCase
{
    /**
     * @param list<string> $allowedEmails
     */
    private function service(array $allowedEmails = []): TaskService
    {
        return new TaskService(
            new Config(
                appEnv: 'test',
                appTimezone: new DateTimeZone('America/New_York'),
                dbHost: '',
                dbName: '',
                dbUser: '',
                dbPassword: '',
                allowedEmails: $allowedEmails,
            ),
            self::db(),
        );
    }

    public function testATitleIsAllATaskNeeds(): void
    {
        [$clean, $errors] = $this->service()->validate(['title' => "  Renew   the PO box \n"]);

        self::assertSame([], $errors);
        self::assertSame([
            'title' => 'Renew the PO box',
            'notes' => null,
            'due_date' => null,
            'owner_id' => null,
            'contact_id' => null,
            'organization_id' => null,
        ], $clean);
    }

    public function testTitleIsRequiredAndBounded(): void
    {
        [, $errors] = $this->service()->validate(['title' => '   ']);
        self::assertSame('A task needs a title.', $errors['title']);

        [, $errors] = $this->service()->validate(['title' => str_repeat('x', 256)]);
        self::assertArrayHasKey('title', $errors);

        [, $errors] = $this->service()->validate(['title' => str_repeat('x', 255)]);
        self::assertSame([], $errors);
    }

    public function testDueDateMustBeARealDate(): void
    {
        foreach (['2026-02-30', 'friday', '11/24/2026', '2026-1-5'] as $bad) {
            [, $errors] = $this->service()->validate(['title' => 'T', 'due_date' => $bad]);
            self::assertArrayHasKey('due_date', $errors, $bad);
        }

        [$clean, $errors] = $this->service()->validate(['title' => 'T', 'due_date' => '2026-11-24']);
        self::assertSame([], $errors);
        self::assertSame('2026-11-24', $clean['due_date']);

        [$clean] = $this->service()->validate(['title' => 'T', 'due_date' => '']);
        self::assertNull($clean['due_date'], 'an empty form field means no date');
    }

    public function testOwnerMustBeSomeoneWhoCanSignIn(): void
    {
        // Only Dana is on the allowlist: Colin's (seeded) row is a
        // retired identity as far as assignment goes.
        $service = $this->service(['dana@example.com']);

        [, $errors] = $service->validate(['title' => 'T', 'owner_id' => 1]);
        self::assertSame('Pick a valid owner.', $errors['owner_id']);

        [$clean, $errors] = $service->validate(['title' => 'T', 'owner_id' => '2']);
        self::assertSame([], $errors);
        self::assertSame(2, $clean['owner_id']);

        [$clean, $errors] = $service->validate(['title' => 'T', 'owner_id' => '']);
        self::assertSame([], $errors);
        self::assertNull($clean['owner_id'], 'empty means shared');

        [, $errors] = $service->validate(['title' => 'T', 'owner_id' => 'dana']);
        self::assertArrayHasKey('owner_id', $errors, 'words are resolved before validation, not here');
    }

    public function testLinksMustExistAndTrashedContactsDoNotCount(): void
    {
        $steve = $this->insertContact('Steve');
        $trashed = $this->insertContact('Trashed Tom');
        self::db()->pdo()->prepare('UPDATE contacts SET deleted_at = NOW(), deleted_by = 1 WHERE id = ?')
            ->execute([$trashed]);

        [$clean, $errors] = $this->service()->validate(['title' => 'T', 'contact_id' => (string) $steve]);
        self::assertSame([], $errors);
        self::assertSame($steve, $clean['contact_id']);

        [, $errors] = $this->service()->validate(['title' => 'T', 'contact_id' => $trashed]);
        self::assertArrayHasKey('contact_id', $errors);

        [, $errors] = $this->service()->validate(['title' => 'T', 'contact_id' => 999999]);
        self::assertArrayHasKey('contact_id', $errors);

        [, $errors] = $this->service()->validate(['title' => 'T', 'organization_id' => 999999]);
        self::assertArrayHasKey('organization_id', $errors);
    }

    public function testAskingForBothLinksAtOnceIsAnError(): void
    {
        $org = $this->insertOrganization('Northfield Labs');
        $steve = $this->insertContact('Steve');

        [, $errors] = $this->service()->validate([
            'title' => 'T',
            'contact_id' => $steve,
            'organization_id' => $org,
        ]);

        self::assertStringContainsString('not both', $errors['contact_id']);
    }

    public function testEditingKeepsWhatWasNotSent(): void
    {
        $steve = $this->insertContact('Steve');
        $service = $this->service();
        [$clean] = $service->validate([
            'title' => 'Send the deck',
            'notes' => 'Short version',
            'due_date' => '2026-11-24',
            'owner_id' => 2,
            'contact_id' => $steve,
        ]);
        $id = $service->create($clean, 1);
        $task = (new TaskModel(self::db()))->find($id);

        [$clean, $errors] = $service->validate(['due_date' => '2026-12-01'], $task);

        self::assertSame([], $errors);
        self::assertSame('Send the deck', $clean['title']);
        self::assertSame('Short version', $clean['notes']);
        self::assertSame('2026-12-01', $clean['due_date']);
        self::assertSame(2, $clean['owner_id']);
        self::assertSame($steve, $clean['contact_id']);

        // An explicit null clears; an absent key keeps.
        [$clean] = $service->validate(['due_date' => null, 'owner_id' => null], $task);
        self::assertNull($clean['due_date']);
        self::assertNull($clean['owner_id']);
        self::assertSame($steve, $clean['contact_id']);
    }

    public function testSettingOneLinkOnEditReplacesTheOther(): void
    {
        $org = $this->insertOrganization('Northfield Labs');
        $steve = $this->insertContact('Steve');
        $service = $this->service();
        [$clean] = $service->validate(['title' => 'Renew the contract', 'organization_id' => $org]);
        $task = (new TaskModel(self::db()))->find($service->create($clean, 1));

        [$clean, $errors] = $service->validate(['contact_id' => $steve], $task);

        self::assertSame([], $errors);
        self::assertSame($steve, $clean['contact_id']);
        self::assertNull($clean['organization_id'], 'linking a contact replaces the organization link');

        $service->update((int) $task['id'], $clean, 2);
        $task = (new TaskModel(self::db()))->find((int) $task['id']);

        [$clean, $errors] = $service->validate(['organization_id' => $org], $task);
        self::assertSame([], $errors);
        self::assertNull($clean['contact_id']);
        self::assertSame($org, $clean['organization_id']);
    }

    public function testATaskAboutAContactSinceTrashedCanStillBeEdited(): void
    {
        $steve = $this->insertContact('Steve');
        $service = $this->service();
        [$clean] = $service->validate(['title' => 'Send the deck', 'contact_id' => $steve]);
        $task = (new TaskModel(self::db()))->find($service->create($clean, 1));

        self::db()->pdo()->prepare('UPDATE contacts SET deleted_at = NOW(), deleted_by = 1 WHERE id = ?')
            ->execute([$steve]);

        // The link is unchanged, so it is not re-checked: an approval that
        // only moves the due date must not fail on an untouched field.
        [, $errors] = $service->validate(['due_date' => '2026-12-01'], $task);
        self::assertSame([], $errors);
    }

    public function testCompleteAndReopenRecordWhoDidIt(): void
    {
        $service = $this->service();
        [$clean] = $service->validate(['title' => 'Tick me', 'owner_id' => 1]);
        $id = $service->create($clean, 1);

        self::assertTrue($service->complete($id, 2));
        self::assertFalse($service->complete($id, 1), 'already done');

        $task = (new TaskModel(self::db()))->find($id);
        self::assertSame(2, (int) $task['completed_by']);

        self::assertTrue($service->reopen($id, 1));
        self::assertNull((new TaskModel(self::db()))->find($id)['completed_at']);
    }

    public function testTasksNeverMoveAFollowUpDate(): void
    {
        $steve = $this->insertContact('Steve', '2026-11-25');
        $service = $this->service();
        [$clean] = $service->validate(['title' => 'Send the deck', 'contact_id' => $steve, 'due_date' => '2026-11-21']);
        $id = $service->create($clean, 1);
        $service->complete($id, 1);

        $contact = self::db()->pdo()->query('SELECT next_touch_date, last_touch_date FROM contacts')->fetch();
        self::assertSame('2026-11-25', $contact['next_touch_date']);
        self::assertNull($contact['last_touch_date']);
    }

    private function insertContact(string $name, ?string $next = null): int
    {
        $stmt = self::db()->pdo()->prepare(
            "INSERT INTO contacts (name, relationship_status, next_touch_date, created_by, updated_by)
             VALUES (:name, 'Conversation Started', :next, 1, 1)"
        );
        $stmt->execute(['name' => $name, 'next' => $next]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    private function insertOrganization(string $name): int
    {
        self::db()->pdo()->prepare('INSERT INTO organizations (name) VALUES (:name)')->execute(['name' => $name]);

        return (int) self::db()->pdo()->lastInsertId();
    }
}
