<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CalendarConnectionModel;
use App\Services\GoogleCalendarService;
use RuntimeException;

/**
 * Connect / disconnect Google Calendar from /settings. Session-gated like
 * the rest of the app; the OAuth state nonce rides the session exactly as
 * the sign-in flow's does. Outcomes land back on /settings?calendar=…
 */
final class CalendarController extends Controller
{
    public function connect(): string
    {
        $google = new GoogleCalendarService($this->config);

        if (!$google->isConfigured()) {
            return $this->redirect('/settings?calendar=not_configured');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['calendar_oauth_state'] = $state;

        return $this->redirect($google->connectUrl($state));
    }

    public function callback(): string
    {
        $expectedState = $_SESSION['calendar_oauth_state'] ?? null;
        unset($_SESSION['calendar_oauth_state']);

        $state = (string) ($_GET['state'] ?? '');
        $code = (string) ($_GET['code'] ?? '');

        if (
            !is_string($expectedState)
            || $expectedState === ''
            || !hash_equals($expectedState, $state)
            || $code === ''
        ) {
            // Covers a denied consent screen too (Google sends error=, no code).
            return $this->redirect('/settings?calendar=failed');
        }

        $google = new GoogleCalendarService($this->config);

        try {
            $granted = $google->exchangeCode($code);
        } catch (RuntimeException) {
            return $this->redirect('/settings?calendar=failed');
        }

        // The consent screen lets people untick the calendar checkbox; a
        // token without the scope would connect something that can never work.
        if (!str_contains($granted['scope'], GoogleCalendarService::CALENDAR_SCOPE)) {
            return $this->redirect('/settings?calendar=no_scope');
        }

        (new CalendarConnectionModel($this->db))->save(
            $this->auth->id(),
            $granted['email'],
            $granted['refresh_token'],
        );

        return $this->redirect('/settings?calendar=connected');
    }

    public function disconnect(): string
    {
        $this->requireValidCsrf();

        (new CalendarConnectionModel($this->db))->delete($this->auth->id());

        return $this->redirect('/settings?calendar=disconnected');
    }
}
