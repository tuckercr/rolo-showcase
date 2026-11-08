<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\ReminderService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReminderServiceTest extends TestCase
{
    /**
     * The seeded rules from database/migrations/0002 (docs/SCHEMA.md table).
     */
    private function service(): ReminderService
    {
        return new ReminderService([
            'Leads' => ['rule_type' => 'fixed_days', 'value_days' => 0],
            'New/Captured' => ['rule_type' => 'fixed_days', 'value_days' => 3],
            'Researching' => ['rule_type' => 'fixed_days', 'value_days' => 7],
            'Relationship Building' => ['rule_type' => 'cadence_from_last_touch', 'value_days' => 30],
            'Opportunity Identified' => ['rule_type' => 'fixed_days', 'value_days' => 5],
            'Proposal / Pitch' => ['rule_type' => 'business_days', 'value_days' => 5],
            'Dormant' => ['rule_type' => 'fixed_days', 'value_days' => 180],
        ]);
    }

    private function date(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd);
    }

    public function testFixedDaysAddsFromReferenceDate(): void
    {
        $next = $this->service()->nextTouchDate('New/Captured', $this->date('2026-07-08'));

        $this->assertSame('2026-07-11', $next?->format('Y-m-d'));
    }

    public function testStatusWithNoRuleProducesNoReminder(): void
    {
        $next = $this->service()->nextTouchDate('Closed / Not a Fit', $this->date('2026-07-08'));

        $this->assertNull($next);
    }

    public function testCadenceCountsFromLastTouchNotReference(): void
    {
        $next = $this->service()->nextTouchDate(
            'Relationship Building',
            referenceDate: $this->date('2026-07-08'),
            lastTouchDate: $this->date('2026-07-01'),
        );

        $this->assertSame('2026-07-31', $next?->format('Y-m-d'));
    }

    public function testCadenceFallsBackToReferenceWhenNeverTouched(): void
    {
        $next = $this->service()->nextTouchDate(
            'Relationship Building',
            referenceDate: $this->date('2026-07-08'),
            lastTouchDate: null,
        );

        $this->assertSame('2026-08-07', $next?->format('Y-m-d'));
    }

    public function testPerContactOverrideBeatsRuleDefault(): void
    {
        $next = $this->service()->nextTouchDate(
            'Relationship Building',
            referenceDate: $this->date('2026-07-08'),
            lastTouchDate: $this->date('2026-07-01'),
            cadenceDaysOverride: 7,
        );

        $this->assertSame('2026-07-08', $next?->format('Y-m-d'));
    }

    public function testOverrideAppliesToFixedDayRulesToo(): void
    {
        $next = $this->service()->nextTouchDate(
            'New/Captured',
            referenceDate: $this->date('2026-07-08'),
            cadenceDaysOverride: 10,
        );

        $this->assertSame('2026-07-18', $next?->format('Y-m-d'));
    }

    public function testBusinessDaysSkipOneWeekend(): void
    {
        // Wed 2026-07-08 + 5 business days: Thu, Fri, Mon, Tue, Wed.
        $next = $this->service()->nextTouchDate('Proposal / Pitch', $this->date('2026-07-08'));

        $this->assertSame('2026-07-15', $next?->format('Y-m-d'));
    }

    public function testBusinessDaysFromFridayLandOnWeekday(): void
    {
        // Fri 2026-07-10 + 5 business days = Fri 2026-07-17.
        $next = $this->service()->nextTouchDate('Proposal / Pitch', $this->date('2026-07-10'));

        $this->assertSame('2026-07-17', $next?->format('Y-m-d'));
    }

    public function testBusinessDaysFromSaturdayStartCountingMonday(): void
    {
        // Sat 2026-07-11: the first business day added is Mon 2026-07-13,
        // so +5 lands Fri 2026-07-17.
        $next = $this->service()->nextTouchDate('Proposal / Pitch', $this->date('2026-07-11'));

        $this->assertSame('2026-07-17', $next?->format('Y-m-d'));
    }

    public function testStatusChangeUsesNewStatusRule(): void
    {
        // A contact moving Researching -> Opportunity Identified on 2026-07-08
        // gets the NEW status's 5-day rule, not Researching's 7.
        $next = $this->service()->nextTouchDate('Opportunity Identified', $this->date('2026-07-08'));

        $this->assertSame('2026-07-13', $next?->format('Y-m-d'));
    }

    public function testZeroDayCadenceIsDueSameDay(): void
    {
        // The Leads stage (2026-09-23): fixed_days 0 = follow up the same
        // day the contact enters the stage.
        $next = $this->service()->nextTouchDate('Leads', $this->date('2026-09-23'));

        $this->assertSame('2026-09-23', $next?->format('Y-m-d'));
    }

    public function testDormantLongCadence(): void
    {
        $next = $this->service()->nextTouchDate('Dormant', $this->date('2026-07-08'));

        $this->assertSame('2027-01-04', $next?->format('Y-m-d'));
    }
}
