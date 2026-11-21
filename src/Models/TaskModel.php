<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\OwnerLens;

/**
 * Tasks: one-off to-dos with a checkbox (migration 0024). Deliberately
 * separate from the follow-up engine: nothing in here reads or writes a
 * contact's touch dates.
 *
 * Every read, rows (select()) and the open count alike, is built from
 * source(), which is the single place the two standing rules are applied:
 *  - a task whose contact is in the trash is invisible, and comes back
 *    when the contact is restored;
 *  - owner scoping is built by OwnerLens, the same definition contacts
 *    use (owner_id NULL = shared).
 * A future workspace boundary belongs in that same method.
 */
final class TaskModel
{
    private const COLUMNS = '
        t.*,
        c.name AS contact_name,
        COALESCE(co.id, o.id) AS linked_organization_id,
        COALESCE(co.name, o.name) AS organization_name,
        ou.name AS owner_name,
        du.name AS completed_by_name,
        cu.name AS created_by_name,
        uu.name AS updated_by_name
    ';

    private const FROM = '
        FROM tasks t
        LEFT JOIN contacts c ON t.contact_id = c.id
        LEFT JOIN organizations co ON c.organization_id = co.id
        LEFT JOIN organizations o ON t.organization_id = o.id
        LEFT JOIN users ou ON t.owner_id = ou.id
        LEFT JOIN users du ON t.completed_by = du.id
        JOIN users cu ON t.created_by = cu.id
        JOIN users uu ON t.updated_by = uu.id
    ';

    /** Tasks of a trashed contact are hidden with it. */
    private const VISIBLE = '(t.contact_id IS NULL OR c.deleted_at IS NULL)';

    /** Dated tasks first, soonest first; undated ones after them. */
    private const OPEN_ORDER = 't.due_date IS NULL, t.due_date ASC, t.id ASC';

    private const DONE_ORDER = 't.completed_at DESC, t.id DESC';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $rows = $this->select(['t.id = :id'], ['id' => $id]);

