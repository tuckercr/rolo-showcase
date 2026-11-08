<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DailySummaryService;
use App\Support\Mailer;

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
            return $service->run($force) . "\n";
        } catch (\RuntimeException $e) {
            http_response_code(500);
            return 'Failed: ' . $e->getMessage() . "\n";
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
