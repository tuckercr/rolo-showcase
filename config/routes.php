<?php

/**
 * Route table. Each route maps to [ControllerClass, method]; controllers are
 * constructed with (Config, Database, Auth) in public/index.php.
 * Routes NOT in the public list there require a logged-in session.
 */

declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\Api\CrmApiController;
use App\Controllers\Api\McpController;
use App\Controllers\Api\ProposalApiController;
use App\Controllers\ApprovalController;
use App\Controllers\AttachmentController;
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
    $r->addRoute('POST', '/activities/{id:\d+}/delete', [ActivityController::class, 'delete']);

    // Attachments (files live outside the web root; this is the only way in)
    $r->addRoute('GET', '/attachments', [AttachmentController::class, 'index']);
    $r->addRoute('GET', '/attachments/{id:\d+}', [AttachmentController::class, 'download']);
    $r->addRoute('POST', '/attachments/{id:\d+}/delete', [AttachmentController::class, 'delete']);

    // Settings (cadence rules)
    $r->addRoute('GET', '/settings', [SettingsController::class, 'index']);
    $r->addRoute('POST', '/settings', [SettingsController::class, 'save']);
    $r->addRoute('POST', '/settings/reset', [SettingsController::class, 'reset']);

    // Agent API (read-only, piece 1) — bearer-token auth inside the
    // controllers, no session; /api/* bypasses the session gate in index.php
    $r->addRoute('GET', '/api/v1/contacts', [CrmApiController::class, 'contactsIndex']);
    $r->addRoute('GET', '/api/v1/contacts/{id:\d+}', [CrmApiController::class, 'contactsShow']);
    $r->addRoute('GET', '/api/v1/organizations', [CrmApiController::class, 'organizationsIndex']);
    $r->addRoute('GET', '/api/v1/organizations/{id:\d+}', [CrmApiController::class, 'organizationsShow']);
    $r->addRoute('GET', '/api/v1/stages', [CrmApiController::class, 'stagesIndex']);
    $r->addRoute('GET', '/api/v1/activities', [CrmApiController::class, 'activitiesIndex']);
    $r->addRoute('GET', '/api/v1/tags', [CrmApiController::class, 'tagsIndex']);

    // Agent API writes (piece 2): every call queues a proposal for human
    // review at /proposals; nothing writes to the CRM directly
    $r->addRoute('POST', '/api/v1/contacts', [ProposalApiController::class, 'proposeCreateContact']);
    $r->addRoute('POST', '/api/v1/organizations', [ProposalApiController::class, 'proposeCreateOrganization']);
    $r->addRoute('PATCH', '/api/v1/contacts/{id:\d+}', [ProposalApiController::class, 'proposeUpdateContact']);
    $r->addRoute('POST', '/api/v1/contacts/{id:\d+}/tags', [ProposalApiController::class, 'proposeTagChanges']);
    $r->addRoute(
        'POST',
        '/api/v1/contacts/{id:\d+}/activities',
        [ProposalApiController::class, 'proposeLogActivity'],
    );
    $r->addRoute('GET', '/api/v1/proposals/{id:\d+}', [ProposalApiController::class, 'show']);

    // MCP endpoint: the same agent tools spoken over the Model Context
    // Protocol (streamable HTTP, stateless) for MCP clients like
    // Superhuman Go. Same bearer tokens, same proposal gate.
    $r->addRoute('POST', '/mcp', [McpController::class, 'handle']);
    // Secret-URL form for MCP clients that cannot send an Authorization
    // header (claude.ai custom connectors): the token IS the URL secret.
    $r->addRoute('POST', '/mcp/{token:[0-9a-f]{64}}', [McpController::class, 'handle']);

    // Approval queue (session-gated web UI)
    $r->addRoute('GET', '/proposals', [ApprovalController::class, 'index']);
    $r->addRoute('POST', '/proposals/{id:\d+}/approve', [ApprovalController::class, 'approve']);
    $r->addRoute('POST', '/proposals/{id:\d+}/reject', [ApprovalController::class, 'reject']);

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
