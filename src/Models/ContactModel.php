<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

final class ContactModel
{
    public const RELATIONSHIP_TYPES = [
        'prospect',
        'client',
        'ecosystem',
        'media',
        'investor',
        'mentor_advisor',
    ];

    /**
     * Sortable columns for the contacts list — URL sort key => SQL
     * expression. Only these whitelisted expressions ever reach ORDER BY.
     * "stage" sorts by pipeline position (stages.sort_order), not the
     * status name alphabetically.
     */
    public const SORTS = [
        'name' => 'c.name',
        'organization' => 'o.name',
        'stage' => 'st.sort_order',
        'last_touch' => 'c.last_touch_date',
        'next_touch' => 'c.next_touch_date',
    ];

    private const BASE_SELECT = '
        SELECT c.*,
               o.name AS organization_name,
               cu.name AS created_by_name,
               uu.name AS updated_by_name
        FROM contacts c
        LEFT JOIN organizations o ON c.organization_id = o.id
        LEFT JOIN stages st ON c.relationship_status = st.name
        JOIN users cu ON c.created_by = cu.id
        JOIN users uu ON c.updated_by = uu.id
    ';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(
        string $query = '',
        string $status = '',
        ?int $organizationId = null,
        string $sort = 'name',
        string $dir = 'asc',
    ): array {
        $sql = self::BASE_SELECT;
        $where = [];
        $params = [];

        if ($query !== '') {
            // Two placeholders for the same value: native prepares (emulation
            // off) reject reusing one named parameter twice in a statement.
            $where[] = '(c.name LIKE :query_name OR o.name LIKE :query_org)';
            $params['query_name'] = '%' . $query . '%';
            $params['query_org'] = '%' . $query . '%';
        }

        if ($status !== '') {
            $where[] = 'c.relationship_status = :status';
            $params['status'] = $status;
        }

        if ($organizationId !== null) {
            $where[] = 'c.organization_id = :organization_id';
            $params['organization_id'] = $organizationId;
        }

        $where[] = 'c.deleted_at IS NULL';

        $sql .= ' WHERE ' . implode(' AND ', $where);

        // Whitelist only — unknown keys fall back to name, and direction is
        // normalized, so nothing user-supplied is ever interpolated.
        $sortExpr = self::SORTS[$sort] ?? self::SORTS['name'];
        $sqlDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        // "IS NULL" first keeps empty cells at the bottom in either direction
        // (MySQL otherwise sorts NULLs to the top ascending).
        $sql .= sprintf(' ORDER BY %s IS NULL, %s %s, c.name', $sortExpr, $sortExpr, $sqlDir);

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Contacts due within the horizon (or overdue) — the Monday dashboard
     * view from docs/SCHEMA.md. $today is "today" in APP_TIMEZONE, computed
     * by the caller, so evening UTC skew doesn't shift the list.
     *
     * @return list<array<string, mixed>>
     */
    public function dueBy(string $today, int $horizonDays = 7): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::BASE_SELECT . '
            WHERE c.next_touch_date IS NOT NULL
              AND c.deleted_at IS NULL
              AND c.next_touch_date <= DATE_ADD(:today, INTERVAL :horizon DAY)
            ORDER BY c.next_touch_date ASC, c.name'
        );
        $stmt->execute(['today' => $today, 'horizon' => $horizonDays]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        // Trashed contacts are invisible here too — every existing screen
        // (show/edit/log activity) 404s on them until restored.
        $stmt = $this->db->pdo()->prepare(
            self::BASE_SELECT . ' WHERE c.id = :id AND c.deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Snooze: push the next touch to a new date without logging an activity
     * or moving last_touch. The updated_by trail still records who did it.
     */
    public function snooze(int $id, string $nextTouchDate, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE contacts
             SET next_touch_date = :next_touch_date, updated_by = :updated_by
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'next_touch_date' => $nextTouchDate,
            'updated_by' => $userId,
            'id' => $id,
        ]);
    }

    /**
     * Move to trash. Everything is kept — activities, dates, attribution —
     * the contact just disappears from lists and reminders until restored.
     */
    public function trash(int $id, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE contacts
             SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['deleted_by' => $userId, 'id' => $id]);
    }

    public function restore(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE contacts
             SET deleted_at = NULL, deleted_by = NULL
             WHERE id = :id AND deleted_at IS NOT NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trashed(): array
    {
        return $this->db->pdo()->query(
            'SELECT c.*, o.name AS organization_name, du.name AS deleted_by_name
             FROM contacts c
             LEFT JOIN organizations o ON c.organization_id = o.id
             LEFT JOIN users du ON c.deleted_by = du.id
             WHERE c.deleted_at IS NOT NULL
             ORDER BY c.deleted_at DESC'
        )->fetchAll();
    }

    public function trashedCount(): int
    {
        return (int) $this->db->pdo()
            ->query('SELECT COUNT(*) FROM contacts WHERE deleted_at IS NOT NULL')
            ->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data validated field values
     */
    public function create(array $data, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO contacts (
                name, organization_id, title, relationship_status, relationship_type,
                email, phone, linkedin_url, cadence_days_override, human_detail,
                next_touch_date, created_by, updated_by
            ) VALUES (
                :name, :organization_id, :title, :relationship_status, :relationship_type,
                :email, :phone, :linkedin_url, :cadence_days_override, :human_detail,
                :next_touch_date, :created_by, :updated_by
            )'
        );

        $stmt->execute($this->fieldParams($data) + [
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data validated field values
     */
    public function update(int $id, array $data, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE contacts SET
                name = :name,
                organization_id = :organization_id,
                title = :title,
                relationship_status = :relationship_status,
                relationship_type = :relationship_type,
                email = :email,
                phone = :phone,
                linkedin_url = :linkedin_url,
                cadence_days_override = :cadence_days_override,
                human_detail = :human_detail,
                next_touch_date = :next_touch_date,
                updated_by = :updated_by
            WHERE id = :id'
        );

        $stmt->execute($this->fieldParams($data) + [
            'updated_by' => $userId,
            'id' => $id,
        ]);
    }

    /**
     * Called after an activity is logged: advance last touch and store the
     * recalculated next touch.
     */
    public function recordTouch(int $id, string $lastTouchDate, ?string $nextTouchDate, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE contacts SET
                last_touch_date = :last_touch_date,
                next_touch_date = :next_touch_date,
                updated_by = :updated_by
            WHERE id = :id'
        );

        $stmt->execute([
            'last_touch_date' => $lastTouchDate,
            'next_touch_date' => $nextTouchDate,
            'updated_by' => $userId,
            'id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function fieldParams(array $data): array
    {
        return [
            'name' => $data['name'],
            'organization_id' => $data['organization_id'],
            'title' => $data['title'],
            'relationship_status' => $data['relationship_status'],
            'relationship_type' => $data['relationship_type'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'linkedin_url' => $data['linkedin_url'],
            'cadence_days_override' => $data['cadence_days_override'],
            'human_detail' => $data['human_detail'],
            'next_touch_date' => $data['next_touch_date'],
        ];
    }
}
