<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\ProposalService;
use PHPUnit\Framework\TestCase;

final class ProposalServiceTest extends TestCase
{
    public function testFirstEverTouchAdvances(): void
    {
        self::assertTrue(ProposalService::advancesLastTouch(null, '2026-09-10'));
        self::assertTrue(ProposalService::advancesLastTouch('', '2026-09-10'));
    }

    public function testNewerTouchAdvances(): void
    {
        self::assertTrue(ProposalService::advancesLastTouch('2026-09-10', '2026-09-22'));
    }

    public function testSameDayTouchAdvances(): void
    {
        self::assertTrue(ProposalService::advancesLastTouch('2026-09-22', '2026-09-22'));
    }

    /**
     * The out-of-order approval case: a Sep 10 meeting approved AFTER a
     * Sep 22 call must not reschedule, or a fixed_days stage would compute
     * next touch from Sep 10 and drag the follow-up into the past.
     */
    public function testBackdatedTouchApprovedLateDoesNotAdvance(): void
    {
        self::assertFalse(ProposalService::advancesLastTouch('2026-09-22', '2026-09-10'));
    }
}
