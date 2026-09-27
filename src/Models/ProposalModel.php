<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

final class ProposalModel
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM api_proposals WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM api_proposals WHERE idempotency_key = :key'
        );
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(
        string $tokenName,
        string $action,
        array $payload,
        string $summary,
        ?string $idempotencyKey,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO api_proposals (token_name, action, payload, summary, idempotency_key)
             VALUES (:token_name, :action, :payload, :summary, :idempotency_key)'
        );
        $stmt->execute([
            'token_name' => $tokenName,
            'action' => $action,
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'summary' => mb_substr($summary, 0, 500),
            'idempotency_key' => $idempotencyKey,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->db->pdo()->query(
            "SELECT * FROM api_proposals WHERE status = 'pending' ORDER BY id"
        )->fetchAll();
    }

    public function pendingCount(): int
    {
        return (int) $this->db->pdo()
            ->query("SELECT COUNT(*) FROM api_proposals WHERE status = 'pending'")
            ->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentlyDecided(int $limit = 20): array
    {
        return $this->db->pdo()->query(
            "SELECT p.*, u.name AS reviewed_by_name
             FROM api_proposals p
             LEFT JOIN users u ON p.reviewed_by = u.id
             WHERE p.status != 'pending'
             ORDER BY p.reviewed_at DESC, p.id DESC
             LIMIT " . (int) $limit
        )->fetchAll();
    }

    public function decide(int $id, string $status, int $reviewerId, ?string $error = null): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_proposals
             SET status = :status, reviewed_by = :reviewed_by,
                 reviewed_at = CURRENT_TIMESTAMP, error = :error
             WHERE id = :id AND status = \'pending\''
        );
        $stmt->execute([
            'status' => $status,
            'reviewed_by' => $reviewerId,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'id' => $id,
        ]);
    }
}
