<?php

/**
 * Seed a FRESH Rolo database with a fictional consultancy, so a new install
 * has something to look at: php bin/seed_demo.php [you@example.com]
 *
 * Pass your own email address to take over the first seeded user, so Google
 * Sign-In (which matches on the users table) lets you straight in.
 *
 * The data and the safety guard live in src/Support/DemoSeeder.php (shared
 * with public/seed_demo.php, the HTTP twin for shared hosts with no shell).
 * Full setup:
 *
 *   cp .env.example .env   # fill in DB_* (and later GOOGLE_*)
 *   composer install
 *   php bin/migrate.php
 *   php bin/seed_demo.php you@example.com
 */

declare(strict_types=1);

use App\Support\Database;
use App\Support\DemoSeeder;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$config = require dirname(__DIR__) . '/config/bootstrap.php';

try {
    $seeder = new DemoSeeder($config, new Database($config));
    echo $seeder->seed($argv[1] ?? null) . "\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
