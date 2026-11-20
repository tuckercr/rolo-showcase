<?php

/**
 * Fill a FRESH database with a fictional consultancy, so a new install has
 * something to look at. Shared by bin/seed_demo.php (CLI) and
 * public/seed_demo.php (HTTP, for shared hosts with no shell — the same
 * reason public/migrate.php exists).
 *
 * Every date is computed relative to today, so the dashboard always shows a
 * live mix of overdue, due-today, due-this-week and going-cold contacts, and
 * of overdue, due and done tasks, no matter when it runs. Refuses to run when the database already has
 * contacts: it is a starter kit for an empty install, not something to
 * point at real data.
 */

declare(strict_types=1);

namespace App\Support;

use App\Models\TaskModel;
use App\Services\ProposalService;
use DateTimeImmutable;
use RuntimeException;

final class DemoSeeder
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $database,
    ) {
    }

    /**
     * @param string|null $signInEmail re-points the first seeded user, so
     *                                 Google Sign-In recognizes that address
     * @return string a human-readable summary of what was created
     * @throws RuntimeException when the database already has contacts
     */
    public function seed(?string $signInEmail): string
    {
        $pdo = $this->database->pdo();

        if ((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() > 0) {
            throw new RuntimeException(
                'This database already has contacts - refusing to seed on top of real data. '
                . 'Point DB_* at a fresh database (and run the migrations) first.',
            );
        }

        if ($signInEmail !== null) {
            if (filter_var($signInEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException("\"$signInEmail\" is not a valid email address.");
            }

            $pdo->prepare('UPDATE users SET email = ? WHERE id = 1')->execute([strtolower($signInEmail)]);
        }

        $today = new DateTimeImmutable('today', $this->config->appTimezone);
        $d = static fn(int $offset): string => $today->modify(sprintf('%+d days', $offset))->format('Y-m-d');

        // Users 1 (Colin) and 2 (Dana) come from migration 0002; $signInEmail
        // above re-points user 1.

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

        // name, title, org, stage, email, human detail, last touch, next touch,
        // created_by, updated_by
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
            ['Felix Nordin', 'Research Lead', 'Bluewater Labs', 'Dormant',
                null, null, $d(-120), $d(60), 1, 1],
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
                $cName, $title, $org === null ? null : $orgIds[$org], $stage, $email,
                $detail, $last, $next, $by, $upBy,
            ]);
            $contactIds[$cName] = (int) $pdo->lastInsertId();
        }

        // Who tends each relationship. Unlisted contacts stay shared, which
        // puts them on everyone's "Mine" view; the rest split between the
        // two seeded users so the Mine / Everyone toggle has something to show.
        $owners = [
            1 => ['Maria Santos', 'David Okafor', 'Anna Kowalski', 'Tom Nguyen', 'Grace Lee', 'Mark Ivers',
                'Hector Ramos', 'Felix Nordin', 'Ivan Petrov', 'Ruth Adler'],
            2 => ['Emily Torres', 'Robert Ellis', 'Lauren Kim', 'Priya Shah', 'Jane Alvarez', 'Olivia Grant',
                'Wendy Park', 'Ben Osei'],
        ];
        $ownerStmt = $pdo->prepare('UPDATE contacts SET owner_id = ? WHERE id = ?');

        foreach ($owners as $ownerId => $owned) {
            foreach ($owned as $cName) {
                $ownerStmt->execute([$ownerId, $contactIds[$cName]]);
            }
        }

        // History: contact, type, day offset, summary, follow-up offset,
        // follow-up note, logged by. The newest row per contact lines up with
        // the last_touch_date set above.
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
            ['Jane Alvarez', 'event_meeting', -14, 'Product-metrics working session with her team',
                null, null, 2],
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

        // Tasks: one-off to-dos, separate from the follow-up rhythm above.
        // title, notes, due offset (null = no date), owner (null = shared),
        // contact, organization, done (days ago, null = open).
        $tasks = [
            ['Intro Tom to the Meridian data team', null, -2, 1, 'Tom Nguyen', null, null],
            ['Draft the pilot pricing one-pager', 'Two options: flat fee, or per-site', 0, 1, 'Sarah Chen', null, null],
            ['Send Anna the interview slots', null, 1, 2, 'Anna Kowalski', null, null],
            ['Prep slides for the innovation showcase talk', 'Twenty minutes; reuse the autumn deck', 4, null,
                null, null, null],
            ['Renew the Harbor Diagnostics retainer paperwork', null, 20, 2, null, 'Harbor Diagnostics', null],
            ['Book travel for the Cascade offsite', null, null, 1, 'David Okafor', null, null],
            ['Order new business cards', null, -1, 1, null, null, 1],
        ];
        $taskModel = new TaskModel($this->database);
        $doneStmt = $pdo->prepare(
            'UPDATE tasks SET completed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY), completed_by = ?
             WHERE id = ?'
        );

        foreach ($tasks as [$title, $notes, $dueOffset, $owner, $cName, $orgName, $doneDaysAgo]) {
            $taskId = $taskModel->create([
                'title' => $title,
                'notes' => $notes,
                'due_date' => $dueOffset === null ? null : $d($dueOffset),
                'owner_id' => $owner,
                'contact_id' => $cName === null ? null : $contactIds[$cName],
                'organization_id' => $orgName === null ? null : $orgIds[$orgName],
            ], $owner ?? 1);

            if ($doneDaysAgo !== null) {
                $doneStmt->execute([$doneDaysAgo, $owner ?? 1, $taskId]);
            }
        }

        // Pending agent proposals, queued through the real validation path so
        // the approval queue (and its Slack DMs) have something to show.
        $proposals = new ProposalService($this->config, $this->database);
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

        $proposals->queue('write', 'create_task', [
            'title' => 'Send Carla the workshop one-pager',
            'due_date' => $d(3),
            'contact_id' => $contactIds['Carla Mendes'],
        ]);

        $summary = 'Seeded: ' . count($orgs) . ' organizations, ' . count($contacts) . ' contacts, '
            . count($activities) . ' activities, ' . count($tags) . ' tags, ' . count($tasks)
            . ' tasks, 4 pending proposals.';

        if ($signInEmail !== null) {
            $summary .= ' Sign-in user: ' . strtolower($signInEmail) . ' (users row 1).';
        }

        return $summary;
    }
}
