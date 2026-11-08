<?php

declare(strict_types=1);

/**
 * Demo data behind the screenshots/ images — entirely fictional people and
 * companies. Dates are relative to today so the dashboard buckets (overdue,
 * due this week, going cold) populate the same whenever it runs.
 *
 * Usage (against a DISPOSABLE database that already has the migrations):
 *   TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=test TEST_DB_USER=... \
 *   TEST_DB_PASSWORD=... php database/demo/seed.php
 *
 * The database name must contain "test" — same guard as the test suite.
 */

use App\Services\ProposalService;
use App\Support\Config;
use App\Support\Database;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$name = (string) getenv('TEST_DB_NAME');

if ($name === '' || preg_match('/(^|_)test($|_)/', strtolower($name)) !== 1) {
    fwrite(STDERR, "TEST_DB_NAME must be \"test\" or have a \"test\" component.\n");
    exit(1);
}

$config = new Config(
    appEnv: 'development',
    appTimezone: new DateTimeZone('America/New_York'),
    dbHost: (string) getenv('TEST_DB_HOST') ?: '127.0.0.1',
    dbName: $name,
    dbUser: (string) getenv('TEST_DB_USER'),
    dbPassword: (string) getenv('TEST_DB_PASSWORD'),
);

$db = new Database($config);
$pdo = $db->pdo();

$today = new DateTimeImmutable('today', $config->appTimezone);
$d = static fn(int $offset): string => $today->modify(sprintf('%+d days', $offset))->format('Y-m-d');

// Users 1 (Colin) and 2 (Dana) come from migration 0002.

$orgs = [
    'Lakeview University' => ['university', null],
    'Harbor Diagnostics' => ['startup', null],
    'Cascade Clinical Research' => ['cro', 'https://cascadecr.example'],
    'Meridian Biosciences' => ['startup', 'https://meridianbio.example'],
    'BioHealth Weekly' => ['media', null],
    'Ridgeline Ventures' => ['investor', 'https://ridgeline.example'],
    'Acme Biotech' => ['startup', null],
];
$orgIds = [];
$orgStmt = $pdo->prepare(
    'INSERT INTO organizations (name, org_type, website) VALUES (?, ?, ?)'
);

foreach ($orgs as $orgName => [$type, $website]) {
    $orgStmt->execute([$orgName, $type, $website]);
    $orgIds[$orgName] = (int) $pdo->lastInsertId();
}

// name, title, org, stage, email, human detail, last touch, next touch,
// note on the next follow-up, created_by, updated_by
$contacts = [
    ['Maria Santos', 'Director, Tech Transfer', 'Lakeview University', 'Conversation Started',
        'msantos@lakeview.example', 'Two spinouts looking for commercial help',
        $d(-21), $d(-19), 'Send her the board-deck case study', 1, 2],
    ['Sarah Chen', null, null, 'Proposal / Pitch', null, null, $d(-13), $d(-8), null, 2, 2],
    ['Emily Torres', null, 'Harbor Diagnostics', 'Opportunity Identified', null, null,
        $d(-9), $d(-4), 'If no reply, nudge her about the pilot scope', 2, 2],
    ['David Okafor', null, 'Cascade Clinical Research', 'Relationship Building',
        null, null, $d(-32), $d(-2), null, 1, 1],
    ['Robert Ellis', null, 'Meridian Biosciences', 'New/Captured', null, null,
        $d(-4), $d(-1), null, 2, 2],
    ['Anna Kowalski', null, 'BioHealth Weekly', 'Intro Sent', null, null, $d(-7), $d(0), null, 2, 2],
    ['Tom Nguyen', null, null, 'Active client', null, null, $d(-29), $d(1), null, 1, 2],
    ['Lauren Kim', null, 'Cascade Clinical Research', 'Researching', null, null,
        $d(-3), $d(4), null, 2, 2],
    ['Priya Shah', null, 'Meridian Biosciences', 'Conversation Started', null, null,
        $d(-8), $d(6), 'Book the diagnostics walk-through', 2, 2],
    ['Nina Rossi', null, 'Lakeview University', 'Conversation Started', null, null,
        null, null, null, 2, 2],
    ['Grace Lee', null, 'BioHealth Weekly', 'Conversation Started', null, null,
        $d(-38), $d(-31), null, 1, 1],
    ['Jane Doe', null, 'Acme Biotech', 'Relationship Building', null, null,
        $d(-1), $d(-12), 'Send her the revised pilot pricing PDF', 2, 2],
    ['Sam Smith', null, null, 'Intro Sent', null, null, $d(-6), $d(0), null, 2, 2],
    ['Mark Ivers', null, 'Ridgeline Ventures', 'Researching', null, null, $d(-2), $d(5), null, 1, 1],
];
$contactIds = [];
$contactStmt = $pdo->prepare(
    'INSERT INTO contacts (name, title, organization_id, relationship_status, email,
        human_detail, last_touch_date, next_touch_date, created_by, updated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($contacts as [$cName, $title, $org, $stage, $email, $detail, $last, $next, $note, $by, $upBy]) {
    $contactStmt->execute([
        $cName, $title, $org === null ? null : $orgIds[$org], $stage, $email, $detail, $last, $next, $by, $upBy,
    ]);
    $contactIds[$cName] = (int) $pdo->lastInsertId();

    if ($note !== null) {
        $pdo->prepare(
            'INSERT INTO activities (contact_id, activity_type, activity_date, summary,
                follow_up_needed, follow_up_date, follow_up_note, created_by)
             VALUES (?, ?, ?, ?, 1, ?, ?, 2)'
        )->execute([
            $contactIds[$cName],
            'coffee',
            // Jane Doe also gets a fresh touch below for the email's
            // "logged yesterday" section; keep her note activity older so
            // she doesn't appear there twice.
            $cName === 'Jane Doe' ? $d(-13) : ($last ?? $d(-7)),
            $cName === 'Maria Santos' ? 'Coffee catch-up' : null,
            $next,
            $note,
        ]);
    }
}

// Yesterday's logged touch, for the email's "have you logged..." section.
$pdo->prepare(
    'INSERT INTO activities (contact_id, activity_type, activity_date, summary, created_by)
     VALUES (?, ?, ?, ?, 2)'
)->execute([$contactIds['Jane Doe'], 'call', $d(-1), 'Discussed the proposal']);

// Three pending agent proposals, queued through the real validation path.
$proposals = new ProposalService($config, $db);
$proposals->queue('write', 'update_contact', [
    'contact_id' => $contactIds['Sarah Chen'],
    'stage' => 'Active client',
    'phone' => '555-0142',
]);
$proposals->queue('write', 'log_activity', [
    'contact_id' => $contactIds['David Okafor'],
    'type' => 'call',
    'date' => $d(-2),
    'summary' => 'Intro call about the Q4 data storytelling workshop. '
        . 'He wants a scoped proposal before their offsite.',
    'counts_as_touch' => true,
    'follow_up_date' => $d(4),
    'follow_up_note' => 'Send the scoped workshop proposal',
]);
$proposals->queue('write', 'create_organization', [
    'name' => 'Northgate Health Partners',
    'type' => 'startup',
    'website' => 'https://northgatehealth.example',
]);

echo "Seeded: " . count($orgs) . " organizations, " . count($contacts) . " contacts, 3 pending proposals.\n";
