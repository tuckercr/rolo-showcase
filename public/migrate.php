<?php

/**
 * Run pending database migrations over HTTP.
 *
 * Why this exists: on shared hosting the database is often unreachable from
 * outside the host's network, and the deploy account may have no shell — so
 * bin/migrate.php can't be run remotely. This endpoint runs the same
 * Migrator on the server itself, gated by a long random token
 * (MIGRATE_TOKEN in the server's .env — never committed, never the same as
 * any other secret in this app).
 *
 * Usage: https://your-app.example.com/migrate.php?token=...
 *
 * Safe to hit repeatedly — migrations are forward-only and tracked in the
 * `migrations` table (see src/Support/Migrator.php); anything already
 * applied is skipped.
 */

declare(strict_types=1);

use App\Support\Database;
use App\Support\Migrator;

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
} catch (\Throwable $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
