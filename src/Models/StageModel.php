<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

final class StageModel
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allOrdered(): array
    {
        return $this->db->pdo()
            ->query('SELECT * FROM stages ORDER BY sort_order')
            ->fetchAll();
    }

    public function exists(string $name): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM stages WHERE name = :name');
        $stmt->execute(['name' => $name]);

        return $stmt->fetch() !== false;
    }
}