        return $rows[0] ?? null;
    }

    /**
     * Open tasks. $forUserId applies the Mine lens (that user's tasks plus
     * shared ones); null is the combined everyone view. $dueBy keeps tasks
     * due on or before that date (overdue included); $includeUndated says
     * whether tasks with no due date pass that cut.
     *
     * @return list<array<string, mixed>>
     */
    public function open(?int $forUserId = null, ?string $dueBy = null, bool $includeUndated = true): array
    {
        $where = ['t.completed_at IS NULL'];
        $params = [];

        if ($forUserId !== null) {
            $where[] = OwnerLens::mine('t.owner_id', 'for_user');
            $params['for_user'] = $forUserId;
        }

        if ($dueBy !== null) {
            $where[] = $includeUndated
                ? '(t.due_date <= :due_by OR t.due_date IS NULL)'
                : 't.due_date <= :due_by';
            $params['due_by'] = $dueBy;
        }

        return $this->select($where, $params, self::OPEN_ORDER);
    }

    /**
     * Completed tasks, most recently ticked first. $since (a UTC DATETIME)
     * keeps only those completed at or after it.
     *
     * @return list<array<string, mixed>>
     */
    public function completed(?int $forUserId = null, ?string $since = null, int $limit = 50): array
    {
        $where = ['t.completed_at IS NOT NULL'];
        $params = [];

        if ($forUserId !== null) {
            $where[] = OwnerLens::mine('t.owner_id', 'for_user');
            $params['for_user'] = $forUserId;
        }

        if ($since !== null) {
            $where[] = 't.completed_at >= :since';
            $params['since'] = $since;
        }

        return $this->select($where, $params, self::DONE_ORDER, $limit);
    }

    /**
     * A contact's tasks. Not lensed: a record's own page shows everything
     * about it, whoever owns the task.
     *
     * @return list<array<string, mixed>>
     */
    public function openForContact(int $contactId): array
    {
        return $this->select(
            ['t.completed_at IS NULL', 't.contact_id = :contact_id'],
            ['contact_id' => $contactId],
            self::OPEN_ORDER,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function completedForContact(int $contactId, int $limit = 5): array
    {
        return $this->select(
            ['t.completed_at IS NOT NULL', 't.contact_id = :contact_id'],
            ['contact_id' => $contactId],
            self::DONE_ORDER,
            $limit,
        );
    }

    /**
     * An organization's tasks: linked to it directly, or to one of its
     * contacts.
     *
     * @return list<array<string, mixed>>
     */
    public function openForOrganization(int $organizationId): array
    {
        return $this->select(
            [
                't.completed_at IS NULL',
                '(t.organization_id = :organization_id OR c.organization_id = :contact_organization_id)',
            ],
            ['organization_id' => $organizationId, 'contact_organization_id' => $organizationId],
            self::OPEN_ORDER,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function completedForOrganization(int $organizationId, int $limit = 5): array
    {
        return $this->select(
            [
                't.completed_at IS NOT NULL',
                '(t.organization_id = :organization_id OR c.organization_id = :contact_organization_id)',
            ],
            ['organization_id' => $organizationId, 'contact_organization_id' => $organizationId],
            self::DONE_ORDER,
            $limit,
        );
    }

    /**
     * Cursor-paginated list for the agent API, in id order so pagination is
     * stable. $status is 'open', 'done' or 'all'. $owner is the exact
     * filter ('' = anyone, 'shared', or a numeric user id), never the lens:
     * an agent searches the whole workspace unless it chooses otherwise.
     * $dueBefore keeps tasks due on or before that date.
     *
     * @return list<array<string, mixed>>
     */
    public function apiList(
        string $status,
        string $owner,
        ?int $contactId,
        ?int $organizationId,
        string $dueBefore,
        int $afterId,
        int $limit,
    ): array {
        $where = ['t.id > :after_id'];
        $params = ['after_id' => $afterId];

        if ($status === 'open') {
            $where[] = 't.completed_at IS NULL';
        } elseif ($status === 'done') {
            $where[] = 't.completed_at IS NOT NULL';
        }

        $ownerFilter = OwnerLens::filter('t.owner_id', $owner);

        if ($ownerFilter !== null) {
            $where[] = $ownerFilter[0];
            $params += $ownerFilter[1];
        }

        if ($contactId !== null) {
            $where[] = 't.contact_id = :contact_id';
            $params['contact_id'] = $contactId;
        }

        if ($organizationId !== null) {
            $where[] = '(t.organization_id = :organization_id OR c.organization_id = :contact_organization_id)';
            $params['organization_id'] = $organizationId;
            $params['contact_organization_id'] = $organizationId;
        }

        if ($dueBefore !== '') {
            $where[] = 't.due_date <= :due_before';
            $params['due_before'] = $dueBefore;
        }

        return $this->select($where, $params, 't.id', $limit);
    }

    /**
     * How many open tasks the lens holds in total (the "and N more later"
     * line under a shortened list). Counted by the database over the same
     * source() as open(), so the two always agree, rather than by fetching
     * and hydrating every open task just to count the rows.
     */
    public function countOpen(?int $forUserId = null): int
    {
        $where = ['t.completed_at IS NULL'];
        $params = [];

        if ($forUserId !== null) {
            $where[] = OwnerLens::mine('t.owner_id', 'for_user');
            $params['for_user'] = $forUserId;
        }

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*)' . self::source($where));
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data validated field values (TaskService)
     */
    public function create(array $data, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tasks (
                title, notes, due_date, owner_id, contact_id, organization_id,
                created_by, updated_by
            ) VALUES (
                :title, :notes, :due_date, :owner_id, :contact_id, :organization_id,
                :created_by, :updated_by
            )'
        );
        $stmt->execute($this->fieldParams($data) + [
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data validated field values (TaskService)
     */
    public function update(int $id, array $data, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tasks SET
                title = :title,
                notes = :notes,
                due_date = :due_date,
                owner_id = :owner_id,
                contact_id = :contact_id,
                organization_id = :organization_id,
                updated_by = :updated_by
            WHERE id = :id'
        );
        $stmt->execute($this->fieldParams($data) + [
            'updated_by' => $userId,
            'id' => $id,
        ]);
    }

    /**
     * Tick or untick a task. A compare-and-set: only a row whose state
     * actually differs is written, so a double tap (two Slack buttons, two
     * browser tabs) changes nothing the second time and the first person
     * stays recorded as completed_by. Returns whether THIS call changed it.
     */
    public function setCompleted(int $id, bool $completed, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            $completed
                ? 'UPDATE tasks
                   SET completed_at = CURRENT_TIMESTAMP, completed_by = :completed_by,
                       updated_by = :updated_by
                   WHERE id = :id AND completed_at IS NULL'
                : 'UPDATE tasks
                   SET completed_at = NULL, completed_by = NULL, updated_by = :updated_by
                   WHERE id = :id AND completed_at IS NOT NULL'
        );
        $stmt->execute(
            ['updated_by' => $userId, 'id' => $id] + ($completed ? ['completed_by' => $userId] : []),
        );

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The one row read path. $where fragments are hardcoded by the methods
     * above; values only ever travel as bound parameters.
     *
     * @param list<string> $where
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function select(array $where, array $params, string $order = 't.id', ?int $limit = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS
            . self::source($where)
            . ' ORDER BY ' . $order
            . ($limit === null ? '' : ' LIMIT ' . max(1, $limit));

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * FROM and WHERE for every task read: the joins, the caller's
     * conditions, and the trashed-contact rule appended to all of them.
     *
     * @param list<string> $where
     */
    private static function source(array $where): string
    {
        $where[] = self::VISIBLE;

        return self::FROM . ' WHERE ' . implode(' AND ', $where);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function fieldParams(array $data): array
    {
        return [
            'title' => $data['title'],
            'notes' => $data['notes'],
            'due_date' => $data['due_date'],
            'owner_id' => $data['owner_id'],
            'contact_id' => $data['contact_id'],
            'organization_id' => $data['organization_id'],
        ];
    }
}
