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

    /**
     * Toggle whether a stage is hidden from the dashboard and daily email.
     */
    public function setHidden(int $id, bool $hidden): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stages SET hidden_from_follow_ups = :hidden WHERE id = :id'
        );
        $stmt->execute(['hidden' => (int) $hidden, 'id' => $id]);
    }

    /**
     * The documented default: Leads and Closed / Not a Fit are hidden.
     */
    public function resetHiddenToDefault(): void
    {
        $this->db->pdo()->exec(
            "UPDATE stages
                SET hidden_from_follow_ups = IF(name IN ('Leads', 'Closed / Not a Fit'), 1, 0)"
        );
    }

    public function exists(string $name): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM stages WHERE name = :name');
        $stmt->execute(['name' => $name]);

        return $stmt->fetch() !== false;
    }
}
