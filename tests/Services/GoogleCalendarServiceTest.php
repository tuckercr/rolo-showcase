<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\GoogleCalendarService;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The event-to-contact matcher is pure, so the subtle calendar rules (what
 * counts as a meeting you had) are tested without any Google.
 */
final class GoogleCalendarServiceTest extends TestCase
{
    private const SELF = 'colin@example.com';

    private DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->tz = new DateTimeZone('America/New_York');
    }

    /**
     * @param list<array<string, mixed>> $attendees
     * @return array<string, mixed>
     */
    private function event(string $title, string $startUtc, array $attendees, string $status = 'confirmed'): array
    {
        return [
            'status' => $status,
            'summary' => $title,
            'start' => ['dateTime' => $startUtc],
            'attendees' => $attendees,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contacts(): array
    {
        return [
            'brad@ethia.com' => ['id' => 7, 'name' => 'Brad Day', 'organization_name' => 'ETHIA'],
            'sue@acme.com' => ['id' => 9, 'name' => 'Sue Storm', 'organization_name' => 'Acme'],
        ];
    }

    public function testMatchesAttendeesToContactsWithLocalTime(): void
    {
        $events = [$this->event('ETHIA brief review', '2026-11-17T19:00:00Z', [
            ['email' => self::SELF, 'self' => true, 'responseStatus' => 'accepted'],
            ['email' => 'Brad@ETHIA.com', 'responseStatus' => 'accepted'],
        ])];

        $matched = GoogleCalendarService::matchEvents($events, $this->contacts(), self::SELF, $this->tz);

        self::assertCount(1, $matched);
        self::assertSame(7, $matched[0]['contact']['id']);
        self::assertSame('ETHIA brief review', $matched[0]['title']);
        self::assertSame('2:00 PM', $matched[0]['time'], '19:00 UTC is 2 PM Eastern in November');
    }

    public function testSkipsWhatIsNotAMeetingYouHad(): void
    {
        $events = [
            // All-day entries (birthdays, OOO) have no dateTime.
            [
                'status' => 'confirmed',
                'summary' => 'Brad birthday',
                'start' => ['date' => '2026-11-17'],
                'attendees' => [['email' => 'brad@ethia.com']],
            ],
            $this->event('Cancelled sync', '2026-11-17T15:00:00Z', [
                ['email' => 'brad@ethia.com'],
            ], status: 'cancelled'),
            $this->event('Declined pitch', '2026-11-17T16:00:00Z', [
                ['email' => self::SELF, 'self' => true, 'responseStatus' => 'declined'],
                ['email' => 'brad@ethia.com', 'responseStatus' => 'accepted'],
            ]),
            $this->event('Room only', '2026-11-17T17:00:00Z', [
                ['email' => self::SELF, 'self' => true],
                ['email' => 'room-4@resource.calendar.google.com', 'resource' => true],
            ]),
            $this->event('Strangers only', '2026-11-17T18:00:00Z', [
                ['email' => self::SELF, 'self' => true],
                ['email' => 'nobody@unknown.com'],
            ]),
        ];

        self::assertSame(
            [],
            GoogleCalendarService::matchEvents($events, $this->contacts(), self::SELF, $this->tz),
        );
    }

    public function testOneSuggestionPerContactFirstEventWins(): void
    {
        $events = [
            $this->event('Morning stand-up', '2026-11-17T14:00:00Z', [
                ['email' => 'brad@ethia.com'],
                ['email' => 'sue@acme.com'],
            ]),
            $this->event('Afternoon recap', '2026-11-17T21:00:00Z', [
                ['email' => 'brad@ethia.com'],
            ]),
        ];

        $matched = GoogleCalendarService::matchEvents($events, $this->contacts(), self::SELF, $this->tz);

        self::assertCount(2, $matched, 'Brad once, Sue once');
        self::assertSame('Morning stand-up', $matched[0]['title']);
        self::assertSame('Morning stand-up', $matched[1]['title']);
    }

    public function testAttendeeEmailsCollectsLowercasedUniqueEmails(): void
    {
        $events = [
            $this->event('A', '2026-11-17T14:00:00Z', [
                ['email' => 'Brad@ETHIA.com'],
                ['email' => 'sue@acme.com'],
            ]),
            $this->event('B', '2026-11-17T15:00:00Z', [
                ['email' => 'brad@ethia.com'],
                ['email' => ''],
            ]),
        ];

        self::assertSame(
            ['brad@ethia.com', 'sue@acme.com'],
            GoogleCalendarService::attendeeEmails($events),
        );
    }
}
