<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use PDO;

/**
 * Freeform context tags (docs/SCHEMA.md) — tables existed since migration
 * 0001; the feature landed with the agent integration piece 2. Tag names
 * are unique case-insensitively (utf8mb4_unicode_ci unique index).
 */
final class TagModel
{
    public const MAX_LENGTH = 100;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * All tags with how many (non-trashed) contacts carry each.
     *
     * @return list<array<string, mixed>>
     */
    public function allWithCounts(): array
    {
        return $this->db->pdo()->query(
            'SELECT t.id, t.name, t.description, COUNT(c.id) AS contact_count
             FROM tags t
             LEFT JOIN contact_tags ct ON ct.tag_id = t.id
             LEFT JOIN contacts c ON c.id = ct.contact_id AND c.deleted_at IS NULL
             GROUP BY t.id, t.name, t.description
             ORDER BY t.name'
        )->fetchAll();
    }

    /**
     * Just the names, for form autocompletion.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $rows = $this->db->pdo()
            ->query('SELECT name FROM tags ORDER BY name')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map(strval(...), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tags WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : (array) $row;
    }

    /**
     * @return list<string>
     */
    public function namesForContact(int $contactId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.name FROM tags t
             JOIN contact_tags ct ON ct.tag_id = t.id
             WHERE ct.contact_id = :contact_id
             ORDER BY t.name'
        );
        $stmt->execute(['contact_id' => $contactId]);

        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Tag names for many contacts in one query, keyed by contact id.
     *
     * @param list<int> $contactIds
     * @return array<int, list<string>>
     */
    public function namesForContacts(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ct.contact_id, t.name FROM tags t
             JOIN contact_tags ct ON ct.tag_id = t.id
             WHERE ct.contact_id IN (' . $placeholders . ')
             ORDER BY t.name'
        );
        $stmt->execute(array_values($contactIds));

        $grouped = [];

        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['contact_id']][] = (string) $row['name'];
        }

        return $grouped;
    }

    public function findOrCreate(string $name): int
    {
        $select = $this->db->pdo()->prepare('SELECT id FROM tags WHERE name = :name');
        $select->execute(['name' => $name]);
        $id = $select->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $insert = $this->db->pdo()->prepare('INSERT IGNORE INTO tags (name) VALUES (:name)');
        $insert->execute(['name' => $name]);

        $select->execute(['name' => $name]);

        return (int) $select->fetchColumn();
    }

    /**
     * Replace a contact's tags with exactly this list (the UI form flow).
     *
     * @param list<string> $names
     */
    public function syncForContact(int $contactId, array $names): void
    {
        $delete = $this->db->pdo()->prepare(
            'DELETE FROM contact_tags WHERE contact_id = :contact_id'
        );
        $delete->execute(['contact_id' => $contactId]);

        $this->addForContact($contactId, $names);
        $this->pruneOrphans();
    }

    /**
     * @param list<string> $names
     */
    public function addForContact(int $contactId, array $names): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO contact_tags (contact_id, tag_id) VALUES (:contact_id, :tag_id)'
        );

        foreach ($names as $name) {
            $insert->execute(['contact_id' => $contactId, 'tag_id' => $this->findOrCreate($name)]);
        }
    }

    /**
     * @param list<string> $names
     */
    public function removeForContact(int $contactId, array $names): void
    {
        $delete = $this->db->pdo()->prepare(
            'DELETE ct FROM contact_tags ct
             JOIN tags t ON t.id = ct.tag_id
             WHERE ct.contact_id = :contact_id AND t.name = :name'
        );

        foreach ($names as $name) {
            $delete->execute(['contact_id' => $contactId, 'name' => $name]);
        }

        $this->pruneOrphans();
    }

    /**
     * Rename a tag and set its description. Renaming onto another existing
     * tag's name (case-insensitively) merges this tag into it instead:
     * contacts are repointed, and the target keeps its own description
     * unless it has none.
     *
     * @return string|null 'updated', 'merged', or null when the tag is gone
     */
    public function update(int $id, string $name, string $description): ?string
    {
        if ($this->find($id) === null) {
            return null;
        }

        $pdo = $this->db->pdo();
        $select = $pdo->prepare('SELECT id FROM tags WHERE name = :name');
        $select->execute(['name' => $name]);
        $existing = $select->fetchColumn();

        if ($existing === false || (int) $existing === $id) {
            $stmt = $pdo->prepare(
                'UPDATE tags SET name = :name, description = :description WHERE id = :id'
            );
            $stmt->execute(['name' => $name, 'description' => $description, 'id' => $id]);

            return 'updated';
        }

        $target = (int) $existing;
        $pdo->beginTransaction();

        try {
            $repoint = $pdo->prepare(
                'INSERT IGNORE INTO contact_tags (contact_id, tag_id)
                 SELECT contact_id, :target FROM contact_tags WHERE tag_id = :id'
            );
            $repoint->execute(['target' => $target, 'id' => $id]);

            $describe = $pdo->prepare(
                "UPDATE tags SET description = :description
                 WHERE id = :target AND description = '' AND :description2 <> ''"
            );
            $describe->execute([
                'description' => $description,
                'target' => $target,
                'description2' => $description,
            ]);

            // The contact_tags FK cascades, removing the old links.
            $pdo->prepare('DELETE FROM tags WHERE id = :id')->execute(['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return 'merged';
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tags WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Tags with no contacts left disappear (keeps filter dropdowns clean) —
     * unless they carry a description, which marks them as curated on the
     * /tags page and worth keeping while empty.
     */
    public function pruneOrphans(): void
    {
        $this->db->pdo()->exec(
            "DELETE FROM tags
             WHERE description = ''
               AND NOT EXISTS (SELECT 1 FROM contact_tags ct WHERE ct.tag_id = tags.id)"
        );
    }

    /**
     * Parse a comma-separated tag string from a form into clean names:
     * trimmed, inner whitespace collapsed, de-duplicated, empties dropped.
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $names = [];

        foreach (explode(',', $raw) as $piece) {
            $name = trim((string) preg_replace('/\s+/', ' ', $piece));

            if ($name === '') {
                continue;
            }

            $lower = mb_strtolower($name);

            if (!isset($names[$lower])) {
                $names[$lower] = $name;
            }
        }

        return array_values($names);
    }
}
