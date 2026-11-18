<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * One row per user who connected Google Calendar from /settings
 * (migration 0019). Reconnecting replaces the row; disconnecting deletes it.
 */
final class CalendarConnectionModel
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT user_id, google_email, refresh_token, connected_at
             FROM calendar_connections WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function save(int $userId, string $googleEmail, string $refreshToken): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO calendar_connections (user_id, google_email, refresh_token)
             VALUES (:user_id, :google_email, :refresh_token)
             ON DUPLICATE KEY UPDATE
                google_email = VALUES(google_email),
                refresh_token = VALUES(refresh_token),
                connected_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'user_id' => $userId,
            'google_email' => strtolower($googleEmail),
            'refresh_token' => $refreshToken,
        ]);
    }

    public function delete(int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM calendar_connections WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
    }
}
