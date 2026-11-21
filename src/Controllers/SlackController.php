<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityModel;
use App\Models\CalendarConnectionModel;
use App\Models\ContactModel;
use App\Models\ProposalModel;
use App\Models\StageModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Services\CalendarSuggestionService;
use App\Services\ProposalService;
use App\Services\SlackDebriefService;
use App\Services\SlackTaskBlocks;
use App\Services\SnoozeService;
use App\Services\TaskService;
use App\Support\Mrkdwn;
use App\Support\PhotoLink;
use App\Support\SlackClient;
use App\Support\SlackSignature;
use App\Support\TaskQuickAdd;
use InvalidArgumentException;

/**
 * Inbound Slack interactivity (docs/SLACK_BOT_SPEC.md): button taps from the
 * evening debrief and the approval DMs. Stateless like the agent API — no
 * session, no CSRF; authentication is Slack's request signature plus the
 * SLACK_USER_MAP allowlist (the tapping Slack account must map to a rolo
 * user, who is recorded as the actor).
 *
 * A tap from a mapped person's own Slack account IS the approval, so these
 * writes go straight through — but "log" still rides ProposalService's
 * validate + apply so the scheduling rules live in exactly one place; the
 * proposal row is queued and approved in the same request.
 */
final class SlackController extends Controller
{
    private const SNOOZE_DAYS = 3;

    public function interact(): string
    {
        header('Content-Type: application/json; charset=utf-8');

        $form = $this->verifiedForm();

        if ($form === null) {
            http_response_code(401);
            return (string) json_encode(['error' => 'bad signature']);
        }

        $payload = json_decode((string) ($form['payload'] ?? ''), true);
        $kind = is_array($payload) ? (string) ($payload['type'] ?? '') : '';

        if (!in_array($kind, ['block_actions', 'view_submission'], true)) {
            http_response_code(400);
            return (string) json_encode(['error' => 'unsupported payload']);
        }

        $slackUser = (string) ($payload['user']['id'] ?? '');
        $actorId = $this->config->slackUserMap[$slackUser] ?? null;

        if ($actorId === null) {
            http_response_code(403);
            return (string) json_encode(['error' => 'unknown user']);
        }

        if ($kind === 'view_submission') {
            $callback = (string) ($payload['view']['callback_id'] ?? '');

            if ($callback === 'log_touch') {
                return $this->handleLogSubmission($payload, $actorId);
            }

            if ($callback === 'edit_contact') {
                return $this->handleEditSubmission($payload, $actorId);
            }

            http_response_code(400);
            return (string) json_encode(['error' => 'unsupported view']);
        }

        $action = json_decode((string) ($payload['actions'][0]['value'] ?? ''), true);

        if (!is_array($action) || !isset($action['a'])) {
            http_response_code(400);
            return (string) json_encode(['error' => 'unsupported action']);
        }

        if ((string) $action['a'] === 'open') {
            // A URL button: the browser opens client-side and Slack just
            // wants the event acknowledged. Leave the row's buttons alone.
            return (string) json_encode(['ok' => true]);
        }

        if ((string) $action['a'] === 'log') {
            // Opens the log form; the write happens on view_submission.
            // 'cal' marks a calendar suggestion: the modal defaults to
            // Event / Meeting with the meeting title prefilled ('s').
            $error = $this->openLogModal(
                (int) ($action['c'] ?? 0),
                (string) ($payload['trigger_id'] ?? ''),
                (string) ($payload['response_url'] ?? ''),
                via: match ((string) ($action['v'] ?? '')) {
                    'list' => 'cmd',
                    'cal' => 'cal',
                    default => 'debrief',
                },
                prefillSummary: (string) ($action['s'] ?? ''),
                // 'd' rides /rolo meetings yesterday: the catch-up log must
                // default to the day the meeting was, not today.
                prefillDate: (string) ($action['d'] ?? ''),
            );

            if ($error !== null) {
                $this->ephemeralNote($payload, $error);
            }

            return (string) json_encode(['ok' => true]);
        }

        if ((string) $action['a'] === 'edit') {
            // Opens the stage form; the write happens on view_submission.
            $error = $this->openEditModal(
                (int) ($action['c'] ?? 0),
                (string) ($payload['trigger_id'] ?? ''),
                (string) ($payload['response_url'] ?? ''),
            );

            if ($error !== null) {
                $this->ephemeralNote($payload, $error);
            }

            return (string) json_encode(['ok' => true]);
        }

        if ((string) $action['a'] === 'skip') {
            $this->skipAdvance($action, $payload, $actorId);

            return (string) json_encode(['ok' => true]);
        }

        [$outcome, $extraActions] = $this->perform($action, $actorId);

        $this->replaceActedOnBlock($payload, $outcome, $extraActions);

        return (string) json_encode(['ok' => true]);
    }

    /**
     * The /rolo slash command. Slack sends it form-encoded (user_id, text,
     * …) with the same request signature as interactivity.
     */
    public function command(): string
    {
        header('Content-Type: application/json; charset=utf-8');

        $form = $this->verifiedForm();

        if ($form === null) {
            http_response_code(401);
            return (string) json_encode(['error' => 'bad signature']);
        }

        $slackUser = (string) ($form['user_id'] ?? '');
        $actorId = $this->config->slackUserMap[$slackUser] ?? null;

        if ($actorId === null) {
            return $this->ephemeral('Sorry, this Slack account is not connected to Rolo.');
        }

        // Slack sends the text entity-encoded (&amp; for &) and with links
        // and mentions in <...> markup; toPlain() gets back what was typed,
        // so "Q&A" is saved and searched for as "Q&A". Every reply escapes
        // again on the way out (Mrkdwn::esc).
        //
        // Commands match case-insensitively, but a task's title is saved as
        // typed, so the original text is kept alongside the lowered one.
        $rawText = trim(Mrkdwn::toPlain((string) ($form['text'] ?? '')));
        $text = strtolower($rawText);

        if ($text === 'task' || str_starts_with($text, 'task ')) {
            return $this->commandTask(trim(substr($rawText, 4)), $actorId);
        }

        if (str_starts_with($text, 'who ')) {
            return $this->commandWho(trim(substr($text, 4)));
        }

        if (str_starts_with($text, 'log ')) {
            return $this->commandLog(trim(substr($text, 4)), $form);
        }

        if (str_starts_with($text, 'snooze ')) {
            return $this->commandSnooze(trim(substr($text, 7)), $actorId);
        }

        if ($text === 'meetings' || str_starts_with($text, 'meetings ')) {
            return $this->commandMeetings($actorId, trim(substr($text, 8)));
        }

        return match ($text) {
            'debrief', 'review' => $this->commandDebrief($slackUser),
            'today' => $this->commandToday($actorId),
            'week' => $this->commandWeek($actorId),
            'overdue' => $this->commandOverdue($actorId),
            'cold' => $this->commandCold($actorId),
            'tasks' => $this->commandTasks($actorId),
            default => $this->ephemeral(
                "Rolo commands:\n"
                . "• `/rolo log <name>`: log an interaction, same short form as the debrief\n"
                . "• `/rolo snooze <name> [days]`: push the follow-up out (3 days if you leave days off)\n"
                . "• `/rolo who <name>`: a quick contact card before you pick up the phone\n"
                . "• `/rolo task <what> [when]`: add a task for yourself. When can be today, tomorrow, "
                . "a weekday, next week, in 3 days, or a date like 2026-12-01, "
                . "with by, on or due in front if you like. "
                . "Start with `for <name>:` to attach it to a contact\n"
                . "• `/rolo tasks`: your open tasks (yours, plus shared), each with a Done button\n"
                . "• `/rolo today`: your follow-ups due today (contacts you own, plus shared)\n"
                . "• `/rolo week`: your follow-ups due in the next 7 days\n"
                . "• `/rolo overdue`: your overdue list\n"
                . "• `/rolo cold`: your contacts going cold\n"
                . "• `/rolo meetings [yesterday|tomorrow]`: calendar meetings with people in Rolo, "
                . "today or yesterday ready to log, tomorrow as prep\n"
                . "• `/rolo debrief`: DM me today's follow-up review with log/snooze buttons\n"
                . "Anything else happens in " . $this->appLink() . ' for now.'
            ),
        };
    }

