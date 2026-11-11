<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DailySummaryService;
use App\Services\SlackApprovalNotifier;
use App\Services\SlackColdAlertService;
use App\Services\SlackDebriefService;
use App\Support\Mailer;
use App\Support\SlackClient;

/**
 * Endpoints for scheduled jobs. No session auth — callers present the
 * CRON_SECRET instead (Authorization: Bearer … or ?token=…).
 */
final class CronController extends Controller
{
    public function dailySummary(): string
    {
        if (!$this->tokenValid()) {
            http_response_code(403);
            return "Forbidden.\n";
        }

        header('Content-Type: text/plain; charset=utf-8');

        $service = new DailySummaryService($this->config, $this->db, new Mailer($this->config));

        $force = ($_GET['force'] ?? '') === '1';

        try {
            $out = $service->run($force) . "\n";
        } catch (\RuntimeException $e) {
            http_response_code(500);
            return 'Failed: ' . $e->getMessage() . "\n";
        }

        // Every tick also runs the other scheduled notices — each one gates
        // itself (send hour, weekday, dedupe), so the Cloudflare Worker can
        // stay a dumb heartbeat hitting this one endpoint 4x a day (13:00,
        // 14:00, 21:00, 22:00 UTC) with all scheduling policy living here.
        // The debrief is never forced from this endpoint; its own
        // /cron/slack-debrief?force=1 exists for manual tests.
        return $out
            . $this->announceApprovals()
            . $this->coldAlert($force)
            . $this->debrief();
    }

    private function debrief(): string
    {
        try {
            $service = new SlackDebriefService($this->config, $this->db, new SlackClient($this->config));

            return $service->run() . "\n";
        } catch (\RuntimeException $e) {
            // Slack being down must not fail the email tick.
            return 'debrief: failed: ' . $e->getMessage() . "\n";
        }
    }

    /**
     * The 5 PM Eastern evening debrief (double-fired in UTC like the daily
     * summary; the service turns the off-hour call into a no-op), plus a
     * second chance for any unannounced approvals.
     */
    public function slackDebrief(): string
    {
        if (!$this->tokenValid()) {
            http_response_code(403);
            return "Forbidden.\n";
        }

        header('Content-Type: text/plain; charset=utf-8');

        $service = new SlackDebriefService($this->config, $this->db, new SlackClient($this->config));

        $force = ($_GET['force'] ?? '') === '1';

        try {
            return $service->run($force) . "\n" . $this->announceApprovals() . $this->coldAlert($force);
        } catch (\RuntimeException $e) {
            http_response_code(500);
            return 'Failed: ' . $e->getMessage() . "\n";
        }
    }

    private function coldAlert(bool $force): string
    {
        try {
            $service = new SlackColdAlertService($this->config, $this->db, new SlackClient($this->config));

            return $service->run($force) . "\n";
        } catch (\RuntimeException $e) {
            // Slack being down must not fail the tick.
            return 'cold alert: failed: ' . $e->getMessage() . "\n";
        }
    }

    private function announceApprovals(): string
    {
        try {
            $notifier = new SlackApprovalNotifier($this->config, $this->db, new SlackClient($this->config));

            return $notifier->run() . "\n";
        } catch (\RuntimeException $e) {
            // Slack being down must not fail the email tick.
            return 'approvals: failed: ' . $e->getMessage() . "\n";
        }
    }

    private function tokenValid(): bool
    {
        $secret = $this->config->cronSecret;

        if ($secret === '') {
            return false;
        }

        // REDIRECT_ variant: Apache prefixes env vars set via mod_rewrite [E=…]
        // once the request has been internally redirected to index.php.
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');
        $bearer = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
        $token = $bearer !== '' ? $bearer : (string) ($_GET['token'] ?? '');

        return $token !== '' && hash_equals($secret, $token);
    }
}
