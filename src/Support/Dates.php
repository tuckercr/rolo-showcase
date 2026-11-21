<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Small date helpers for display math. Kept pure so the subtle parts
 * (timezone-consistent whole-day differences) are unit-testable.
 */
final class Dates
{
    /**
     * Whole days between two Y-m-d dates, both interpreted in the SAME
     * timezone. Mixing zones (a midnight-local vs a midnight-UTC
     * DateTimeImmutable) makes DateInterval::days truncate one short for
     * zones ahead of UTC; constructing both here avoids that class of bug.
     */
    public static function daysBetween(string $fromYmd, string $toYmd, DateTimeZone $zone): int
    {
        $from = new DateTimeImmutable($fromYmd, $zone);
        $to = new DateTimeImmutable($toYmd, $zone);

        return (int) $from->diff($to)->days;
    }

    /**
     * Whether $value is a real calendar date written exactly as YYYY-MM-DD.
     * The round trip is what rejects "2026-02-30" (PHP would roll it over
     * to March) and loose spellings like "2026-1-5". The one definition
     * every form, command and API filter shares.
     */
    public static function isValidYmd(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
