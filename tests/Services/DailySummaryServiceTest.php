<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\DailySummaryService;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DailySummaryServiceTest extends TestCase
{
    private function eastern(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('America/New_York'));
    }

    public function testBefore9amIsNotSendTime(): void
    {
        $this->assertFalse(DailySummaryService::isSendTime($this->eastern('2026-07-09 08:59:59')));
    }

    public function testFrom9amOnwardIsSendTime(): void
    {
        $this->assertTrue(DailySummaryService::isSendTime($this->eastern('2026-07-09 09:00:00')));
        $this->assertTrue(DailySummaryService::isSendTime($this->eastern('2026-07-09 13:45:00')));
    }

    /**
     * Renders the actual email template with fixture data and checks the
     * pieces the feature promises: the nudge link at the very top, both
     * follow-up sections, and yesterday's logged activity.
     */
    public function testEmailTemplateRendersAllSections(): void
    {
        $html = View::render('emails/daily_summary', [
            'appUrl' => 'https://crm.example.com',
            'overdue' => [[
                'id' => 7,
                'name' => 'Jane Doe',
                'organization_name' => 'Acme Biotech',
                'relationship_status' => 'Relationship Building',
                'next_touch_date' => '2026-07-01',
            ]],
            'dueToday' => [[
                'id' => 9,
                'name' => 'Sam Smith',
                'organization_name' => null,
                'relationship_status' => 'Intro Sent',
                'next_touch_date' => '2026-07-09',
            ]],
            'yesterdayActivities' => [[
                'contact_name' => 'Jane Doe',
                'activity_type' => 'call',
                'summary' => 'Discussed the proposal',
                'created_by_name' => 'Jessica',
                'attachment_count' => 2,
            ]],
            'activityTypes' => ['call' => 'Call'],
            'todayLabel' => 'Thursday, July 9',
            'yesterdayLabel' => 'Wednesday',
        ]);

        $this->assertStringContainsString('Have you logged what you did yesterday?', $html);
        $nudgePos = strpos($html, 'Have you logged what you did yesterday?');
        $overduePos = strpos($html, 'Overdue (1)');
        $this->assertNotFalse($nudgePos);
        $this->assertNotFalse($overduePos);
        $this->assertLessThan($overduePos, $nudgePos, 'Nudge link must sit above the sections.');

        $this->assertStringContainsString('https://crm.example.com/contacts/7', $html);
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Due today (1)', $html);
        $this->assertStringContainsString('Sam Smith', $html);
        $this->assertStringContainsString('Logged Wednesday (1)', $html);
        $this->assertStringContainsString('Discussed the proposal', $html);
        $this->assertStringContainsString('2 attachments', $html);
        $this->assertStringContainsString('Jessica', $html);
    }

    public function testEmailTemplateEmptyStatesNudgeTowardLogging(): void
    {
        $html = View::render('emails/daily_summary', [
            'appUrl' => 'https://crm.example.com',
            'overdue' => [],
            'dueToday' => [],
            'yesterdayActivities' => [],
            'activityTypes' => [],
            'todayLabel' => 'Thursday, July 9',
            'yesterdayLabel' => 'Wednesday',
        ]);

        $this->assertStringContainsString('Nothing overdue', $html);
        $this->assertStringContainsString('Nothing due today', $html);
        $this->assertStringContainsString('No interactions were logged yesterday', $html);
    }

    public function testEmailEscapesUserContent(): void
    {
        $html = View::render('emails/daily_summary', [
            'appUrl' => 'https://crm.example.com',
            'overdue' => [[
                'id' => 1,
                'name' => '<script>alert(1)</script>',
                'organization_name' => null,
                'relationship_status' => 'Researching',
                'next_touch_date' => '2026-07-01',
            ]],
            'dueToday' => [],
            'yesterdayActivities' => [],
            'activityTypes' => [],
            'todayLabel' => 'Thursday, July 9',
            'yesterdayLabel' => 'Wednesday',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
