<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

/**
 * Read-only Google Calendar access (per-user opt-in from /settings).
 *
 * Reuses the Google Sign-In OAuth client, with its own redirect URI
 * ({APP_URL}/settings/calendar/callback — must be added to the client's
 * authorized redirect URIs in the GCP console, and the Calendar API enabled).
 * The connect flow asks for offline access with prompt=consent so Google
 * always returns a refresh token, which is the only thing we keep.
 *
 * The HTTP pieces are thin; the event-to-contact matching is a pure static
 * method so the subtle part is unit-tested without a network.
 */
final class GoogleCalendarService
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const EVENTS_ENDPOINT = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';

    public function __construct(private readonly Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config->googleClientId !== ''
            && $this->config->googleClientSecret !== ''
            && $this->config->appUrl !== '';
    }

    public function redirectUri(): string
    {
        return $this->config->appUrl . '/settings/calendar/callback';
    }

    public function connectUrl(string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->config->googleClientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email ' . self::CALENDAR_SCOPE,
            // offline + consent guarantees a refresh token, including on
            // reconnect (without consent Google only issues one the first time).
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * Exchange the authorization code for the refresh token we keep, plus
     * the calendar account's identity and the scopes actually granted (the
     * consent screen lets people untick the calendar checkbox).
     *
     * @return array{email: string, refresh_token: string, scope: string}
     */
    public function exchangeCode(string $code): array
    {
        $body = $this->postToken([
            'code' => $code,
            'client_id' => $this->config->googleClientId,
            'client_secret' => $this->config->googleClientSecret,
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (!is_string($body['refresh_token'] ?? null) || $body['refresh_token'] === '') {
            throw new RuntimeException('Google did not return a refresh token.');
        }

        $claims = GoogleAuthService::decodeIdTokenClaims((string) ($body['id_token'] ?? ''));
        GoogleAuthService::validateClaims($claims, $this->config->googleClientId, time());

        return [
            'email' => strtolower((string) $claims['email']),
            'refresh_token' => $body['refresh_token'],
            'scope' => (string) ($body['scope'] ?? ''),
        ];
    }

    public function accessToken(string $refreshToken): string
    {
        $body = $this->postToken([
            'refresh_token' => $refreshToken,
            'client_id' => $this->config->googleClientId,
            'client_secret' => $this->config->googleClientSecret,
            'grant_type' => 'refresh_token',
        ]);

        if (!is_string($body['access_token'] ?? null) || $body['access_token'] === '') {
            throw new RuntimeException('Google token refresh returned no access token.');
        }

        return $body['access_token'];
    }

    /**
     * The primary calendar's events for one local day (recurring events
     * expanded to instances).
     *
     * @return list<array<string, mixed>> raw Google event resources
     */
    public function eventsForDay(string $accessToken, string $day): array
    {
        $start = new DateTimeImmutable($day . ' 00:00:00', $this->config->appTimezone);

        $url = self::EVENTS_ENDPOINT . '?' . http_build_query([
            'timeMin' => $start->format(DateTimeInterface::RFC3339),
            'timeMax' => $start->modify('+1 day')->format(DateTimeInterface::RFC3339),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => '50',
        ]);

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($response) || $status !== 200) {
            throw new RuntimeException(sprintf('Google Calendar events fetch failed (HTTP %d).', $status));
        }

        $body = json_decode($response, true);
        $items = is_array($body) ? ($body['items'] ?? []) : [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * Every attendee email in the day's events (the candidate list for one
     * contact lookup), lowercased and deduplicated.
     *
     * @param list<array<string, mixed>> $events
     * @return list<string>
     */
    public static function attendeeEmails(array $events): array
    {
        $emails = [];

        foreach ($events as $event) {
            foreach (is_array($event['attendees'] ?? null) ? $event['attendees'] : [] as $attendee) {
                $email = strtolower(trim((string) ($attendee['email'] ?? '')));

                if ($email !== '') {
                    $emails[$email] = true;
                }
            }
        }

        return array_keys($emails);
    }

    /**
     * Which contacts did these events put you in a room with? Timed events
     * only (all-day entries are birthdays and OOO blocks, not meetings),
     * skipping cancelled events, ones you declined, meeting-room "attendees"
     * and yourself — then one suggestion per contact, first event wins.
     *
     * @param list<array<string, mixed>> $events raw Google event resources
     * @param array<string, array<string, mixed>> $contactsByEmail lowercase email => contact
     * @return list<array{contact: array<string, mixed>, title: string, time: ?string}>
     */
    public static function matchEvents(
        array $events,
        array $contactsByEmail,
        string $selfEmail,
        DateTimeZone $timezone,
    ): array {
        $selfEmail = strtolower($selfEmail);
        $suggestions = [];
        $seenContactIds = [];

        foreach ($events as $event) {
            if ((string) ($event['status'] ?? '') === 'cancelled') {
                continue;
            }

            $startRaw = $event['start']['dateTime'] ?? null;

            if (!is_string($startRaw) || $startRaw === '') {
                continue;
            }

            $attendees = is_array($event['attendees'] ?? null) ? $event['attendees'] : [];

            if (self::declinedBySelf($attendees, $selfEmail)) {
                continue;
            }

            try {
                $time = (new DateTimeImmutable($startRaw))->setTimezone($timezone)->format('g:i A');
            } catch (\Exception) {
                $time = null;
            }

            foreach ($attendees as $attendee) {
                $email = strtolower(trim((string) ($attendee['email'] ?? '')));

                if ($email === '' || $email === $selfEmail || ($attendee['resource'] ?? false) === true) {
                    continue;
                }

                $contact = $contactsByEmail[$email] ?? null;

                if ($contact === null || isset($seenContactIds[(int) $contact['id']])) {
                    continue;
                }

                $seenContactIds[(int) $contact['id']] = true;
                $suggestions[] = [
                    'contact' => $contact,
                    'title' => trim((string) ($event['summary'] ?? '')),
                    'time' => $time,
                ];
            }
        }

        return $suggestions;
    }

    /**
     * @param list<array<string, mixed>> $attendees
     */
    private static function declinedBySelf(array $attendees, string $selfEmail): bool
    {
        foreach ($attendees as $attendee) {
            $isSelf = ($attendee['self'] ?? false) === true
                || strtolower(trim((string) ($attendee['email'] ?? ''))) === $selfEmail;

            if ($isSelf && (string) ($attendee['responseStatus'] ?? '') === 'declined') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function postToken(array $fields): array
    {
        $curl = curl_init(self::TOKEN_ENDPOINT);

        if ($curl === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($response) || $status !== 200) {
            throw new RuntimeException(sprintf('Google token request failed (HTTP %d).', $status));
        }

        $body = json_decode($response, true);

        if (!is_array($body)) {
            throw new RuntimeException('Google token response is not valid JSON.');
        }

        return $body;
    }
}
