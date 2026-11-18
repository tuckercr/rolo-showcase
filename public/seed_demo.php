<?php

/**
 * Run the demo seed over HTTP — the shared-host twin of bin/seed_demo.php,
 * for the same reason public/migrate.php exists: an SFTP-only host has no
 * shell to run CLI scripts, and the database is only reachable from inside
 * the host's own network.
 *
 * Usage: https://crm.example.com/seed_demo.php?token=...&email=you@example.com
 *
 * Gated by the same MIGRATE_TOKEN as migrate.php (the operator's token).
 * Harmless on a live install: DemoSeeder refuses to run when the database
 * already has contacts.
 */

declare(strict_types=1);

use App\Support\Database;
use App\Support\DemoSeeder;

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

$email = $_GET['email'] ?? null;

try {
    $seeder = new DemoSeeder($config, new Database($config));
    echo $seeder->seed(is_string($email) && $email !== '' ? $email : null) . "\n";
} catch (RuntimeException $e) {
    http_response_code(409);
    echo $e->getMessage() . "\n";
    exit(1);
}
