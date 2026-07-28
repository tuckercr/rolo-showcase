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
     * system-generated entries (snoozes), which are display-only.
     */
    public const DISPLAY_TYPES = self::TYPES + ['snoozed' => 'Snoozed'];

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
     * All interactions logged for a given calendar date, regardless of who
     * logged them — used by the daily summary email ("what you did yesterday").
     *
     * @return list<array<string, mixed>>
     */
    public function onDate(string $date): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, c.name AS contact_name, u.name AS created_by_name
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
     * 2026-07-10). The activity date is deliberately not editable; it
     * anchors last_touch history.
     */
    public function update(
        int $id,
        string $activityType,
        string $summary,
        bool $followUpNeeded,
        ?string $followUpDate,
        int $userId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE activities SET
                activity_type = :activity_type,
                summary = :summary,
                follow_up_needed = :follow_up_needed,
                follow_up_date = :follow_up_date,
                updated_by = :updated_by,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id'
        );
        $stmt->execute([
            'activity_type' => $activityType,
            'summary' => $summary,
            'follow_up_needed' => (int) $followUpNeeded,
            'follow_up_date' => $followUpDate,
            'updated_by' => $userId,
            'id' => $id,
        ]);
    }

    /**
     * Whether this activity is the contact's most recent log entry — edits
     * to it may reschedule the contact's next touch.
     */
    public function isLatestForContact(int $activityId, int $contactId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(id) FROM activities WHERE contact_id = :contact_id'
        );
        $stmt->execute(['contact_id' => $contactId]);

        return (int) $stmt->fetchColumn() === $activityId;
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
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO activities (
                contact_id, organization_id, activity_type, activity_date,
                summary, follow_up_needed, follow_up_date, created_by
            ) VALUES (
                :contact_id, :organization_id, :activity_type, :activity_date,
                :summary, :follow_up_needed, :follow_up_date, :created_by
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
            'created_by' => $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
