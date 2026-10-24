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
}
