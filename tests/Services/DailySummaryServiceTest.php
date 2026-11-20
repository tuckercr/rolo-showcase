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

    public function testWeekendsAreNotSendTime(): void
    {
        // 2026-07-11 is a Saturday, 2026-07-12 a Sunday, 2026-07-13 a Monday.
        $this->assertFalse(DailySummaryService::isSendTime($this->eastern('2026-07-11 09:30:00')));
        $this->assertFalse(DailySummaryService::isSendTime($this->eastern('2026-07-12 09:30:00')));
        $this->assertTrue(DailySummaryService::isSendTime($this->eastern('2026-07-13 09:30:00')));
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
                'contact_id' => 7,
                'contact_name' => 'Jane Doe',
                'activity_type' => 'call',
                'summary' => 'Discussed the proposal',
                'created_by_name' => 'Dana',
                'attachment_count' => 2,
            ]],
            'activityTypes' => ['call' => 'Call'],
            'todayLabel' => 'Thursday, July 9',
            'yesterdayLabel' => 'Wednesday',
            'photoCids' => [7 => 'photo-c7'],
            'goingColdCount' => 3,
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
        $dueTodayPos = strpos($html, 'Due today (1)');
        $this->assertLessThan($overduePos, $dueTodayPos, 'Due today must sit above Overdue.');
        $this->assertStringContainsString('Sam Smith', $html);
        $this->assertStringContainsString('Logged Wednesday (1)', $html);
        $this->assertStringContainsString('Discussed the proposal', $html);
        $this->assertStringContainsString('2 attachments', $html);
        $this->assertStringContainsString('Dana', $html);

        // Jane (id 7) has an embedded photo; Sam falls back to initials.
        $this->assertStringContainsString('cid:photo-c7', $html);
        $this->assertStringContainsString('>SS</span>', $html);
        $this->assertStringContainsString('Plus 3 going cold', $html);
    }

    public function testEmailShowsPendingApprovalsBannerAboveEverything(): void
    {
        $base = [
            'appUrl' => 'https://crm.example.com',
            'overdue' => [],
            'dueToday' => [],
            'yesterdayActivities' => [],
            'activityTypes' => [],
            'todayLabel' => 'Thursday, July 9',
            'yesterdayLabel' => 'Wednesday',
            'photoCids' => [],
        ];

        $html = View::render('emails/daily_summary', $base + ['pendingApprovals' => 10]);
        $this->assertStringContainsString('approvals outstanding', $html);
        $this->assertStringContainsString('>10</strong>', $html);
        $this->assertStringContainsString('https://crm.example.com/proposals', $html);
        $bannerPos = strpos($html, 'approvals outstanding');
        $nudgePos = strpos($html, 'Have you logged what you did yesterday?');
        $this->assertLessThan($nudgePos, $bannerPos, 'Approvals banner must sit at the very top.');

        $singular = View::render('emails/daily_summary', $base + ['pendingApprovals' => 1]);
        $this->assertStringContainsString('approval outstanding', $singular);
        $this->assertStringNotContainsString('approvals outstanding', $singular);

        // No banner at all when the approvals queue is empty.
        $none = View::render('emails/daily_summary', $base + ['pendingApprovals' => 0]);
        $this->assertStringNotContainsString('outstanding', $none);
        $this->assertStringNotContainsString('/proposals', $none);
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
            'photoCids' => [7 => 'photo-c7'],
        ]);

        $this->assertStringContainsString('Nothing overdue', $html);
        $this->assertStringContainsString('No interactions were logged yesterday', $html);

        // An empty Due today section is hidden entirely, not shown as empty.
        $this->assertStringNotContainsString('Due today', $html);
        $this->assertStringNotContainsString('going cold', $html);
    }

    public function testHasNewsSkipsOnlyTrulyEmptyEmails(): void
    {
        $empty = [
            'overdue' => [],
            'dueToday' => [],
            'yesterdayActivities' => [],
            'pendingApprovals' => 0,
            'goingColdCount' => 5,
        ];

        self::assertFalse(
            DailySummaryService::hasNews($empty),
            'going-cold alone is a standing condition, not daily news',
        );
        self::assertTrue(DailySummaryService::hasNews(['overdue' => [['id' => 1]]] + $empty));
        self::assertTrue(DailySummaryService::hasNews(['dueToday' => [['id' => 1]]] + $empty));
        self::assertTrue(DailySummaryService::hasNews(['yesterdayActivities' => [['id' => 1]]] + $empty));
        self::assertTrue(DailySummaryService::hasNews(['pendingApprovals' => 2] + $empty));
        self::assertTrue(
            DailySummaryService::hasNews(['tasks' => [['id' => 1]]] + $empty),
            'a task due today is news even with no follow-ups',
        );
    }

    public function testSubjectMentionsTasksAndApprovalsOnlyWhenThereAreSome(): void
    {
        $now = $this->eastern('2026-11-20 09:00:00');
        $data = ['overdue' => [['id' => 1]], 'dueToday' => [], 'tasks' => [], 'pendingApprovals' => 0];

        self::assertSame('Rolo: 1 overdue, 0 due today (Fri Nov 20)', DailySummaryService::subject($data, $now));
        self::assertSame(
            'Rolo: 1 overdue, 0 due today, 1 task (Fri Nov 20)',
            DailySummaryService::subject(['tasks' => [['id' => 1]]] + $data, $now),
        );
        self::assertSame(
            'Rolo: 1 overdue, 0 due today, 2 tasks, 3 to approve (Fri Nov 20)',
            DailySummaryService::subject(
                ['tasks' => [['id' => 1], ['id' => 2]], 'pendingApprovals' => 3] + $data,
                $now,
            ),
        );
    }

    public function testEmailRendersTheTasksBlockOnlyWhenThereAreTasks(): void
    {
        $base = [
            'appUrl' => 'https://crm.example.com',
            'overdue' => [],
            'dueToday' => [],
            'yesterdayActivities' => [],
            'activityTypes' => [],
            'todayLabel' => 'Friday, November 20',
            'yesterdayLabel' => 'Thursday',
            'photoCids' => [],
            'lensLabel' => '',
        ];

        self::assertStringNotContainsString('asks (', View::render('emails/daily_summary', $base));

        $html = View::render('emails/daily_summary', $base + ['tasks' => [
            [
                'id' => 4,
                'title' => 'Send <b>Q1</b> proposal',
                'contact_id' => 7,
                'contact_name' => 'Maria Santos',
                'organization_name' => 'Lakeview University',
                'due_label' => 'due Nov 18 (2 days late)',
                'is_overdue' => true,
            ],
            [
                'id' => 5,
                'title' => 'Renew the contract',
                'contact_id' => null,
                'contact_name' => null,
                'organization_name' => 'Northfield Labs',
                'due_label' => 'due today',
                'is_overdue' => false,
            ],
        ]]);

        self::assertStringContainsString('Tasks (2)', $html);
        self::assertStringNotContainsString('Your tasks', $html, 'the everyone view is not "yours"');
        self::assertStringContainsString(
            'Your tasks (1)',
            View::render('emails/daily_summary', ['lensLabel' => 'Your follow-ups and tasks.', 'tasks' => [[
                'id' => 4,
                'title' => 'Mine',
                'contact_id' => null,
                'contact_name' => null,
                'organization_name' => null,
                'due_label' => 'due today',
                'is_overdue' => false,
            ]]] + $base),
        );
        self::assertStringContainsString('Send &lt;b&gt;Q1&lt;/b&gt; proposal', $html, 'titles are escaped');
        self::assertStringContainsString('https://crm.example.com/contacts/7', $html);
        self::assertStringContainsString('Maria Santos', $html);
        self::assertStringContainsString('due Nov 18 (2 days late)', $html);
        self::assertStringContainsString('Northfield Labs', $html);
        self::assertLessThan(
            strpos($html, 'Overdue ('),
            strpos($html, 'Tasks (2)'),
            'tasks come before the follow-up sections',
        );
        self::assertStringNotContainsString("\u{2014}", $html, 'no em dashes in user-facing copy');
    }

    public function testEmailShowsTheLensLabelOnlyWhenPersonalized(): void
    {
        $base = [
            'appUrl' => 'https://crm.example.com',
            'overdue' => [],
            'dueToday' => [],
            'yesterdayActivities' => [],
            'activityTypes' => [],
            'todayLabel' => 'Thursday, July 9',
            'yesterdayLabel' => 'Wednesday',
            'photoCids' => [],
        ];

        $personal = View::render('emails/daily_summary', $base + [
            'lensLabel' => 'Your follow-ups and tasks: the ones you own plus shared ones.',
        ]);
        self::assertStringContainsString('the ones you own plus shared ones', $personal);

        $combined = View::render('emails/daily_summary', $base + ['lensLabel' => '']);
        self::assertStringNotContainsString('the ones you own', $combined);
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
            'photoCids' => [7 => 'photo-c7'],
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
