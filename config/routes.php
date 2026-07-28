<?php

/**
 * Route table. Each route maps to [ControllerClass, method]; controllers are
 * constructed with (Config, Database, Auth) in public/index.php.
 * Routes NOT in the public list there require a logged-in session.
 */

declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\AuthController;
use App\Controllers\ContactController;
use App\Controllers\CronController;
use App\Controllers\DashboardController;
use App\Controllers\OrganizationController;
use App\Controllers\SettingsController;
use FastRoute\RouteCollector;

return static function (RouteCollector $r): void {
    // Auth (GET/POST sign-in routes are public — see $publicRoutes in public/index.php)
    $r->addRoute('GET', '/login', [AuthController::class, 'showLogin']);
    $r->addRoute('GET', '/auth/google', [AuthController::class, 'googleRedirect']);
    $r->addRoute('GET', '/auth/google/callback', [AuthController::class, 'googleCallback']);
    $r->addRoute('POST', '/auth/dev-login', [AuthController::class, 'devLogin']);
    $r->addRoute('POST', '/logout', [AuthController::class, 'logout']);

    // Dashboard
    $r->addRoute('GET', '/', [DashboardController::class, 'index']);

    // Contacts
    $r->addRoute('GET', '/contacts', [ContactController::class, 'index']);
    $r->addRoute('GET', '/contacts/new', [ContactController::class, 'create']);
    $r->addRoute('GET', '/contacts/trash', [ContactController::class, 'trashIndex']);
    $r->addRoute('POST', '/contacts', [ContactController::class, 'store']);
    $r->addRoute('POST', '/contacts/{id:\d+}/snooze', [ContactController::class, 'snooze']);
    $r->addRoute('POST', '/contacts/{id:\d+}/trash', [ContactController::class, 'trash']);
    $r->addRoute('POST', '/contacts/{id:\d+}/restore', [ContactController::class, 'restore']);
    $r->addRoute('GET', '/contacts/{id:\d+}', [ContactController::class, 'show']);
    $r->addRoute('GET', '/contacts/{id:\d+}/edit', [ContactController::class, 'edit']);
    $r->addRoute('POST', '/contacts/{id:\d+}', [ContactController::class, 'update']);

    // Activities (created from the contact page; editable in place since 2026-07-10)
    $r->addRoute('POST', '/contacts/{id:\d+}/activities', [ActivityController::class, 'store']);
    $r->addRoute('GET', '/activities/{id:\d+}/edit', [ActivityController::class, 'edit']);
    $r->addRoute('POST', '/activities/{id:\d+}', [ActivityController::class, 'update']);

    // Settings (cadence rules)
    $r->addRoute('GET', '/settings', [SettingsController::class, 'index']);
    $r->addRoute('POST', '/settings', [SettingsController::class, 'save']);
    $r->addRoute('POST', '/settings/reset', [SettingsController::class, 'reset']);

    // Scheduled jobs (public route, guarded by CRON_SECRET instead of a session)
    $r->addRoute(['GET', 'POST'], '/cron/daily-summary', [CronController::class, 'dailySummary']);

    // Organizations
    $r->addRoute('GET', '/organizations', [OrganizationController::class, 'index']);
    $r->addRoute('GET', '/organizations/new', [OrganizationController::class, 'create']);
    $r->addRoute('POST', '/organizations', [OrganizationController::class, 'store']);
    $r->addRoute('GET', '/organizations/{id:\d+}', [OrganizationController::class, 'show']);
    $r->addRoute('GET', '/organizations/{id:\d+}/edit', [OrganizationController::class, 'edit']);
    $r->addRoute('POST', '/organizations/{id:\d+}', [OrganizationController::class, 'update']);
};
