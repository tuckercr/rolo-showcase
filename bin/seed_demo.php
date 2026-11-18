<?php

/**
 * Seed a FRESH Rolo database with a fictional consultancy, so a new install
 * has something to look at: php bin/seed_demo.php [you@example.com]
 *
 * Everything is invented (people, companies, the .example domains), and every
 * date is computed relative to today, so the dashboard always shows a live
 * mix of overdue, due-today, due-this-week and going-cold contacts no matter
 * when you run it.
 *
 * Pass your own email address to take over the first seeded user, so Google
 * Sign-In (which matches on the users table) lets you straight in.
 *
 * Refuses to run when the database already has contacts: it is a starter kit
 * for an empty install, not something to point at real data. Full setup:
 *
 *   cp .env.example .env   # fill in DB_* (and later GOOGLE_*)
 *   composer install
 *   php bin/migrate.php
 *   php bin/seed_demo.php you@example.com
 */

declare(strict_types=1);

use App\Services\ProposalService;
use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$config = require dirname(__DIR__) . '/config/bootstrap.php';

$database = new Database($config);
$pdo = $database->pdo();

if ((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() > 0) {
    fwrite(STDERR, "This database already has contacts - refusing to seed on top of real data.\n");
    fwrite(STDERR, "Point DB_* at a fresh database (and run bin/migrate.php) first.\n");
    exit(1);
}

$signInEmail = $argv[1] ?? null;

if ($signInEmail !== null) {
    if (filter_var($signInEmail, FILTER_VALIDATE_EMAIL) === false) {
        fwrite(STDERR, "\"$signInEmail\" is not a valid email address.\n");
        exit(1);
    }

    $pdo->prepare('UPDATE users SET email = ? WHERE id = 1')->execute([strtolower($signInEmail)]);
}

$today = new DateTimeImmutable('today', $config->appTimezone);
$d = static fn(int $offset): string => $today->modify(sprintf('%+d days', $offset))->format('Y-m-d');

// Users 1 (Colin) and 2 (Dana) come from migration 0002; passing an email
// above re-points user 1 at you.

$orgs = [
    'Lakeview University' => ['university', null],
    'Harbor Diagnostics' => ['startup', 'https://harbordx.example'],
    'Cascade Clinical Research' => ['cro', 'https://cascadecr.example'],
    'Meridian Biosciences' => ['startup', 'https://meridianbio.example'],
    'BioHealth Weekly' => ['media', null],
    'Ridgeline Ventures' => ['investor', 'https://ridgeline.example'],
    'Acme Robotics' => ['startup', 'https://acmerobotics.example'],
    'Summit Advisory Group' => ['other', null],
    'Bluewater Labs' => ['startup', 'https://bluewaterlabs.example'],
];
$orgIds = [];
$orgStmt = $pdo->prepare('INSERT INTO organizations (name, org_type, website) VALUES (?, ?, ?)');

foreach ($orgs as $orgName => [$type, $website]) {
    $orgStmt->execute([$orgName, $type, $website]);
    $orgIds[$orgName] = (int) $pdo->lastInsertId();
}

// name, title, org, stage, email, human detail, last touch, next touch, created_by, updated_by
$contacts = [
    ['Maria Santos', 'Director of Partnerships', 'Lakeview University', 'Relationship Building',
        'msantos@lakeview.example', 'Runs the annual innovation showcase; two spinouts looking for help',
        $d(-21), $d(-2), 1, 2],
    ['Sarah Chen', 'COO', 'Harbor Diagnostics', 'Proposal / Pitch',
        'schen@harbordx.example', 'Decisive; wants the numbers before the narrative', $d(-6), $d(0), 2, 2],
    ['Emily Torres', 'Head of Operations', 'Harbor Diagnostics', 'Opportunity Identified',
        'etorres@harbordx.example', null, $d(-9), $d(-4), 2, 2],
    ['David Okafor', 'VP Clinical Operations', 'Cascade Clinical Research', 'Relationship Building',
        'dokafor@cascadecr.example', 'Marathon runner; their offsite is next month', $d(-32), $d(-2), 1, 1],
    ['Robert Ellis', 'CEO', 'Meridian Biosciences', 'New/Captured',
        'rellis@meridianbio.example', null, $d(-4), $d(-1), 2, 2],
    ['Anna Kowalski', 'Senior Editor', 'BioHealth Weekly', 'Intro Sent',
        'akowalski@biohealthweekly.example', 'Covers the data-storytelling beat', $d(-7), $d(0), 2, 2],
    ['Tom Nguyen', 'Founder', 'Bluewater Labs', 'Active client',
        'tnguyen@bluewaterlabs.example', 'Prefers a call to a thread', $d(-29), $d(1), 1, 2],
    ['Lauren Kim', 'Program Manager', 'Cascade Clinical Research', 'Researching',
        null, null, $d(-3), $d(4), 2, 2],
    ['Priya Shah', 'Chief of Staff', 'Meridian Biosciences', 'Conversation Started',
        'pshah@meridianbio.example', null, $d(-8), $d(6), 2, 2],
    ['Nina Rossi', 'Grants Officer', 'Lakeview University', 'Conversation Started',
        null, 'Met at the showcase reception', null, null, 2, 2],
    ['Grace Lee', 'Producer', 'BioHealth Weekly', 'Conversation Started',
        null, null, $d(-38), $d(-31), 1, 1],
    ['Jane Alvarez', 'Head of Product', 'Acme Robotics', 'Relationship Building',
        'jalvarez@acmerobotics.example', null, $d(-1), $d(12), 2, 2],
    ['Sam Whitfield', 'Analyst', 'Ridgeline Ventures', 'Intro Sent', null, null, $d(-6), $d(0), 2, 2],
    ['Mark Ivers', 'Partner', 'Ridgeline Ventures', 'Researching',
        null, 'Intro came through Tom; skis in Utah', $d(-2), $d(5), 1, 1],
    ['Olivia Grant', 'Principal', 'Summit Advisory Group', 'Leads', null, null, null, null, 2, 2],
    ['Hector Ramos', 'CTO', 'Acme Robotics', 'Leads', null, null, null, null, 1, 1],
    ['Felix Nordin', 'Research Lead', 'Bluewater Labs', 'Dormant', null, null, $d(-120), $d(60), 1, 1],
    ['Wendy Park', 'Director of Communications', 'Summit Advisory Group', 'Closed / Not a Fit',
        null, null, $d(-90), null, 2, 2],
    ['Ben Osei', 'Lab Manager', 'Harbor Diagnostics', 'Researching', null, null, $d(-5), $d(9), 2, 2],
    ['Carla Mendes', 'Business Development', 'Cascade Clinical Research', 'Opportunity Identified',
        'cmendes@cascadecr.example', null, $d(-12), $d(3), 2, 2],
    ['Ivan Petrov', 'Data Science Lead', 'Meridian Biosciences', 'Conversation Started',
        null, null, $d(-15), $d(13), 1, 2],
    ['Ruth Adler', null, null, 'Relationship Building',
        null, 'Monthly coffee; ask how the book is coming', $d(-25), $d(5), 1, 1],
];
$contactIds = [];
$contactStmt = $pdo->prepare(
    'INSERT INTO contacts (name, title, organization_id, relationship_status, email,
        human_detail, last_touch_date, next_touch_date, created_by, updated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($contacts as [$cName, $title, $org, $stage, $email, $detail, $last, $next, $by, $upBy]) {
    $contactStmt->execute([
        $cName, $title, $org === null ? null : $orgIds[$org], $stage, $email, $detail, $last, $next, $by, $upBy,
    ]);
    $contactIds[$cName] = (int) $pdo->lastInsertId();
}

// History: contact, type, day offset, summary, follow-up offset, follow-up note, logged by.
// The newest row per contact lines up with the last_touch_date set above.
$activities = [
    ['Maria Santos', 'coffee', -21, 'Coffee catch-up before the showcase', -2,
        'Send her the board-deck case study', 2],
    ['Maria Santos', 'email', -35, 'She shared the spinout one-pagers', null, null, 1],
    ['Sarah Chen', 'event_meeting', -6, 'Walked the pilot proposal; she wants revised pricing', 0,
        'Walk her through the revised pilot pricing', 2],
    ['Sarah Chen', 'call', -20, 'Scoping call with her ops leads', null, null, 2],
    ['Emily Torres', 'call', -9, 'Pilot scope still with legal', -4,
        'If no reply, nudge her about the pilot scope', 2],
    ['David Okafor', 'event_meeting', -32, 'Q3 review dinner; great feedback on the dashboards',
        null, null, 1],
    ['Robert Ellis', 'intro_made', -4, 'Intro from Ridgeline; sent the overview deck', null, null, 2],
    ['Anna Kowalski', 'email', -7, 'Pitched the founder-metrics story angle', 0,
        'Follow up with the interview slots', 2],
    ['Tom Nguyen', 'call', -29, 'Monthly check-in; renewal conversation opens next quarter',
        null, null, 1],
    ['Lauren Kim', 'linkedin_message', -3, 'Connected after her conference talk', null, null, 2],
    ['Priya Shah', 'call', -8, 'Walked through what a diagnostics workshop covers', 6,
        'Book the diagnostics walk-through', 2],
    ['Grace Lee', 'coffee', -38, 'Talked through the documentary data segment', -31, null, 1],
    ['Jane Alvarez', 'call', -1, 'Discussed the revised proposal; she is taking it to the board',
        null, null, 2],
    ['Jane Alvarez', 'event_meeting', -14, 'Product-metrics working session with her team', null, null, 2],
    ['Sam Whitfield', 'email', -6, 'Sent the fund\'s portfolio-reporting sample', 0, null, 2],
    ['Mark Ivers', 'coffee', -2, 'First chat; he wants a look at the investor update template',
        5, 'Share the investor update template', 1],
    ['Ben Osei', 'email', -5, 'Asked about instrument-log reporting', 9, null, 2],
    ['Carla Mendes', 'event_meeting', -12, 'Workshop scoping; budget lands this month', 3,
        'Share the workshop one-pager', 2],
    ['Ivan Petrov', 'linkedin_message', -15, 'Swapped notes on their analytics stack', null, null, 1],
    ['Ruth Adler', 'coffee', -25, 'Monthly coffee; she offered an intro at Summit', null, null, 1],
];
$activityStmt = $pdo->prepare(
    'INSERT INTO activities (contact_id, organization_id, activity_type, activity_date, summary,
        follow_up_needed, follow_up_date, follow_up_note, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($activities as [$cName, $type, $offset, $summary, $followUpOffset, $note, $by]) {
    $org = null;

    foreach ($contacts as $row) {
        if ($row[0] === $cName && $row[2] !== null) {
            $org = $orgIds[$row[2]];
        }
    }

    $activityStmt->execute([
        $contactIds[$cName], $org, $type, $d($offset), $summary,
        $followUpOffset === null ? 0 : 1,
        $followUpOffset === null ? null : $d($followUpOffset),
        $note, $by,
    ]);
}

// A couple of snooze traces, in the exact shape the snooze button leaves.
$snoozes = [
    ['Felix Nordin', -10, $d(-40), $d(60), 1],
    ['Lauren Kim', -3, $d(1), $d(4), 2],
];

foreach ($snoozes as [$cName, $offset, $from, $to, $by]) {
    $activityStmt->execute([
        $contactIds[$cName], null, 'snoozed', $d($offset),
        sprintf('Modified follow up date from: %s, to: %s', $from, $to),
        0, null, null, $by,
    ]);
}

$tags = [
    'speaker' => ['Maria Santos', 'Lauren Kim'],
    'warm intro' => ['Robert Ellis', 'Mark Ivers'],
    'newsletter' => ['Anna Kowalski', 'Grace Lee', 'Priya Shah', 'Ruth Adler'],
    'priority' => ['Sarah Chen', 'Tom Nguyen', 'Jane Alvarez'],
];
$tagStmt = $pdo->prepare('INSERT INTO tags (name) VALUES (?)');
$tagLinkStmt = $pdo->prepare('INSERT INTO contact_tags (contact_id, tag_id) VALUES (?, ?)');

foreach ($tags as $tagName => $tagged) {
    $tagStmt->execute([$tagName]);
    $tagId = (int) $pdo->lastInsertId();

    foreach ($tagged as $cName) {
        $tagLinkStmt->execute([$contactIds[$cName], $tagId]);
    }
}

// Pending agent proposals, queued through the real validation path so the
// approval queue (and its Slack DMs) have something to show.
$proposals = new ProposalService($config, $database);
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

echo 'Seeded: ' . count($orgs) . ' organizations, ' . count($contacts) . ' contacts, '
    . count($activities) . ' activities, ' . count($tags) . " tags, 3 pending proposals.\n";

if ($signInEmail !== null) {
    echo "Sign-in user: " . strtolower($signInEmail) . " (users row 1).\n";
}
