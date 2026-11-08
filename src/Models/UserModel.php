<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use PDO;

final class UserModel
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->pdo()
            ->query('SELECT * FROM users ORDER BY id')
            ->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByGoogleSub(string $googleSub): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE google_sub = :sub');
        $stmt->execute(['sub' => $googleSub]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * First login for an allowlisted email that has no users row yet
     * (docs/SCHEMA.md Authentication).
     */
    public function create(string $name, string $email, string $googleSub, string $role = 'member'): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (name, email, google_sub, role)
             VALUES (:name, :email, :google_sub, :role)'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'google_sub' => $googleSub,
            'role' => $role,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function setGoogleSub(int $id, string $googleSub): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET google_sub = :sub WHERE id = :id');
        $stmt->execute(['sub' => $googleSub, 'id' => $id]);
    }
}