    /**
     * /rolo task [for <name>:] <what> [when]: capture a to-do the moment it
     * comes up. The task belongs to the person typing; "for <name>:" links
     * it to a contact. A slash command from a mapped Slack account IS the
     * person's own action, so it is saved directly (no approval queue), and
     * through TaskService like every other way a task is written.
     */
    private function commandTask(string $rest, int $actorId): string
    {
        $today = $this->todayLocal();
        $parsed = TaskQuickAdd::parse($rest, $today);

        if ($parsed === null) {
            return $this->ephemeral(
                "Usage: `/rolo task <what> [when]`, for example `/rolo task Send the Q1 proposal friday`.\n"
                . "When can be today, tomorrow, a weekday, next week, in 3 days, or a date like 2026-12-01. "
                . "Leave it off for no due date.\n"
                . "You can put by, on or due in front of it. The short days mon, wed, sat and sun "
                . "need one, as in `by sat`, because they are also ordinary words.\n"
                . "Attach it to a contact with `/rolo task for <name>: <what> [when]`.\n"
                . 'Put the task in "quotes" if it ends in a word like friday that is not the due date.'
            );
        }

        $contactId = null;

        if ($parsed['contact_query'] !== null) {
            $contact = $this->findOneContact($parsed['contact_query'], ', so nothing was added');

            if (is_string($contact)) {
                return $contact;
            }

            $contactId = (int) $contact['id'];
        }

        $service = new TaskService($this->config, $this->db);
        [$clean, $errors] = $service->validate([
            'title' => $parsed['title'],
            'due_date' => $parsed['due_date'],
            'contact_id' => $contactId,
            'owner_id' => $actorId,
        ]);

        if ($errors !== []) {
            return $this->ephemeral('Nothing was added: ' . (string) reset($errors));
        }

        $task = (new TaskModel($this->db))->find($service->create($clean, $actorId));

        return (string) json_encode([
            'response_type' => 'ephemeral',
            'text' => 'Added to your tasks: ' . Mrkdwn::esc((string) $clean['title']),
            'blocks' => (new SlackTaskBlocks($this->config))->listBlocks(
                $task === null ? [] : [$task],
                ':ballot_box_with_check: Added to your tasks:',
                $today->format('Y-m-d'),
            ),
        ]);
    }

    /**
     * /rolo tasks: the asker's open tasks under the Mine lens (their own
     * plus shared ones), dated ones first, each with a Done button.
     */
    private function commandTasks(int $actorId): string
    {
        $tasks = (new TaskModel($this->db))->open($actorId);

        if ($tasks === []) {
            return $this->ephemeral(':tada: No open tasks. Add one with `/rolo task <what> [when]`.');
        }

        $headline = sprintf('*%d open task%s*, soonest first:', count($tasks), count($tasks) === 1 ? '' : 's');

        return (string) json_encode([
            'response_type' => 'ephemeral',
            'text' => $headline,
            'blocks' => (new SlackTaskBlocks($this->config))->listBlocks(
                $tasks,
                $headline,
                $this->todayLocal()->format('Y-m-d'),
            ),
        ]);
    }

    /**
     * /rolo log <name>: the fastest way to log a call the moment it ends —
     * find the contact, open the same modal the debrief uses.
     *
     * @param array<string, mixed> $form the verified slash-command form
     */
    private function commandLog(string $query, array $form): string
    {
        if ($query === '') {
            return $this->ephemeral('Usage: `/rolo log <name or organization>`');
        }

        $contact = $this->findOneContact($query);

        if (is_string($contact)) {
            return $contact;
        }

        $error = $this->openLogModal(
            (int) $contact['id'],
            (string) ($form['trigger_id'] ?? ''),
            (string) ($form['response_url'] ?? ''),
            via: 'cmd',
        );

        // An empty 200 acknowledges the command without posting anything;
        // the modal is already on screen and confirms on submit.
        return $error === null ? '' : $this->ephemeral($error);
    }

