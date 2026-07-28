<?php

/**
 * Apply pending database migrations: php bin/migrate.php
 * Uses the DB_* credentials from .env — point them at the production database to
 * migrate production, or at a local MariaDB for development.
 */

declare(strict_types=1);

use App\Support\Database;
use App\Support\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$config = require dirname(__DIR__) . '/config/bootstrap.php';

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
