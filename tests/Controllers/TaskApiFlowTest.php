<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\Api\CrmApiController;
use App\Models\UserModel;
use App\Support\Auth;
use App\Support\Config;
use DateTimeZone;
use Tests\Database\DatabaseTestCase;

/**
 * GET /api/v1/tasks end to end: a bearer token and a query string go into
 * the real controller, and the test reads the status, the error code and
 * the rows that came back. Seeded users: 1 and 2 (migration 0002).
 */
final class TaskApiFlowTest extends DatabaseTestCase
{
    private const TOKEN = 'test-read-token';

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_GET = [];
        http_response_code(200);

        parent::tearDown();
    }

    public function testFiltersByContactAndOrganizationIds(): void
    {
        $contact = $this->insertContact('Maria Santos');
        $this->insertTask('About Maria', $contact);
        $this->insertTask('About nobody', null);

        [$status, $body] = $this->get(['contact_id' => (string) $contact]);

        self::assertSame(200, $status);
        self::assertSame(['About Maria'], array_column($body['data'], 'title'));

        // An id nobody has is a valid filter that matches nothing.
        [$status, $body] = $this->get(['contact_id' => '999999']);
        self::assertSame(200, $status);
        self::assertSame([], $body['data']);
    }

    public function testANonIntegerIdIsAnErrorNotAnEmptyList(): void
    {
        $this->insertTask('About nobody', null);

        foreach (['contact_id', 'organization_id'] as $filter) {
            foreach (['abc', '12abc', '1.5', '-1', '0'] as $bad) {
                [$status, $body] = $this->get([$filter => $bad]);

                self::assertSame(400, $status, "$filter=$bad");
                self::assertSame('invalid_filter', $body['error']['code'], "$filter=$bad");
                self::assertSame($filter . ' must be a whole-number id.', $body['error']['message']);
            }
        }

        // Sent but empty is the same as not sent.
        [$status, $body] = $this->get(['contact_id' => '', 'organization_id' => '']);
        self::assertSame(200, $status);
        self::assertCount(1, $body['data']);
    }

    public function testABadOwnerIsInvalidOwnerLikeTheContactsEndpoint(): void
    {
        // Unresolvable words, an unknown email, and "me" on an anonymous token.
        foreach (['nobody', 'ghost@example.com', 'me'] as $owner) {
            [$status, $body] = $this->get(['owner' => $owner]);

            self::assertSame(400, $status, $owner);
            self::assertSame('invalid_owner', $body['error']['code'], $owner);
        }

        // The contacts endpoint, for comparison: same input, same code.
        [$status, $body] = $this->get(['owner' => 'nobody'], 'contactsIndex');
        self::assertSame(400, $status);
        self::assertSame('invalid_owner', $body['error']['code']);
    }

    public function testOtherBadFiltersStayInvalidFilter(): void
    {
        foreach ([['status' => 'pending'], ['due_before' => '2026-02-30'], ['due_before' => 'friday']] as $query) {
            [$status, $body] = $this->get($query);

            self::assertSame(400, $status, (string) json_encode($query));
            self::assertSame('invalid_filter', $body['error']['code'], (string) json_encode($query));
        }

        // A good owner with a bad filter is still about the filter.
        [, $body] = $this->get(['owner' => 'shared', 'contact_id' => 'abc']);
        self::assertSame('invalid_filter', $body['error']['code']);

        [$status, $body] = $this->get(['owner' => 'shared', 'due_before' => '2026-12-01', 'status' => 'all']);
        self::assertSame(200, $status);
        self::assertSame([], $body['data']);
    }

    /**
     * @param array<string, string> $query
     * @return array{0: int, 1: array<string, mixed>} [HTTP status, decoded body]
     */
    private function get(array $query, string $endpoint = 'tasksIndex'): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
        $_GET = $query;

        $controller = new CrmApiController(
            new Config(
                appEnv: 'test',
                appTimezone: new DateTimeZone('America/New_York'),
                dbHost: '',
                dbName: '',
                dbUser: '',
                dbPassword: '',
                apiTokenRead: self::TOKEN,
            ),
            self::db(),
            new Auth(new UserModel(self::db())),
        );

        $body = (array) json_decode($controller->{$endpoint}(), true);

        return [(int) http_response_code(), $body];
    }

    private function insertTask(string $title, ?int $contactId): int
    {
        self::db()->pdo()->prepare(
            'INSERT INTO tasks (title, contact_id, created_by, updated_by) VALUES (?, ?, 1, 1)'
        )->execute([$title, $contactId]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    private function insertContact(string $name): int
    {
        self::db()->pdo()->prepare(
            "INSERT INTO contacts (name, relationship_status, created_by, updated_by)
             VALUES (?, 'Conversation Started', 1, 1)"
        )->execute([$name]);

        return (int) self::db()->pdo()->lastInsertId();
    }
}
