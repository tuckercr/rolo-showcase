<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Dates;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DatesTest extends TestCase
{
    public function testWholeDayDifference(): void
    {
        $zone = new DateTimeZone('America/New_York');

        $this->assertSame(0, Dates::daysBetween('2026-10-13', '2026-10-13', $zone));
        $this->assertSame(1, Dates::daysBetween('2026-10-12', '2026-10-13', $zone));
        $this->assertSame(14, Dates::daysBetween('2026-09-29', '2026-10-13', $zone));
    }

    public function testZonesAheadOfUtcDoNotTruncateADay(): void
    {
        // The bug this helper exists to prevent: midnight Paris vs midnight
        // UTC differs by 22-23 hours, which DateInterval::days truncates
        // to N-1 when the dates are constructed in different zones.
        $zone = new DateTimeZone('Europe/Paris');

        $this->assertSame(1, Dates::daysBetween('2026-10-12', '2026-10-13', $zone));
        $this->assertSame(45, Dates::daysBetween('2026-08-29', '2026-10-13', $zone));
    }

    public function testAcrossDstTransitions(): void
    {
        $zone = new DateTimeZone('America/New_York');

        // US DST ends 2026-11-01; the day count must stay whole.
        $this->assertSame(2, Dates::daysBetween('2026-10-31', '2026-11-02', $zone));
    }
}
