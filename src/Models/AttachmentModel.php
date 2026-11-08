<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

final class AttachmentModel
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM activity_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forActivity(int $activityId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM activity_attachments WHERE activity_id = :activity_id ORDER BY id'
        );
        $stmt->execute(['activity_id' => $activityId]);

        return $stmt->fetchAll();
    }

    /**
     * All attachments for a contact's history, keyed by activity id — one
     * query for the contact page instead of one per history entry.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function forContactByActivity(int $contactId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT att.*
             FROM activity_attachments att
             JOIN activities a ON att.activity_id = a.id
             WHERE a.contact_id = :contact_id
             ORDER BY att.id'
        );
        $stmt->execute(['contact_id' => $contactId]);

        $grouped = [];

        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['activity_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Every attachment with its contact/interaction context — the library
     * page. Attachments of trashed contacts are hidden, consistent with
     * trash semantics everywhere else.
     *
     * @return list<array<string, mixed>>
     */
    public function searchAll(string $query = ''): array
    {
        $sql = 'SELECT att.*, a.activity_type, a.activity_date, a.contact_id,
                       c.name AS contact_name, u.name AS uploaded_by_name
                FROM activity_attachments att
                JOIN activities a ON att.activity_id = a.id
                JOIN contacts c ON a.contact_id = c.id
                JOIN users u ON att.uploaded_by = u.id
                WHERE c.deleted_at IS NULL';
        $params = [];

        if ($query !== '') {
            // Separate placeholders: native prepares reject reusing one name.
            $sql .= ' AND (att.original_name LIKE :q_file OR c.name LIKE :q_contact)';
            $params['q_file'] = '%' . $query . '%';
            $params['q_contact'] = '%' . $query . '%';
        }

        $sql .= ' ORDER BY att.created_at DESC, att.id DESC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function totalBytes(): int
    {
        return (int) $this->db->pdo()
            ->query('SELECT COALESCE(SUM(size_bytes), 0) FROM activity_attachments')
            ->fetchColumn();
    }

    /**
     * @return list<string>
     */
    public function allStoredNames(): array
    {
        return array_map(
            strval(...),
            $this->db->pdo()
                ->query('SELECT stored_name FROM activity_attachments')
                ->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    public function countForActivity(int $activityId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_attachments WHERE activity_id = :activity_id'
        );
        $stmt->execute(['activity_id' => $activityId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{stored_name: string, original_name: string, mime_type: string, size_bytes: int} $meta
     */
    public function create(int $activityId, array $meta, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO activity_attachments
                (activity_id, stored_name, original_name, mime_type, size_bytes, uploaded_by)
             VALUES (:activity_id, :stored_name, :original_name, :mime_type, :size_bytes, :uploaded_by)'
        );
        $stmt->execute([
            'activity_id' => $activityId,
            'stored_name' => $meta['stored_name'],
            'original_name' => $meta['original_name'],
            'mime_type' => $meta['mime_type'],
            'size_bytes' => $meta['size_bytes'],
            'uploaded_by' => $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM activity_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
