<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deterministic initials-avatar colors, shared by the web avatar partial
 * and the daily email (which needs raw hex values for inline styles).
 * Muted tones chosen to sit well with the brand palette.
 */
final class AvatarTone
{
    private const TONES = [
        ['bg' => '#CBB89A', 'text' => '#4A3F2E'],
        ['bg' => '#B9C4B1', 'text' => '#3C4636'],
        ['bg' => '#C6B7C9', 'text' => '#473A4A'],
        ['bg' => '#AFC3CB', 'text' => '#35454D'],
        ['bg' => '#D3B1A5', 'text' => '#4E352D'],
        ['bg' => '#C7C0A8', 'text' => '#45412E'],
    ];

    /**
     * @return array{bg: string, text: string}
     */
    public static function for(string $name): array
    {
        return self::TONES[crc32(mb_strtolower(trim($name))) % count(self::TONES)];
    }

    public static function initials(string $name): string
    {
        $name = trim($name) ?: '?';
        $words = preg_split('/\s+/', $name) ?: [];

        return mb_strtoupper(
            mb_substr($words[0] ?? '?', 0, 1) . mb_substr($words[1] ?? '', 0, 1)
        );
    }
}