    /**
     * /rolo snooze <name> [days]: push a follow-up out without opening the
     * app. Days defaults to the debrief button's 3.
     */
    private function commandSnooze(string $rest, int $actorId): string
    {
        $args = self::parseSnoozeArgs($rest);

        if ($args === null) {
            return $this->ephemeral(
                'Usage: `/rolo snooze <name> [days]`. Days defaults to 3. '
                . 'Quote a name that ends in a number: `/rolo snooze "studio 54" 7`.'
            );
        }

        ['query' => $query, 'days' => $days] = $args;

        if ($days < 1 || $days > 365) {
            return $this->ephemeral('Days must be between 1 and 365.');
        }

        $contact = $this->findOneContact($query);

        if (is_string($contact)) {
            return $contact;
        }

        [$outcome, $undo] = $this->snooze((int) $contact['id'], $actorId, $days, via: 'via /rolo snooze');

        $blocks = [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $outcome]]];

        if ($undo !== null) {
            $blocks[] = $undo;
        }

        return (string) json_encode([
            'response_type' => 'ephemeral',
            'text' => $outcome,
            'blocks' => $blocks,
        ]);
    }

    /**
     * Parse the text after "/rolo snooze": a trailing number is the day
     * count, which would make a name that ends in digits ("Studio 54")
     * unaddressable — so a double-quoted name is taken verbatim, with an
     * optional day count after the closing quote. Public for tests.
     *
     * @return array{query: string, days: int}|null null = nothing usable
     */
    public static function parseSnoozeArgs(string $rest): ?array
    {
        $rest = trim($rest);

        if (preg_match('/^"(.*?)"\s*(\d+)?$/', $rest, $m) === 1) {
            $query = trim($m[1]);
        } elseif (preg_match('/^(.*?)(?:\s+(\d+))?$/', $rest, $m) === 1) {
            $query = trim($m[1]);
        } else {
            return null;
        }

        if ($query === '') {
            return null;
        }

        return [
            'query' => $query,
            'days' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : self::SNOOZE_DAYS,
        ];
    }

    private function commandWho(string $query): string
    {
        if ($query === '') {
            return $this->ephemeral('Usage: `/rolo who <name or organization>`');
        }

        $contact = $this->findOneContact($query);

        if (is_string($contact)) {
            return $contact;
        }

        return (string) json_encode([
            'response_type' => 'ephemeral',
            'text' => (string) $contact['name'],
            'blocks' => $this->contactCard($contact),
        ]);
    }

    /**
     * The one contact a typed name means. Every command that takes a name
     * acts only on an exact single match; for none or several it answers
     * instead of guessing, and the caller returns that answer as is.
     *
     * @param string $noneNote appended to the "no match" line, for commands
     *        that should say what did not happen
     * @return array<string, mixed>|string the contact row, or the ephemeral
     *         reply to send in its place
     */
    private function findOneContact(string $query, string $noneNote = ''): array|string
    {
        $matches = (new ContactModel($this->db))->search(query: $query);

        if ($matches === []) {
            return $this->ephemeral(sprintf('No contact matches "%s"%s.', Mrkdwn::esc($query), $noneNote));
        }

        if (count($matches) > 1) {
            return $this->matchList($query, $matches);
        }

        return $matches[0];
    }

    /**
     * An ambiguous name: list the candidates so the next try can be exact.
     *
     * @param list<array<string, mixed>> $matches
     */
    private function matchList(string $query, array $matches): string
    {
        $lines = array_map(
            fn(array $c): string => sprintf(
                '• <%s|%s>%s · %s',
                $this->contactUrl((int) $c['id']),
                Mrkdwn::esc((string) $c['name']),
                ($c['organization_name'] ?? '') !== ''
                    ? ' (' . Mrkdwn::esc((string) $c['organization_name']) . ')'
                    : '',
                Mrkdwn::esc((string) $c['relationship_status']),
            ),
            array_slice($matches, 0, 5),
        );

        return $this->ephemeral(sprintf(
            "%d contacts match \"%s\":\n%s%s",
            count($matches),
            Mrkdwn::esc($query),
            implode("\n", $lines),
            count($matches) > 5 ? "\n…and more. Try a fuller name." : '',
        ));
    }

    /**
     * The dashboard's Overdue list under the asker's Mine lens (their
     * contacts plus shared ones): due before today, minus the going-cold
     * contacts, so this quotes the same numbers as the web and the email.
     */
    private function commandOverdue(int $actorId): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $overdue = array_values(array_filter(
            (new ContactModel($this->db))->dueExcludingCold($today, $actorId),
            static fn(array $c): bool => (string) $c['next_touch_date'] < $today,
        ));

        return $this->contactList(
            $overdue,
            sprintf('*%d overdue follow-up%s*, oldest first:', count($overdue), count($overdue) === 1 ? '' : 's'),
            ':tada: Nothing is overdue.',
            fn(array $c): string => $this->contactLine($c, 'due ' . (string) $c['next_touch_date']),
            // Overdue is the most actionable list of all — same in-place
            // Log/Snooze/Edit row as today and cold, not the report layout.
            buttons: true,
        );
    }

    private function commandToday(int $actorId): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $due = array_values(array_filter(
            (new ContactModel($this->db))->dueBy($today, 0, $actorId),
            static fn(array $c): bool => (string) $c['next_touch_date'] === $today,
        ));

        return $this->contactList(
            $due,
            sprintf('*%d follow-up%s due today:*', count($due), count($due) === 1 ? '' : 's'),
            ':tada: Nothing is due today.',
            fn(array $c): string => $this->contactLine($c, (string) $c['relationship_status']),
            // Today's list is a to-do, not a report: act on it in place.
            buttons: true,
        );
    }

    /**
     * The dashboard's "due this week": today through 6 days out.
     */
    private function commandWeek(int $actorId): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $due = array_values(array_filter(
            (new ContactModel($this->db))->dueBy($today, 6, $actorId),
            static fn(array $c): bool => (string) $c['next_touch_date'] >= $today,
        ));

        return $this->contactList(
            $due,
            sprintf('*%d follow-up%s due in the next 7 days:*', count($due), count($due) === 1 ? '' : 's'),
            'Nothing is due in the next 7 days.',
            fn(array $c): string => $this->contactLine($c, 'due ' . (string) $c['next_touch_date']),
        );
    }

    private function commandCold(int $actorId): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $cold = (new ContactModel($this->db))->goingCold($today, forUserId: $actorId);

        return $this->contactList(
            $cold,
            sprintf(
                '*%d contact%s going cold*, longest quiet first:',
                count($cold),
                count($cold) === 1 ? '' : 's',
            ),
            ':tada: Nobody is going cold.',
            fn(array $c): string => $this->contactLine(
                $c,
                $c['last_touch_date'] === null
                    ? 'never touched'
                    : 'last touch ' . (string) $c['last_touch_date'],
            ),
            buttons: true,
        );
    }

    /**
     * One "*Name* (Org) · detail" row for the list replies.
     *
     * @param array<string, mixed> $contact
     */
    private function contactLine(array $contact, string $detail): string
    {
        $org = (string) ($contact['organization_name'] ?? '');

        return sprintf(
            '*%s*%s · %s',
            Mrkdwn::esc((string) $contact['name']),
            $org !== '' ? ' (' . Mrkdwn::esc($org) . ')' : '',
            $detail,
        );
    }

    /**
     * An ephemeral list of contacts (max 10 rows, a "plus N more" footer
     * past the cap) — the shape every /rolo list command answers in. Plain
     * rows carry an Open button; $buttons swaps it for the debrief's action
     * row (Log / Open / Snooze, no Skip — these lists are asked for, not
     * scheduled, so there is no "leave it for the morning email" to skip to).
     *
     * @param list<array<string, mixed>> $contacts
     * @param callable(array<string, mixed>): string $lineFor
     */
    private function contactList(
        array $contacts,
        string $headline,
        string $emptyText,
        callable $lineFor,
        bool $buttons = false,
    ): string {
        if ($contacts === []) {
            return $this->ephemeral($emptyText);
        }

        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $headline],
        ]];

        foreach (array_slice($contacts, 0, 10) as $contact) {
            $id = (int) $contact['id'];

            if (!$buttons) {
                $blocks[] = [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => $lineFor($contact)],
                    'accessory' => $this->openAccessory($id),
                ];
                continue;
            }

            $row = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $lineFor($contact)],
            ];
            $photo = PhotoLink::accessory($this->config, $contact);

            if ($photo !== null) {
                $row['accessory'] = $photo;
            }

            $blocks[] = $row;
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Log'],
                        'style' => 'primary',
                        'action_id' => 'log_' . $id,
                        // v marks the tap's origin so the history trace says
                        // /rolo, not "the Slack debrief".
                        'value' => json_encode(['a' => 'log', 'c' => $id, 'v' => 'list'], JSON_THROW_ON_ERROR),
                    ],
                    $this->openAccessory($id),
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Snooze 3 days'],
                        'action_id' => 'snooze_' . $id,
                        'value' => json_encode(['a' => 'snooze', 'c' => $id, 'v' => 'list'], JSON_THROW_ON_ERROR),
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Edit'],
                        'action_id' => 'edit_' . $id,
                        'value' => json_encode(['a' => 'edit', 'c' => $id], JSON_THROW_ON_ERROR),
                    ],
                ],
            ];
        }

        if (count($contacts) > 10) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => sprintf('Plus %d more in %s.', count($contacts) - 10, $this->appLink()),
                ]],
            ];
        }

        return (string) json_encode([
            'response_type' => 'ephemeral',
            'text' => $headline,
            'blocks' => $blocks,
        ]);
    }

    /**
     * The "glance before I pick up the phone" card.
     *
     * @param array<string, mixed> $contact
     * @return list<array<string, mixed>>
     */
    private function contactCard(array $contact): array
    {
        $id = (int) $contact['id'];
        $today = $this->todayLocal()->format('Y-m-d');
        $next = (string) ($contact['next_touch_date'] ?? '');
        $titleOrg = implode(' · ', array_filter([
            (string) ($contact['title'] ?? ''),
            (string) ($contact['organization_name'] ?? ''),
        ], static fn(string $v): bool => $v !== ''));

        $headline = sprintf(
            "*%s*%s\nStage: %s · Last touch: %s · Next: %s%s",
            Mrkdwn::esc((string) $contact['name']),
            $titleOrg !== '' ? "\n" . Mrkdwn::esc($titleOrg) : '',
            Mrkdwn::esc((string) $contact['relationship_status']),
            (string) ($contact['last_touch_date'] ?? 'never'),
            $next !== '' ? $next : 'none',
            $next !== '' && $next < $today ? ' *(overdue)*' : '',
        );

        if (($contact['human_detail'] ?? '') !== '') {
            $headline .= sprintf("\n_%s_", Mrkdwn::esc((string) $contact['human_detail']));
        }

        // The headshot takes the card's accessory slot when there is one;
        // Open then moves down into the actions row.
        $photo = PhotoLink::accessory($this->config, $contact);
        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $headline],
            'accessory' => $photo ?? $this->openAccessory($id),
        ]];

        $history = array_slice((new ActivityModel($this->db))->forContact($id), 0, 3);

        if ($history !== []) {
            $lines = [];

            foreach ($history as $activity) {
                $summary = trim((string) ($activity['summary'] ?? ''));

                $lines[] = sprintf(
                    '• %s %s (%s)%s',
                    (string) $activity['activity_date'],
                    (string) (ActivityModel::DISPLAY_TYPES[(string) $activity['activity_type']]
                        ?? $activity['activity_type']),
                    Mrkdwn::esc((string) ($activity['created_by_name'] ?? '')),
                    $summary !== '' ? ': ' . Mrkdwn::esc(mb_substr($summary, 0, 90)) : '',
                );
            }

            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => implode("\n", $lines)]],
            ];
        }

        // Open tasks about this person, whoever owns them: part of "what do
        // I need to know before I pick up the phone".
        $openTasks = (new TaskModel($this->db))->openForContact($id);

        if ($openTasks !== []) {
            $renderer = new SlackTaskBlocks($this->config);
            $lines = array_map(
                static fn(array $task): string => '• ' . $renderer->line(
                    ['contact_id' => null, 'organization_name' => null] + $task,
                    $today,
                ),
                array_slice($openTasks, 0, 3),
            );

            if (count($openTasks) > 3) {
                $lines[] = sprintf('…and %d more.', count($openTasks) - 3);
            }

            $blocks[] = [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => "Open tasks:\n" . implode("\n", $lines),
                ]],
            ];
        }

        $actions = [[
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => 'Log'],
            'style' => 'primary',
            'action_id' => 'log_' . $id,
            'value' => json_encode(['a' => 'log', 'c' => $id, 'v' => 'list'], JSON_THROW_ON_ERROR),
        ]];

        if ($photo !== null) {
            $actions[] = $this->openAccessory($id);
        }

        $blocks[] = ['type' => 'actions', 'elements' => $actions];

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function openAccessory(int $contactId): array
    {
        return [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => 'Open'],
            'url' => $this->contactUrl($contactId),
            'action_id' => 'open_' . $contactId,
            'value' => json_encode(['a' => 'open', 'c' => $contactId], JSON_THROW_ON_ERROR),
        ];
    }

    private function contactUrl(int $contactId): string
    {
        return sprintf('%s/contacts/%d', $this->config->baseUrl(), $contactId);
    }

    /**
     * /rolo meetings [yesterday|tomorrow]: calendar meetings that matched
     * contacts. Today (the default) and yesterday are the debrief's section
     * on demand — prefilled Log buttons, minus anyone already logged that
     * day, and yesterday's modal opens dated yesterday. Tomorrow is prep:
     * who you're meeting and what you know, nothing to log yet.
     */
    private function commandMeetings(int $actorId, string $arg = ''): string
    {
        $offset = match ($arg) {
            '', 'today' => 0,
            'yesterday' => -1,
            'tomorrow' => 1,
            default => null,
        };

        if ($offset === null) {
            return $this->ephemeral('Try `/rolo meetings`, `/rolo meetings yesterday` or `/rolo meetings tomorrow`.');
        }

        if ((new CalendarConnectionModel($this->db))->findForUser($actorId) === null) {
            return $this->ephemeral(
                'Your Google Calendar is not connected yet. Connect it from Settings in '
                . $this->appLink() . ' and meetings with people in Rolo will show up here.'
            );
        }

        $when = $offset === 0 ? 'today' : $arg;
        $day = $this->todayLocal()->modify(sprintf('%+d days', $offset))->format('Y-m-d');

        try {
            $suggestions = (new CalendarSuggestionService($this->config, $this->db))
                ->suggestionsFor($actorId, $day, skipAlreadyLogged: $offset <= 0);
        } catch (\Throwable) {
            return $this->ephemeral('Could not read your calendar just now. Try again in a minute.');
        }

        if ($suggestions === []) {
            return $this->ephemeral($offset === 1
                ? ':calendar: No meetings with people in Rolo on tomorrow\'s calendar.'
                : sprintf(':calendar: No unlogged meetings with people in Rolo on your calendar %s.', $when));
        }

        $renderer = new SlackDebriefService($this->config, $this->db, new SlackClient($this->config));

        return (string) json_encode([
            'response_type' => 'ephemeral',
            'blocks' => $renderer->calendarBlocks(
                $suggestions,
                $when,
                // Today's modal already defaults to today; only yesterday
                // needs the date carried into the Log payload.
                $offset === -1 ? $day : null,
            ),
        ]);
    }

    private function commandDebrief(string $slackUser): string
    {
        $service = new SlackDebriefService($this->config, $this->db, new SlackClient($this->config));

        try {
            $service->runForUser($slackUser);
        } catch (\RuntimeException $e) {
            return $this->ephemeral('Could not send the debrief: ' . $e->getMessage());
        }

        return $this->ephemeral('On it. Check your DMs.');
    }

    private function ephemeral(string $text): string
    {
        return (string) json_encode(['response_type' => 'ephemeral', 'text' => $text]);
    }

    private function appLink(): string
    {
        return sprintf('<%s|Rolo>', $this->config->baseUrl());
    }

    /**
     * The Events API endpoint (JSON body, unlike the form-encoded webhooks).
     * Two things arrive here: the one-time url_verification handshake when
     * the Request URL is saved in the app config, and message.im events from
     * someone typing into the bot's DM. The bot is command-driven, so a
     * plain message gets a pointer at /rolo instead of silence.
     */
    public function events(): string
    {
        header('Content-Type: application/json; charset=utf-8');

        $rawBody = $this->verifiedRawBody();

        if ($rawBody === null) {
            http_response_code(401);
            return (string) json_encode(['error' => 'bad signature']);
        }

        $event = json_decode($rawBody, true);

        if (!is_array($event)) {
            http_response_code(400);
            return (string) json_encode(['error' => 'bad payload']);
        }

        if ((string) ($event['type'] ?? '') === 'url_verification') {
            return (string) json_encode(['challenge' => (string) ($event['challenge'] ?? '')]);
        }

        // Slack re-delivers an event when the ack was slow; the reply from
        // the first delivery already went out, so never answer a retry.
        if ((string) ($_SERVER['HTTP_X_SLACK_RETRY_NUM'] ?? '') !== '') {
            return (string) json_encode(['ok' => true]);
        }

        $inner = $event['event'] ?? null;

        // Only a human typing plainly into the bot's DM gets the nudge: not
        // the bot's own messages (bot_id — replying to those would loop),
        // not edits/deletes/joins (subtype), not channel messages.
        if (
            is_array($inner)
            && (string) ($inner['type'] ?? '') === 'message'
            && (string) ($inner['channel_type'] ?? '') === 'im'
            && !isset($inner['bot_id'])
            && !isset($inner['subtype'])
        ) {
            try {
                (new SlackClient($this->config))->postMessage(
                    (string) ($inner['channel'] ?? ''),
                    'I only understand `/rolo` commands. Type `/rolo` to see what I can do.',
                );
            } catch (\Throwable) {
                // A missed nudge is not worth a non-200; Slack would retry
                // the event and we would say it twice (or never).
            }
        }

        return (string) json_encode(['ok' => true]);
    }

    /**
     * Raw body, signature-verified and form-decoded; null when the request
     * is not provably from Slack.
     *
     * @return array<string, mixed>|null
     */
    private function verifiedForm(): ?array
    {
        $rawBody = $this->verifiedRawBody();

        if ($rawBody === null) {
            return null;
        }

        parse_str($rawBody, $form);

        return $form;
    }

    /**
     * The request's raw body when Slack's signature proves it, else null.
     */
    private function verifiedRawBody(): ?string
    {
        $rawBody = (string) file_get_contents('php://input');

        $validSignature = SlackSignature::verify(
            $this->config->slackSigningSecret,
            (string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? ''),
            $rawBody,
            (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? ''),
        );

        return $validSignature ? $rawBody : null;
    }

    /**
     * @param array<string, mixed> $action decoded button value {a, c|p, d?}
     * @return array{0: string, 1: array<string, mixed>|null} outcome line +
     *         an optional actions block to render under it (the snooze Undo)
     */
    private function perform(array $action, int $actorId): array
    {
        return match ((string) $action['a']) {
            'snooze' => $this->snooze(
                (int) ($action['c'] ?? 0),
                $actorId,
                via: (string) ($action['v'] ?? '') === 'list' ? 'via a /rolo list' : 'from the Slack debrief',
            ),
            'unsnooze' => [
                $this->unsnooze((int) ($action['c'] ?? 0), (string) ($action['d'] ?? ''), $actorId),
                null,
            ],
            'task_done' => $this->completeTask((int) ($action['t'] ?? 0), $actorId),
            'task_reopen' => [$this->reopenTask((int) ($action['t'] ?? 0), $actorId), null],
            'approve' => [$this->decide((int) ($action['p'] ?? 0), $actorId, approve: true), null],
            'reject' => [$this->decide((int) ($action['p'] ?? 0), $actorId, approve: false), null],
            default => ['Nothing done: unknown action.', null],
        };
    }

    /**
     * Skip is a display action, not a CRM event: nothing is written. In the
     * debrief (whose tap payload carries the message) the skipped row is
     * replaced by the next due contact that isn't on screen — MAX_PROMPTS
     * made the same oldest contacts monopolize the debrief while the rest
     * never surfaced in Slack at all (Colin, 2026-11-12). Everything on
     * screen, plus ids skipped earlier (carried as 'x' on Skip buttons,
     * since a replaced row leaves no other trace), is excluded.
     *
     * @param array<string, mixed> $action decoded button value {a, c, x?}
     * @param array<string, mixed> $payload the block_actions payload
     */
    private function skipAdvance(array $action, array $payload, int $actorId): void
    {
        $skippedLine = 'Skipped. The contact stays in the next morning email.';
        $blocks = is_array($payload['message']['blocks'] ?? null) ? $payload['message']['blocks'] : null;

        if ($blocks === null) {
            // Not a debrief DM (nothing to splice into): just acknowledge.
            $this->replaceActedOnBlock($payload, $skippedLine);

            return;
        }

        $today = $this->todayLocal()->format('Y-m-d');

        // Everything already shown or skipped earlier is off limits.
        $seen = self::seenContactIds($action, $blocks);

        // The replacement row follows the same Mine lens as the debrief DM
        // being edited, so a skipped contact never swaps in someone else's.
        $undisplayed = array_values(array_filter(
            (new ContactModel($this->db))->dueExcludingCold($today, $actorId),
            static fn(array $c): bool => !in_array((int) $c['id'], $seen, true),
        ));

        // Locate the tapped actions row; the prompt line sits right above it.
        $actionId = (string) ($payload['actions'][0]['action_id'] ?? '');
        $actionsIndex = null;

        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? '') !== 'actions') {
                continue;
            }

            foreach ($block['elements'] ?? [] as $element) {
                if (($element['action_id'] ?? '') === $actionId) {
                    $actionsIndex = $i;
                    break 2;
                }
            }
        }

        if ($actionsIndex === null) {
            $this->replaceActedOnBlock($payload, $skippedLine);

            return;
        }

        if ($undisplayed === []) {
            // Nothing left to swap in: collapse the row as Skip always did.
            array_splice($blocks, $actionsIndex, 1, [[
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => $skippedLine]],
            ]]);
        } else {
            $service = new SlackDebriefService($this->config, $this->db, new SlackClient($this->config));
            $start = ($blocks[$actionsIndex - 1]['type'] ?? '') === 'section' ? $actionsIndex - 1 : $actionsIndex;
            array_splice(
                $blocks,
                $start,
                $actionsIndex - $start + 1,
                $service->promptBlocks($undisplayed[0], $today, $seen),
            );
            $blocks = $this->updateMoreFooter($blocks, count($undisplayed) - 1);
        }

        try {
            (new SlackClient($this->config))->respond((string) ($payload['response_url'] ?? ''), [
                'replace_original' => true,
                'text' => 'Debrief updated.',
                'blocks' => $blocks,
            ]);
        } catch (\Throwable) {
            // Nothing was written anywhere; a failed cosmetic update must
            // not become an error for Slack to retry.
        }
    }

    /**
     * Every contact id the debrief message already shows or has skipped:
     * the tapped action's own id, the ids skipped earlier it carries as
     * 'x', and every id found on the message's remaining buttons (a
     * replaced row leaves no other trace). Public for tests — this
     * harvesting is the subtle half of Skip's splice.
     *
     * @param array<string, mixed> $action decoded button value {a, c, x?}
     * @param list<array<string, mixed>> $blocks the message's blocks
     * @return list<int>
     */
    public static function seenContactIds(array $action, array $blocks): array
    {
        $seen = [(int) ($action['c'] ?? 0)];

        foreach ((array) ($action['x'] ?? []) as $x) {
            $seen[] = (int) $x;
        }

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'actions') {
                continue;
            }

            foreach ($block['elements'] ?? [] as $element) {
                $value = json_decode((string) ($element['value'] ?? ''), true);

                if (!is_array($value)) {
                    continue;
                }

                if (isset($value['c'])) {
                    $seen[] = (int) $value['c'];
                }

                foreach ((array) ($value['x'] ?? []) as $x) {
                    $seen[] = (int) $x;
                }
            }
        }

        return array_values(array_unique($seen));
    }

    /**
     * Keep the debrief's "Plus N more on the dashboard." line honest as
     * Skip consumes the queue; at zero the line goes away. Public for tests.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public function updateMoreFooter(array $blocks, int $remaining): array
    {
        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? '') !== 'context') {
                continue;
            }

            $text = (string) ($block['elements'][0]['text'] ?? '');

            if (!str_starts_with($text, 'Plus ') || !str_contains($text, ' more on the ')) {
                continue;
            }

            if ($remaining > 0) {
                $blocks[$i]['elements'] = [[
                    'type' => 'mrkdwn',
                    'text' => sprintf('Plus %d more on the <%s|dashboard>.', $remaining, $this->config->baseUrl()),
                ]];
            } else {
                array_splice($blocks, $i, 1);
            }

            break;
        }

        return $blocks;
    }

    /**
     * Open the log form (from a debrief button or /rolo log). The trigger_id
     * expires in ~3s, so this happens before anything else. Returns an error
     * line for the caller to surface, or null when the modal opened.
     */
    private function openLogModal(
        int $contactId,
        string $triggerId,
        string $responseUrl,
        string $via,
        string $prefillSummary = '',
        string $prefillDate = '',
    ): ?string {
        $contact = (new ContactModel($this->db))->find($contactId);

        if ($contact === null) {
            return 'Nothing done: that contact no longer exists.';
        }

        $typeOptions = [];

        foreach (ActivityModel::TYPES as $value => $label) {
            $typeOptions[] = [
                'text' => ['type' => 'plain_text', 'text' => $label],
                'value' => (string) $value,
            ];
        }

        // A calendar suggestion is a meeting you had; everything else most
        // often follows a call.
        $initialType = $via === 'cal' ? 'event_meeting' : 'call';

        $view = [
            'type' => 'modal',
            'callback_id' => 'log_touch',
            // The submission payload carries no message context, so stash
            // what the outcome post needs (3000-char limit, this is tiny).
            'private_metadata' => json_encode([
                'c' => $contactId,
                'r' => $responseUrl,
                'v' => $via,
            ], JSON_THROW_ON_ERROR),
            'title' => ['type' => 'plain_text', 'text' => 'Log an interaction'],
            'submit' => ['type' => 'plain_text', 'text' => 'Log it'],
            'close' => ['type' => 'plain_text', 'text' => 'Cancel'],
            'blocks' => [
                [
                    'type' => 'context',
                    'elements' => [[
                        'type' => 'mrkdwn',
                        'text' => sprintf(
                            '*%s*%s',
                            Mrkdwn::esc((string) $contact['name']),
                            ($contact['organization_name'] ?? '') !== ''
                                ? ' (' . Mrkdwn::esc((string) $contact['organization_name']) . ')'
                                : '',
                        ),
                    ]],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'type',
                    'label' => ['type' => 'plain_text', 'text' => 'Type'],
                    'element' => [
                        'type' => 'static_select',
                        'action_id' => 'v',
                        'options' => $typeOptions,
                        'initial_option' => [
                            'text' => ['type' => 'plain_text', 'text' => ActivityModel::TYPES[$initialType]],
                            'value' => $initialType,
                        ],
                    ],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'date',
                    'label' => ['type' => 'plain_text', 'text' => 'Date'],
                    'element' => [
                        'type' => 'datepicker',
                        'action_id' => 'v',
                        'initial_date' => $prefillDate !== '' ? $prefillDate : $this->todayLocal()->format('Y-m-d'),
                    ],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'summary',
                    'optional' => true,
                    'label' => ['type' => 'plain_text', 'text' => 'What happened?'],
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'v',
                        'multiline' => true,
                        'placeholder' => [
                            'type' => 'plain_text',
                            'text' => 'Quick summary of the interaction...',
                        ],
                    ] + ($prefillSummary !== '' ? ['initial_value' => $prefillSummary] : []),
                ],
                [
                    'type' => 'input',
                    'block_id' => 'follow_up_date',
                    'optional' => true,
                    'label' => ['type' => 'plain_text', 'text' => 'Follow up by'],
                    'element' => ['type' => 'datepicker', 'action_id' => 'v'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'follow_up_note',
                    'optional' => true,
                    'label' => ['type' => 'plain_text', 'text' => 'Follow-up note'],
                    'element' => ['type' => 'plain_text_input', 'action_id' => 'v'],
                ],
            ],
        ];

        try {
            (new SlackClient($this->config))->openModal($triggerId, $view);
        } catch (\Throwable $e) {
            return 'Could not open the log form: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * Tap on "Edit": a small form to move the contact to another stage
     * (Dana triaging the cold list to Dormant or Closed without opening
     * the app). Returns an error line for the caller, or null on success.
     */
    private function openEditModal(int $contactId, string $triggerId, string $responseUrl): ?string
    {
        $contact = (new ContactModel($this->db))->find($contactId);

        if ($contact === null) {
            return 'Nothing done: that contact no longer exists.';
        }

        $current = (string) $contact['relationship_status'];
        $stageOptions = [];
        $initial = null;

        foreach ((new StageModel($this->db))->allOrdered() as $stage) {
            $option = [
                'text' => ['type' => 'plain_text', 'text' => (string) $stage['name']],
                'value' => (string) $stage['name'],
            ];
            $stageOptions[] = $option;

            if ((string) $stage['name'] === $current) {
                $initial = $option;
            }
        }

        $view = [
            'type' => 'modal',
            'callback_id' => 'edit_contact',
            'private_metadata' => json_encode([
                'c' => $contactId,
                'r' => $responseUrl,
            ], JSON_THROW_ON_ERROR),
            'title' => ['type' => 'plain_text', 'text' => 'Edit contact'],
            'submit' => ['type' => 'plain_text', 'text' => 'Save'],
            'close' => ['type' => 'plain_text', 'text' => 'Cancel'],
            'blocks' => [
                [
                    'type' => 'context',
                    'elements' => [[
                        'type' => 'mrkdwn',
                        'text' => sprintf(
                            '*%s*%s',
                            Mrkdwn::esc((string) $contact['name']),
                            ($contact['organization_name'] ?? '') !== ''
                                ? ' (' . Mrkdwn::esc((string) $contact['organization_name']) . ')'
                                : '',
                        ),
                    ]],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'stage',
                    'label' => ['type' => 'plain_text', 'text' => 'Stage'],
                    'element' => [
                        'type' => 'static_select',
                        'action_id' => 'v',
                        'options' => $stageOptions,
                    ] + ($initial !== null ? ['initial_option' => $initial] : []),
                ],
            ],
        ];

        try {
            (new SlackClient($this->config))->openModal($triggerId, $view);
        } catch (\Throwable $e) {
            return 'Could not open the edit form: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * The submitted edit form: move the contact's stage through the
     * proposal pipeline (stage validation and the Active-client celebration
     * both live there) and confirm where the tap happened.
     *
     * @param array<string, mixed> $payload the view_submission payload
     */
    private function handleEditSubmission(array $payload, int $actorId): string
    {
        $meta = json_decode((string) ($payload['view']['private_metadata'] ?? ''), true);
        $contactId = is_array($meta) ? (int) ($meta['c'] ?? 0) : 0;
        $responseUrl = is_array($meta) ? (string) ($meta['r'] ?? '') : '';

        $values = $payload['view']['state']['values'] ?? [];
        $stage = (string) ($values['stage']['v']['selected_option']['value'] ?? '');

        $contacts = new ContactModel($this->db);
        $contact = $contacts->find($contactId);

        if ($contact === null) {
            $outcome = 'Nothing done: that contact no longer exists.';
        } elseif ($stage === '' || $stage === (string) $contact['relationship_status']) {
            $outcome = sprintf(
                'Nothing changed: %s is already %s.',
                Mrkdwn::esc((string) $contact['name']),
                Mrkdwn::esc($stage),
            );
        } else {
            $service = new ProposalService($this->config, $this->db);

            try {
                [$proposal] = $service->queue('slack', 'update_contact', [
                    'contact_id' => $contactId,
                    'stage' => $stage,
                ]);
                $service->apply($proposal, $actorId);
                (new ProposalModel($this->db))->decide((int) $proposal['id'], 'approved', $actorId);

                $outcome = sprintf(
                    ':white_check_mark: %s moved to %s.',
                    Mrkdwn::esc((string) $contact['name']),
                    Mrkdwn::esc($stage),
                );
            } catch (InvalidArgumentException $e) {
                $outcome = 'Nothing done: ' . $e->getMessage();
            }
        }

        if ($responseUrl !== '') {
            try {
                (new SlackClient($this->config))->respond($responseUrl, [
                    'replace_original' => false,
                    'response_type' => 'ephemeral',
                    'text' => $outcome,
                ]);
            } catch (\Throwable) {
                // The write already happened; a lost confirmation is not an error.
            }
        }

        // Empty 200 closes the modal.
        return '';
    }

    /**
     * The submitted log form: write the touch through the proposal pipeline
     * (validation + scheduling in one place) and confirm in the DM.
     *
     * @param array<string, mixed> $payload the view_submission payload
     */
    private function handleLogSubmission(array $payload, int $actorId): string
    {
        $meta = json_decode((string) ($payload['view']['private_metadata'] ?? ''), true);
        $contactId = is_array($meta) ? (int) ($meta['c'] ?? 0) : 0;
        $responseUrl = is_array($meta) ? (string) ($meta['r'] ?? '') : '';
        $via = is_array($meta) ? (string) ($meta['v'] ?? '') : '';

        $values = $payload['view']['state']['values'] ?? [];
        $field = static fn(string $block, string $key): string =>
            trim((string) ($values[$block]['v'][$key] ?? ''));

        $type = (string) ($values['type']['v']['selected_option']['value'] ?? 'call');
        $date = (string) ($values['date']['v']['selected_date'] ?? '');
        $summary = $field('summary', 'value');
        $followUpDate = (string) ($values['follow_up_date']['v']['selected_date'] ?? '');
        $followUpNote = $field('follow_up_note', 'value');

        $outcome = $this->performLog(
            $contactId,
            $actorId,
            $type,
            $date,
            $summary,
            $followUpDate,
            $followUpNote,
            defaultSummary: match ($via) {
                'cmd' => 'Logged via /rolo',
                'cal' => 'Logged from a calendar suggestion',
                default => 'Logged from the Slack debrief',
            },
        );

        if ($responseUrl !== '') {
            try {
                (new SlackClient($this->config))->respond($responseUrl, [
                    'replace_original' => false,
                    'response_type' => 'ephemeral',
                    'text' => $outcome,
                ]);
            } catch (\Throwable) {
                // The write already happened; a lost confirmation is not an error.
            }
        }

        // Empty 200 closes the modal.
        return '';
    }

    private function performLog(
        int $contactId,
        int $actorId,
        string $type,
        string $date,
        string $summary,
        string $followUpDate,
        string $followUpNote,
        string $defaultSummary = 'Logged from the Slack debrief',
    ): string {
        $contacts = new ContactModel($this->db);
        $contact = $contacts->find($contactId);

        if ($contact === null) {
            return 'Nothing done: that contact no longer exists.';
        }

        $service = new ProposalService($this->config, $this->db);

        $logPayload = [
            'contact_id' => $contactId,
            'type' => $type,
            'date' => $date !== '' ? $date : $this->todayLocal()->format('Y-m-d'),
            'summary' => $summary !== '' ? $summary : $defaultSummary,
            'counts_as_touch' => true,
        ];

        if ($followUpDate !== '') {
            $logPayload['follow_up_date'] = $followUpDate;
        }

        if ($followUpNote !== '') {
            $logPayload['follow_up_note'] = $followUpNote;
        }

        try {
            [$proposal] = $service->queue('slack', 'log_activity', $logPayload);
            $service->apply($proposal, $actorId);
            (new ProposalModel($this->db))->decide((int) $proposal['id'], 'approved', $actorId);
        } catch (InvalidArgumentException $e) {
            return 'Nothing done: ' . $e->getMessage();
        }

        $next = (string) ($contacts->find($contactId)['next_touch_date'] ?? '');

        return sprintf(
            ':white_check_mark: Logged %s with %s.%s',
            strtolower((string) (ActivityModel::TYPES[$type] ?? 'a touch')),
            Mrkdwn::esc((string) $contact['name']),
            $next !== '' ? sprintf(' Next follow-up %s.', $next) : '',
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function snooze(
        int $contactId,
        int $actorId,
        int $days = self::SNOOZE_DAYS,
        string $via = 'from the Slack debrief',
    ): array {
        $contacts = new ContactModel($this->db);
        $contact = $contacts->find($contactId);

        if ($contact === null) {
            return ['Nothing done: that contact no longer exists.', null];
        }

        $oldDate = (string) ($contact['next_touch_date'] ?? '');
        $newDate = $this->todayLocal()
            ->modify(sprintf('+%d days', $days))
            ->format('Y-m-d');

        (new SnoozeService($this->db))->reschedule(
            $contact,
            $newDate,
            $actorId,
            $this->todayLocal()->format('Y-m-d'),
            note: $via,
        );

        $undo = [
            'type' => 'actions',
            'elements' => [[
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Undo'],
                'action_id' => 'unsnooze_' . $contactId,
                'value' => json_encode(
                    ['a' => 'unsnooze', 'c' => $contactId, 'd' => $oldDate],
                    JSON_THROW_ON_ERROR,
                ),
            ]],
        ];

        return [
            sprintf(':zzz: Snoozed %s to %s.', Mrkdwn::esc((string) $contact['name']), $newDate),
            $undo,
        ];
    }

    /**
     * Undo a snooze: put the follow-up back to the date it had before the
     * tap ('' = it had none), with the same immutable trace as any other
     * date change.
     */
    private function unsnooze(int $contactId, string $restoreDate, int $actorId): string
    {
        $contacts = new ContactModel($this->db);
        $contact = $contacts->find($contactId);

        if ($contact === null) {
            return 'Nothing done: that contact no longer exists.';
        }

        (new SnoozeService($this->db))->reschedule(
            $contact,
            $restoreDate === '' ? null : $restoreDate,
            $actorId,
            $this->todayLocal()->format('Y-m-d'),
            note: 'snooze undone, from Slack',
        );

        return sprintf(
            ':leftwards_arrow_with_hook: Follow-up for %s restored to %s.',
            Mrkdwn::esc((string) $contact['name']),
            $restoreDate === '' ? 'no reminder' : $restoreDate,
        );
    }

    /**
     * Tap on a task's Done button. Ticking is a compare-and-set in
     * TaskModel, so a second tap (the other person's copy of a shared
     * task, a double tap) reports "already done" instead of re-stamping it.
     *
     * @return array{0: string, 1: array<string, mixed>|null} outcome line +
     *         the Undo row
     */
    private function completeTask(int $taskId, int $actorId): array
    {
        $task = (new TaskModel($this->db))->find($taskId);

        if ($task === null) {
            return ['Nothing done: that task no longer exists.', null];
        }

        $title = Mrkdwn::esc((string) $task['title']);

        if (!(new TaskService($this->config, $this->db))->complete($taskId, $actorId)) {
            return [sprintf('Already done: *%s*.', $title), null];
        }

        return [
            sprintf(':white_check_mark: Done: *%s*.', $title),
            (new SlackTaskBlocks($this->config))->undoActions($taskId),
        ];
    }

    private function reopenTask(int $taskId, int $actorId): string
    {
        $task = (new TaskModel($this->db))->find($taskId);

        if ($task === null) {
            return 'Nothing done: that task no longer exists.';
        }

        (new TaskService($this->config, $this->db))->reopen($taskId, $actorId);

        return sprintf(
            ':leftwards_arrow_with_hook: Back on your list: *%s*.',
            Mrkdwn::esc((string) $task['title']),
        );
    }

    private function decide(int $proposalId, int $actorId, bool $approve): string
    {
        $proposals = new ProposalModel($this->db);
        $proposal = $proposals->find($proposalId);
        $actorName = Mrkdwn::esc((string) ((new UserModel($this->db))->find($actorId)['name'] ?? 'someone'));

        if ($proposal === null || $proposal['status'] !== 'pending') {
            return 'Nothing done: this proposal was already decided.';
        }

        if (!$approve) {
            return $proposals->decide($proposalId, 'rejected', $actorId)
                ? sprintf(':x: Rejected by %s.', $actorName)
                : 'Nothing done: this proposal was already decided.';
        }

        // ProposalService::approve wins the pending -> approved
        // compare-and-set BEFORE applying: the DM goes to everyone, and two
        // near-simultaneous Approve taps must not both run apply()
        // (duplicate activity rows, double stage changes). The loser of the
        // race changes nothing.
        $result = (new ProposalService($this->config, $this->db))->approve($proposalId, $actorId);

        return match ($result['outcome']) {
            'approved' => sprintf(':white_check_mark: Approved by %s.', $actorName),
            'failed' => sprintf(
                ':warning: Could not apply (%s). Rejected, with the error recorded.',
                Mrkdwn::esc((string) $result['error']),
            ),
            default => 'Nothing done: this proposal was already decided.',
        };
    }

    /**
     * Surface an error under the message WITHOUT touching it. A failed
     * modal open (the ~3s trigger_id expired, a Slack hiccup) is transient:
     * splicing the error over the actions row would permanently delete
     * buttons nothing was done with. An ephemeral note keeps the row
     * tappable for another try.
     *
     * @param array<string, mixed> $payload the block_actions payload
     */
    private function ephemeralNote(array $payload, string $text): void
    {
        $responseUrl = (string) ($payload['response_url'] ?? '');

        if ($responseUrl === '') {
            return;
        }

        try {
            (new SlackClient($this->config))->respond($responseUrl, [
                'replace_original' => false,
                'response_type' => 'ephemeral',
                'text' => $text,
            ]);
        } catch (\Throwable) {
            // An unseen error note is not worth a non-200 for Slack to retry.
        }
    }

    /**
     * Swap the tapped buttons row for the outcome line via response_url, so
     * the message shows what happened and the buttons can't fire twice.
     * $extraActions (the snooze Undo) renders right under the outcome.
     *
     * @param array<string, mixed> $payload the full block_actions payload
     * @param array<string, mixed>|null $extraActions
     */
    private function replaceActedOnBlock(array $payload, string $outcome, ?array $extraActions = null): void
    {
        $responseUrl = (string) ($payload['response_url'] ?? '');

        if ($responseUrl === '') {
            return;
        }

        // A tap on an ephemeral message (the /rolo lists) carries no message
        // body to edit, and replacing the original would wipe the whole list
        // because one row was acted on — append the outcome below it instead.
        if (!is_array($payload['message'] ?? null)) {
            $blocks = [[
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => $outcome]],
            ]];

            if ($extraActions !== null) {
                $blocks[] = $extraActions;
            }

            try {
                (new SlackClient($this->config))->respond($responseUrl, [
                    'replace_original' => false,
                    'response_type' => 'ephemeral',
                    'text' => $outcome,
                    'blocks' => $blocks,
                ]);
            } catch (\Throwable) {
                // The action itself succeeded; see below.
            }

            return;
        }

        $actionId = (string) ($payload['actions'][0]['action_id'] ?? '');
        $blocks = is_array($payload['message']['blocks'] ?? null) ? $payload['message']['blocks'] : [];
        $replaced = false;

        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? '') !== 'actions') {
                continue;
            }

            foreach ($block['elements'] ?? [] as $element) {
                if (($element['action_id'] ?? '') === $actionId) {
                    $replacement = [[
                        'type' => 'context',
                        'elements' => [['type' => 'mrkdwn', 'text' => $outcome]],
                    ]];

                    if ($extraActions !== null) {
                        $replacement[] = $extraActions;
                    }

                    array_splice($blocks, $i, 1, $replacement);
                    $replaced = true;
                    break 2;
                }
            }
        }

        $message = [
            'replace_original' => true,
            'text' => $outcome,
        ];

        if ($replaced) {
            $message['blocks'] = $blocks;
        }

        try {
            (new SlackClient($this->config))->respond($responseUrl, $message);
        } catch (\Throwable) {
            // The action itself succeeded; a failed cosmetic update must not
            // turn the whole interaction into an error for Slack to retry.
        }
    }
}
