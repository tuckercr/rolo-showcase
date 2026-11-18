<?php

/**
 * Run pending database migrations over HTTP.
 *
 * Why this exists: the IONOS MariaDB host (db5020832313.hosting-data.io) has
 * no public DNS record — confirmed via `dig`/Google's public DNS returning
 * NXDOMAIN — so it's only reachable from inside IONOS's own network. The
 * SFTP deploy account has no shell, so bin/migrate.php can never be run
 * remotely. This endpoint runs the same Migrator on the server itself,
 * gated by a long random token (MIGRATE_TOKEN in the server's .env — never
 * committed, never the same as any other secret in this app).
 *
 * Usage: https://crm.example.com/migrate.php?token=...
 *
 * Safe to hit repeatedly — migrations are forward-only and tracked in the
 * `migrations` table (see src/Support/Migrator.php); anything already
 * applied is skipped.
 */

declare(strict_types=1);

use App\Services\SlackOpsNotifier;
use App\Support\Database;
use App\Support\Migrator;
use App\Support\SlackClient;

$config = require dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

if ($config->migrateToken === '') {
    http_response_code(500);
    echo "MIGRATE_TOKEN is not set on the server — refusing to run.\n";
    exit(1);
}

$token = $_GET['token'] ?? '';

if (!is_string($token) || !hash_equals($config->migrateToken, $token)) {
    http_response_code(403);
    echo "Forbidden.\n";
    exit(1);
}

// The DM is informational; a Slack hiccup must never change this
// endpoint's output or exit code, which bin/deploy.sh acts on.
$opsNotice = static function (string $text) use ($config): void {
    try {
        (new SlackOpsNotifier($config, new SlackClient($config)))->notify($text);
    } catch (\Throwable) {
        // Nothing — the migration outcome is already on stdout.
    }
};

try {
    $database = new Database($config);
    $migrator = new Migrator($database->pdo(), dirname(__DIR__) . '/database/migrations');

    $ran = $migrator->migrate();

    if ($ran === []) {
        echo "Nothing to migrate — database is up to date.\n";
        exit(0);
    }

    foreach ($ran as $filename) {
        echo "Applied: {$filename}\n";
    }

    echo count($ran) . " migration(s) applied.\n";

    $opsNotice(sprintf(
        ':card_file_box: Database migrated (%d): %s',
        count($ran),
        implode(', ', $ran),
    ));
} catch (\Throwable $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
    $opsNotice(':rotating_light: Migration FAILED: ' . $e->getMessage()
        . ' (production may be serving code ahead of its schema)');
    exit(1);
}
