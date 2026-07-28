<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

final class OrganizationModel
{
    public const ORG_TYPES = [
        'startup' => 'Startup',
        'cro' => 'CRO',
        'university' => 'University',
        'investor' => 'Investor',
        'media' => 'Media',
        'other' => 'Other',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allOrdered(): array
    {
        return $this->db->pdo()
            ->query('SELECT * FROM organizations ORDER BY name')
            ->fetchAll();
    }

    /**
     * List view: organizations with how many contacts each holds.
     *
     * @return list<array<string, mixed>>
     */
    public function searchWithContactCounts(string $query = '', string $orgType = ''): array
    {
        $sql = '
            SELECT o.*, COUNT(c.id) AS contact_count
            FROM organizations o
            LEFT JOIN contacts c ON c.organization_id = o.id
        ';
        $where = [];
        $params = [];

        if ($query !== '') {
            $where[] = 'o.name LIKE :query';
            $params['query'] = '%' . $query . '%';
        }

        if ($orgType !== '') {
            $where[] = 'o.org_type = :org_type';
            $params['org_type'] = $orgType;
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' GROUP BY o.id ORDER BY o.name';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM organizations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $data validated field values
     */
    public function create(array $data): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO organizations (name, org_type, website, notes)
             VALUES (:name, :org_type, :website, :notes)'
        );
        $stmt->execute($data);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data validated field values
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE organizations
             SET name = :name, org_type = :org_type, website = :website, notes = :notes
             WHERE id = :id'
        );
        $stmt->execute($data + ['id' => $id]);
    }
}
