<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityModel;
use App\Models\CalendarConnectionModel;
use App\Models\ContactModel;
use App\Support\Config;
use App\Support\Database;

/**
 * "You met these contacts today" for the evening debrief: one user's
 * calendar day, matched to contacts by attendee email, minus anyone who
 * already has an interaction logged today (no nagging about done work).
 *
 * Not final so SlackDebriefService tests can stub suggestionsFor() instead
 * of standing up a fake Google (the same reason SlackClient stays open).
 */
class CalendarSuggestionService
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
    ) {
    }

    /**
     * One calendar day's matched meetings. $skipAlreadyLogged drops contacts
     * who already have an interaction logged on $day — right for today and
     * yesterday (no nagging about done work), wrong for tomorrow's prep
     * view, where the meeting hasn't happened yet.
     *
     * @return list<array{contact: array<string, mixed>, title: string, time: ?string, start?: string}>
     */
    public function suggestionsFor(?int $userId, string $day, bool $skipAlreadyLogged = true): array
    {
        if ($userId === null) {
            return [];
        }

        $connection = (new CalendarConnectionModel($this->db))->findForUser($userId);

        if ($connection === null) {
            return [];
        }

        $google = new GoogleCalendarService($this->config);
        $accessToken = $google->accessToken((string) $connection['refresh_token']);
        $events = $google->eventsForDay($accessToken, $day);

        $emails = GoogleCalendarService::attendeeEmails($events);

        if ($emails === []) {
            return [];
        }

        $matched = GoogleCalendarService::matchEvents(
            $events,
            (new ContactModel($this->db))->activeByEmails($emails),
            (string) $connection['google_email'],
            $this->config->appTimezone,
        );

        if ($matched === [] || !$skipAlreadyLogged) {
            return $matched;
        }

        $logged = [];

        foreach ((new ActivityModel($this->db))->onDate($day) as $activity) {
            if ((string) $activity['activity_type'] !== 'snoozed') {
                $logged[(int) $activity['contact_id']] = true;
            }
        }

        return array_values(array_filter(
            $matched,
            static fn(array $s): bool => !isset($logged[(int) $s['contact']['id']]),
        ));
    }
}
