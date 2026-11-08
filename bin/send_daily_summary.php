<?php

/**
 * Send the daily summary email: php bin/send_daily_summary.php [--force]
 *
 * Without --force it only sends after 9 AM Eastern and at most once per day
 * (same rules as the /cron/daily-summary endpoint). --force sends now,
 * regardless — handy for testing the email itself.
 */

declare(strict_types=1);

use App\Services\DailySummaryService;
use App\Support\Database;
use App\Support\Mailer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$config = require dirname(__DIR__) . '/config/bootstrap.php';

$force = in_array('--force', $argv, true);

$database = new Database($config);
$service = new DailySummaryService($config, $database, new Mailer($config));

try {
    echo $service->run($force) . "\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
