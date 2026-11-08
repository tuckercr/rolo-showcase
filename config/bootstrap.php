<?php

/**
 * One-time application bootstrap: autoloader, .env, timezone, error mode.
 * Returns the Config instance — the single source of configuration
 * everywhere else (no scattered getenv() calls, per CLAUDE.md).
 */

declare(strict_types=1);

use App\Support\Config;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// safeLoad(): a missing .env is fine as long as the variables exist in the
// real environment (e.g. CI) — required keys are validated in Config::fromEnv.
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

// $_ENV holds what phpdotenv loaded from .env. Real environment variables
// don't reliably appear there (variables_order has no E; the built-in web
// server exposes them only via getenv()), and phpdotenv never overwrites
// them — so they win. Enables e.g. DB_HOST=… php bin/migrate.php
$config = Config::fromEnv($_ENV + $_SERVER + getenv());

// Everything is stored and computed in UTC; APP_TIMEZONE is display-only
// (see CLAUDE.md "Dates & Time").
date_default_timezone_set('UTC');

error_reporting(E_ALL);
ini_set('display_errors', $config->isLocal() ? '1' : '0');
ini_set('log_errors', '1');

return $config;
