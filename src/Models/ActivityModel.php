<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

final class ActivityModel
{
    /** Types a person can log — offered in the interaction form. */
    public const TYPES = [
        'event_meeting' => 'Event / Meeting',
        'email' => 'Email',
        'call' => 'Call',
        'coffee' => 'Coffee',
        'linkedin_message' => 'LinkedIn message',
        'intro_made' => 'Intro made',
        'other' => 'Other',
    ];

    /**
     * Everything that can appear in history — loggable types plus
     * system-generated entries (snoozes) and agent-logged campaign touches.
     */
    public const DISPLAY_TYPES = self::TYPES + ['snoozed' => 'Snoozed', 'campaign' => 'Campaign'];

    /**
     * Types the agent API may propose: everything a human can log, plus
     * 'campaign' (bulk marketing touches). Never 'snoozed'.
     */
    public const API_LOGGABLE_TYPES = [
        'event_meeting',
        'email',
        'call',
        'coffee',
        'linkedin_message',
        'intro_made',
        'other',
        'campaign',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Newest first — the interaction history shown on the contact page.
     *
     * @return list<array<string, mixed>>
     */
    public function forContact(int $contactId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, u.name AS created_by_name, eu.name AS updated_by_name
             FROM activities a
             JOIN users u ON a.created_by = u.id
             LEFT JOIN users eu ON a.updated_by = eu.id
             WHERE a.contact_id = :contact_id
             ORDER BY a.activity_date DESC, a.id DESC'
        );
        $stmt->execute(['contact_id' => $contactId]);

        return $stmt->fetchAll();
    }

    /**
     * Cursor-paginated activity feed for the agent API (id order). $since
     * filters on activity_date (YYYY-MM-DD); excludes trashed contacts.
     *
     * @return list<array<string, mixed>>
     */
    public function apiList(int $contactId, string $since, int $afterId, int $limit): array
    {
        $sql = 'SELECT a.*, c.name AS contact_name, u.name AS created_by_name,
                       (SELECT COUNT(*) FROM activity_attachments att
                        WHERE att.activity_id = a.id) AS attachment_count
                FROM activities a
                JOIN contacts c ON a.contact_id = c.id
                JOIN users u ON a.created_by = u.id';
        $where = ['c.deleted_at IS NULL', 'a.id > :after_id'];
        $params = ['after_id' => $afterId];

        if ($contactId > 0) {
            $where[] = 'a.contact_id = :contact_id';
            $params['contact_id'] = $contactId;
        }

        if ($since !== '') {
            $where[] = 'a.activity_date >= :since';
            $params['since'] = $since;
        }

        $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id LIMIT ' . $limit;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * All interactions logged for a given calendar date, regardless of who
     * logged them — used by the daily summary email ("what you did yesterday").
     *
     * @return list<array<string, mixed>>
     */
    public function onDate(string $date): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, c.name AS contact_name, u.name AS created_by_name,
                    (SELECT COUNT(*) FROM activity_attachments att
                     WHERE att.activity_id = a.id) AS attachment_count
             FROM activities a
             JOIN contacts c ON a.contact_id = c.id
             JOIN users u ON a.created_by = u.id
             WHERE a.activity_date = :date
             ORDER BY a.id'
        );
        $stmt->execute(['date' => $date]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, u.name AS created_by_name
             FROM activities a
             JOIN users u ON a.created_by = u.id
             WHERE a.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Edit in place — latest content only, no revision history (decision
     * 2026-07-10; date made editable 2026-07-28 after typos proved the
     * immutability more annoying than protective).
     */
    public function update(
        int $id,
        string $activityType,
        string $activityDate,
        string $summary,
        bool $followUpNeeded,
        ?string $followUpDate,
        int $userId,
        ?string $followUpNote = null,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE activities SET
                activity_type = :activity_type,
                activity_date = :activity_date,
                summary = :summary,
                follow_up_needed = :follow_up_needed,
                follow_up_date = :follow_up_date,
                follow_up_note = :follow_up_note,
                updated_by = :updated_by,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id'
        );
        $stmt->execute([
            'activity_type' => $activityType,
            'activity_date' => $activityDate,
            'summary' => $summary,
            'follow_up_needed' => (int) $followUpNeeded,
            'follow_up_date' => $followUpDate,
            'follow_up_note' => $followUpNote,
            'updated_by' => $userId,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM activities WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * The contact's most recent real touch — snooze entries don't count.
     * Basis for recomputing last_touch/next_touch after edits and deletes.
     *
     * @return array<string, mixed>|null
     */
    public function latestTouchFor(int $contactId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM activities
             WHERE contact_id = :contact_id AND activity_type != 'snoozed'
             ORDER BY activity_date DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['contact_id' => $contactId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(
        int $contactId,
        ?int $organizationId,
        string $activityType,
        string $activityDate,
        string $summary,
        bool $followUpNeeded,
        ?string $followUpDate,
        int $userId,
        ?string $followUpNote = null,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO activities (
                contact_id, organization_id, activity_type, activity_date,
                summary, follow_up_needed, follow_up_date, follow_up_note, created_by
            ) VALUES (
                :contact_id, :organization_id, :activity_type, :activity_date,
                :summary, :follow_up_needed, :follow_up_date, :follow_up_note, :created_by
            )'
        );

        $stmt->execute([
            'contact_id' => $contactId,
            'organization_id' => $organizationId,
            'activity_type' => $activityType,
            'activity_date' => $activityDate,
            'summary' => $summary,
            'follow_up_needed' => (int) $followUpNeeded,
            'follow_up_date' => $followUpDate,
            'follow_up_note' => $followUpNote,
            'created_by' => $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
