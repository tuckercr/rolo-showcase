<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure helpers for the agent-facing API: cursor encoding and timestamp
 * formatting (per the integration spec: stable IDs + timestamps on every
 * object, cursor pagination on every list).
 */
final class ApiFormat
{
    /**
     * Cursors are opaque to callers: base64 of the last row id seen.
     */
    public static function encodeCursor(int $lastId): string
    {
        return rtrim(strtr(base64_encode('v1:' . $lastId), '+/', '-_'), '=');
    }

    /**
     * Returns the id a cursor points past, 0 for absent/invalid cursors
     * (an invalid cursor just starts the list over rather than erroring).
     */
    public static function decodeCursor(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || !str_starts_with($decoded, 'v1:')) {
            return 0;
        }

        $id = substr($decoded, 3);

        return ctype_digit($id) ? (int) $id : 0;
    }

    /**
     * DB DATETIMEs are stored in UTC; present them as ISO 8601 with Z.
     * DATE-only values pass through unchanged. Null stays null.
     */
    public static function isoDateTime(?string $dbValue): ?string
    {
        if ($dbValue === null || $dbValue === '') {
            return null;
        }

        if (!str_contains($dbValue, ' ')) {
            return $dbValue;
        }

        return str_replace(' ', 'T', $dbValue) . 'Z';
    }

    /**
     * Clamp a requested page size to 1..100 (default 25).
     */
    public static function clampLimit(mixed $raw): int
    {
        $limit = is_numeric($raw) ? (int) $raw : 25;

        return max(1, min(100, $limit));
    }
}
