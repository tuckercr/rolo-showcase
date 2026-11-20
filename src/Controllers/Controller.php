<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\View;

abstract class Controller
{
    public function __construct(
        protected readonly Config $config,
        protected readonly Database $db,
        protected readonly Auth $auth,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = []): string
    {
        return View::render($template, $data + [
            'currentUser' => $this->auth->user(),
            'pendingProposals' => (new \App\Models\ProposalModel($this->db))->pendingCount(),
        ]);
    }

    protected function redirect(string $to): string
    {
        header('Location: ' . $to, true, 302);

        return '';
    }

    /**
     * Aborts the request with 403 unless the posted CSRF token is valid.
     * Call this first in every state-changing (POST) handler.
     */
    protected function requireValidCsrf(): void
    {
        if (!Csrf::verify($_POST['_token'] ?? null)) {
            http_response_code(403);
            echo 'Invalid or missing CSRF token. Go back, reload the page, and try again.';
            exit;
        }
    }

    /**
     * "Today" as Dana experiences it (APP_TIMEZONE), for date fields and
     * reminder math — not UTC "today", which flips a day early in the evening.
     */
    protected function todayLocal(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', $this->config->appTimezone);
    }

    /**
     * The follow-up lens: the signed-in user's id for "Mine" (their owned
     * contacts plus shared ones — see migration 0021), null for "Everyone".
     * Chosen with ?view=mine|everyone, remembered for the session, and
     * defaults to Mine. The contact page's follow-up queue reads it too so
     * "next in queue" always matches what the dashboard shows.
     */
    protected function followUpScope(): ?int
    {
        $view = $_GET['view'] ?? null;

        if ($view === 'mine' || $view === 'everyone') {
            $_SESSION['follow_up_view'] = $view;
        }

        return ($_SESSION['follow_up_view'] ?? 'mine') === 'mine' ? $this->auth->id() : null;
    }
}
