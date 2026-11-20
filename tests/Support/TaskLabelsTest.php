<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\TaskLabels;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class TaskLabelsTest extends TestCase
{
    private const TODAY = '2026-11-20';

    private function zone(): DateTimeZone
    {
        return new DateTimeZone('America/New_York');
    }

    public function testDueLabels(): void
    {
        $zone = $this->zone();

        self::assertSame('', TaskLabels::due(null, self::TODAY, $zone));
        self::assertSame('due today', TaskLabels::due('2026-11-20', self::TODAY, $zone));
        self::assertSame('due tomorrow', TaskLabels::due('2026-11-21', self::TODAY, $zone));
        self::assertSame('due Nov 24', TaskLabels::due('2026-11-24', self::TODAY, $zone));
        self::assertSame('due Nov 19 (1 day late)', TaskLabels::due('2026-11-19', self::TODAY, $zone));
        self::assertSame('due Nov 18 (2 days late)', TaskLabels::due('2026-11-18', self::TODAY, $zone));
    }

    public function testAnotherYearShowsTheYear(): void
    {
        self::assertSame('due Jan 5, 2027', TaskLabels::due('2027-01-05', self::TODAY, $this->zone()));
    }

    public function testOverdueIsStrictlyBeforeToday(): void
    {
        self::assertTrue(TaskLabels::isOverdue('2026-11-19', self::TODAY));
        self::assertFalse(TaskLabels::isOverdue('2026-11-20', self::TODAY));
        self::assertFalse(TaskLabels::isOverdue(null, self::TODAY));
    }

    public function testDoneDayIsReadInTheAppTimezoneNotUtc(): void
    {
        $zone = $this->zone();

        // 02:30 UTC on the 21st is still the evening of the 20th in New York.
        self::assertSame('done today', TaskLabels::done('2026-11-21 02:30:00', self::TODAY, $zone));
        self::assertSame('done yesterday', TaskLabels::done('2026-11-19 15:00:00', self::TODAY, $zone));
        self::assertSame('done Nov 17', TaskLabels::done('2026-11-17 15:00:00', self::TODAY, $zone));
    }

    public function testDecorateAddsTheDisplayFields(): void
    {
        $rows = TaskLabels::decorate([
            ['due_date' => '2026-11-18', 'completed_at' => null],
            ['due_date' => '2026-11-18', 'completed_at' => '2026-11-20 15:00:00'],
            ['due_date' => null, 'completed_at' => null],
        ], self::TODAY, $this->zone());

        self::assertSame('due Nov 18 (2 days late)', $rows[0]['due_label']);
        self::assertTrue($rows[0]['is_overdue']);
        self::assertSame('', $rows[0]['done_label']);

        self::assertFalse($rows[1]['is_overdue'], 'a done task is never overdue');
        self::assertSame('done today', $rows[1]['done_label']);

        self::assertSame('', $rows[2]['due_label']);
        self::assertFalse($rows[2]['is_overdue']);
    }
}
