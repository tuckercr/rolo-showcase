<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\OwnerLens;

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
               (SELECT IF(a.follow_up_needed = 1, a.follow_up_note, NULL)
                FROM activities a
                WHERE a.contact_id = c.id AND a.activity_type != \'snoozed\'
                ORDER BY a.activity_date DESC, a.id DESC
                LIMIT 1) AS follow_up_note,
               o.name AS organization_name,
               ou.name AS owner_name,
               cu.name AS created_by_name,
               uu.name AS updated_by_name
        FROM contacts c
        LEFT JOIN organizations o ON c.organization_id = o.id
        LEFT JOIN stages st ON c.relationship_status = st.name
        LEFT JOIN users ou ON c.owner_id = ou.id
        JOIN users cu ON c.created_by = cu.id
        JOIN users uu ON c.updated_by = uu.id
    ';

    /**
     * WHERE fragment for a user's personalized follow-up lens: their own
     * contacts plus shared (unowned) ones. NULL owner means shared, see
     * migration 0021. The rule itself lives in OwnerLens, shared with
     * every other owned entity.
     */
    private static function ownerLens(string $placeholder): string
    {
        return OwnerLens::mine('c.owner_id', $placeholder);
    }

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
        string $tag = '',
        string $owner = '',
    ): array {
        $sql = self::BASE_SELECT;
        $where = [];
        $params = [];

        // Owner filter: '' = anyone, 'shared' = unowned contacts only,
        // a numeric string = that user's owned contacts. The controller
        // validates the value; anything else falls through as "anyone".
        $ownerFilter = OwnerLens::filter('c.owner_id', $owner);

        if ($ownerFilter !== null) {
            $where[] = $ownerFilter[0];
            $params += $ownerFilter[1];
        }

        if ($tag !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM contact_tags ct
                JOIN tags t ON t.id = ct.tag_id
                WHERE ct.contact_id = c.id AND t.name = :tag)';
            $params['tag'] = $tag;
        }

        if ($query !== '') {
            // Two placeholders for the same value: native prepares (emulation
            // off) reject reusing one named parameter twice in a statement.
            $where[] = "(c.name LIKE :query_name ESCAPE '!' OR o.name LIKE :query_org ESCAPE '!')";
            $params['query_name'] = '%' . Database::escapeLike($query) . '%';
            $params['query_org'] = '%' . Database::escapeLike($query) . '%';
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
     * The default horizon is 6 = "this week" as 7 calendar days INCLUDING
     * today. It was 7, which made a one-week snooze (today + 7) land exactly
     * on the boundary and stay on the dashboard — Dana's snooze-looks-
     * buggy report (2026-07-22). Snoozing a week now clears the list.
     *
     * $forUserId personalizes the list to that user's lens (their contacts
     * plus shared ones); null keeps the combined everyone view.
     *
     * @return list<array<string, mixed>>
     */
    public function dueBy(string $today, int $horizonDays = 6, ?int $forUserId = null): array
    {
        $params = ['today' => $today, 'horizon' => $horizonDays];
        $lens = '';

        if ($forUserId !== null) {
            $lens = ' AND ' . self::ownerLens('for_user');
            $params['for_user'] = $forUserId;
        }

        $stmt = $this->db->pdo()->prepare(
            // Stages flagged hidden_from_follow_ups (Leads by default, see
            // /settings) never surface on the dashboard or in the email.
            self::BASE_SELECT . "
            WHERE c.next_touch_date IS NOT NULL
              AND c.deleted_at IS NULL
              AND COALESCE(st.hidden_from_follow_ups, 0) = 0
              AND c.next_touch_date <= DATE_ADD(:today, INTERVAL :horizon DAY)"
            . $lens . '
            ORDER BY c.next_touch_date ASC, c.name'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Due today or overdue, with the going-cold contacts removed: the
     * dashboard's split (mildly late and quietly dying are different
     * problems), shared by the Slack debrief and the /rolo lists so every
     * surface quotes the same numbers (Colin, 2026-11-12: the debrief said
     * 31 where the dashboard said 28 overdue + 1 due today).
     *
     * @return list<array<string, mixed>>
     */
    public function dueExcludingCold(string $today, ?int $forUserId = null): array
    {
        $coldIds = array_map(
            static fn(array $c): int => (int) $c['id'],
            $this->goingCold($today, forUserId: $forUserId),
        );

        return array_values(array_filter(
            $this->dueBy($today, 0, $forUserId),
            static fn(array $c): bool => !in_array((int) $c['id'], $coldIds, true),
        ));
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
     * Live contacts whose email is in the list, keyed by lowercase email —
     * how calendar-event attendees become contacts (CalendarSuggestionService).
     *
     * @param list<string> $emails
     * @return array<string, array<string, mixed>> lowercase email => contact
     */
    public function activeByEmails(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn(string $e): string => strtolower(trim($e)),
            $emails,
        ), static fn(string $e): bool => $e !== '')));

        if ($emails === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($emails), '?'));
        $stmt = $this->db->pdo()->prepare(
            self::BASE_SELECT
                . " WHERE c.deleted_at IS NULL AND LOWER(c.email) IN ($placeholders)"
        );
        $stmt->execute($emails);

        $byEmail = [];

        foreach ($stmt->fetchAll() as $row) {
            $byEmail[strtolower((string) $row['email'])] = $row;
        }

        return $byEmail;
    }

    /**
     * Cursor-paginated list for the agent API. $afterId is the decoded
     * cursor; rows come back in id order so pagination is stable.
     *
     * @return list<array<string, mixed>>
     */
    public function apiList(
        string $query,
        string $stage,
        string $updatedSince,
        int $afterId,
        int $limit,
        string $tag = '',
        string $owner = '',
    ): array {
        $sql = self::BASE_SELECT;
        $where = ['c.deleted_at IS NULL', 'c.id > :after_id'];
        $params = ['after_id' => $afterId];

        // Same owner semantics as search(): '' = anyone, 'shared' = unowned,
        // a numeric string = that user's contacts.
        $ownerFilter = OwnerLens::filter('c.owner_id', $owner);

        if ($ownerFilter !== null) {
            $where[] = $ownerFilter[0];
            $params += $ownerFilter[1];
        }

        if ($tag !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM contact_tags ct
                JOIN tags t ON t.id = ct.tag_id
                WHERE ct.contact_id = c.id AND t.name = :tag)';
            $params['tag'] = $tag;
        }

        if ($query !== '') {
            $where[] = "(c.name LIKE :query_name ESCAPE '!' OR o.name LIKE :query_org ESCAPE '!')";
            $params['query_name'] = '%' . Database::escapeLike($query) . '%';
            $params['query_org'] = '%' . Database::escapeLike($query) . '%';
        }

        if ($stage !== '') {
            $where[] = 'c.relationship_status = :stage';
            $params['stage'] = $stage;
        }

        if ($updatedSince !== '') {
            $where[] = 'c.updated_at >= :updated_since';
            $params['updated_since'] = $updatedSince;
        }

        $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id LIMIT ' . $limit;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Every live contact as a light id/name/organization row, for pickers
     * (the task forms' "about" select). Skips BASE_SELECT's per-row
     * follow-up-note subquery, which a dropdown has no use for.
     *
     * @return list<array<string, mixed>>
     */
    public function options(): array
    {
        return $this->db->pdo()->query(
            'SELECT c.id, c.name, o.name AS organization_name
             FROM contacts c
             LEFT JOIN organizations o ON c.organization_id = o.id
             WHERE c.deleted_at IS NULL
             ORDER BY c.name, c.id'
        )->fetchAll();
    }

    /**
     * How many live contacts the user owns — the dashboard's empty-state
     * hint, so an empty Mine view can say "you own N, nothing is due"
     * instead of showing nothing at all.
     */
    public function countForOwner(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM contacts WHERE owner_id = :owner_id AND deleted_at IS NULL'
        );
        $stmt->execute(['owner_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Set the next touch date directly (snooze, the manual date editor, and
     * the Slack undo all land here) without logging an activity or moving
     * last_touch. Null clears the reminder entirely. The updated_by trail
     * still records who did it.
     */
    public function snooze(int $id, ?string $nextTouchDate, int $userId): void
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
     * Relationships going cold: anyone in a live stage who is more than
     * $lateDays past their follow-up date, or has no follow-up scheduled
     * at all. Dormant and Closed are excluded on purpose (parked is a
     * choice, cold is a failure). Coldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function goingCold(string $today, int $lateDays = 30, ?int $forUserId = null): array
    {
        $params = ['today' => $today, 'today_b' => $today, 'late_days' => $lateDays];
        $lens = '';

        if ($forUserId !== null) {
            $lens = ' AND ' . self::ownerLens('for_user');
            $params['for_user'] = $forUserId;
        }

        $stmt = $this->db->pdo()->prepare(
            self::BASE_SELECT . "
            WHERE c.deleted_at IS NULL
              AND c.relationship_status NOT IN ('Dormant', 'Closed / Not a Fit')
              AND COALESCE(st.hidden_from_follow_ups, 0) = 0
              AND ((c.next_touch_date IS NULL
                        AND c.created_at < DATE_SUB(:today_b, INTERVAL 7 DAY))
                   OR c.next_touch_date < DATE_SUB(:today, INTERVAL :late_days DAY))"
            . $lens . '
            ORDER BY (c.last_touch_date IS NULL) DESC, c.last_touch_date ASC, c.name'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Set or clear the profile photo's stored filename.
     */
    public function setPhoto(int $id, ?string $storedName, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE contacts SET photo = :photo, updated_by = :updated_by WHERE id = :id'
        );
        $stmt->execute(['photo' => $storedName, 'updated_by' => $userId, 'id' => $id]);
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
                owner_id, email, phone, linkedin_url, cadence_days_override, human_detail,
                next_touch_date, created_by, updated_by
            ) VALUES (
                :name, :organization_id, :title, :relationship_status, :relationship_type,
                :owner_id, :email, :phone, :linkedin_url, :cadence_days_override, :human_detail,
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
                owner_id = :owner_id,
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
    public function recordTouch(int $id, ?string $lastTouchDate, ?string $nextTouchDate, int $userId): void
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
            'owner_id' => $data['owner_id'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'linkedin_url' => $data['linkedin_url'],
            'cadence_days_override' => $data['cadence_days_override'],
            'human_detail' => $data['human_detail'],
            'next_touch_date' => $data['next_touch_date'],
        ];
    }
}
