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

    public function testIsValidYmdAcceptsOnlyRealDatesWrittenExactly(): void
    {
        foreach (['2026-11-20', '2028-02-29', '2026-01-01', '2026-12-31'] as $good) {
            $this->assertTrue(Dates::isValidYmd($good), $good);
        }

        foreach (
            [
                '',
                '2026-02-30',       // PHP would roll this over into March
                '2027-02-29',       // not a leap year
                '2026-13-01',
                '2026-1-5',
                '11/20/2026',
                '20-11-2026',
                'friday',
                '2026-11-20 10:00',
                ' 2026-11-20',
                "2026-11-20\n",
            ] as $bad
        ) {
            $this->assertFalse(Dates::isValidYmd($bad), var_export($bad, true));
        }
    }

    public function testAcrossDstTransitions(): void
    {
        $zone = new DateTimeZone('America/New_York');

        // US DST ends 2026-11-01; the day count must stay whole.
        $this->assertSame(2, Dates::daysBetween('2026-10-31', '2026-11-02', $zone));
    }
}
