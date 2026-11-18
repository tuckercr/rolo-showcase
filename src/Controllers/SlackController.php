<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\ProposalModel;
use App\Models\StageModel;
use App\Models\UserModel;
use App\Services\ProposalService;
use App\Services\SlackDebriefService;
use App\Support\SlackClient;
use App\Support\SlackSignature;
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
            );

            if ($error !== null) {
                $this->replaceActedOnBlock($payload, $error);
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
                $this->replaceActedOnBlock($payload, $error);
            }

            return (string) json_encode(['ok' => true]);
        }

        if ((string) $action['a'] === 'skip') {
            $this->skipAdvance($action, $payload);

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

        $text = strtolower(trim((string) ($form['text'] ?? '')));

        if (str_starts_with($text, 'who ')) {
            return $this->commandWho(trim(substr($text, 4)));
        }

        if (str_starts_with($text, 'log ')) {
            return $this->commandLog(trim(substr($text, 4)), $form);
        }

        if (str_starts_with($text, 'snooze ')) {
            return $this->commandSnooze(trim(substr($text, 7)), $actorId);
        }

        return match ($text) {
            'debrief', 'review' => $this->commandDebrief($slackUser),
            'today' => $this->commandToday(),
            'week' => $this->commandWeek(),
            'overdue' => $this->commandOverdue(),
            'cold' => $this->commandCold(),
            default => $this->ephemeral(
                "Rolo commands:\n"
                . "• `/rolo log <name>` — log an interaction, same short form as the debrief\n"
                . "• `/rolo snooze <name> [days]` — push the follow-up out (3 days if you leave days off)\n"
                . "• `/rolo who <name>` — a quick contact card before you pick up the phone\n"
                . "• `/rolo today` — follow-ups due today\n"
                . "• `/rolo week` — follow-ups due in the next 7 days\n"
                . "• `/rolo overdue` — the overdue list\n"
                . "• `/rolo cold` — contacts going cold\n"
                . "• `/rolo debrief` — DM me today's follow-up review with log/snooze buttons\n"
                . "Anything else happens in " . $this->appLink() . ' for now.'
            ),
        };
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

        $matches = (new ContactModel($this->db))->search(query: $query);

        if ($matches === []) {
            return $this->ephemeral(sprintf('No contact matches "%s".', $query));
        }

        if (count($matches) > 1) {
            return $this->matchList($query, $matches);
        }

        $error = $this->openLogModal(
            (int) $matches[0]['id'],
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
        if (preg_match('/^(.*?)(?:\s+(\d+))?$/', $rest, $m) !== 1 || trim($m[1]) === '') {
            return $this->ephemeral('Usage: `/rolo snooze <name> [days]` — days defaults to 3.');
        }

        $query = trim($m[1]);
        $days = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : self::SNOOZE_DAYS;

        if ($days < 1 || $days > 365) {
            return $this->ephemeral('Days must be between 1 and 365.');
        }

        $matches = (new ContactModel($this->db))->search(query: $query);

        if ($matches === []) {
            return $this->ephemeral(sprintf('No contact matches "%s".', $query));
        }

        if (count($matches) > 1) {
            return $this->matchList($query, $matches);
        }

        [$outcome, $undo] = $this->snooze((int) $matches[0]['id'], $actorId, $days, via: 'via /rolo snooze');

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

    private function commandWho(string $query): string
    {
        if ($query === '') {
            return $this->ephemeral('Usage: `/rolo who <name or organization>`');
        }

        $matches = (new ContactModel($this->db))->search(query: $query);

        if ($matches === []) {
            return $this->ephemeral(sprintf('No contact matches "%s".', $query));
        }

        if (count($matches) > 1) {
            return $this->matchList($query, $matches);
        }

        return (string) json_encode([
            'response_type' => 'ephemeral',
            'text' => (string) $matches[0]['name'],
            'blocks' => $this->contactCard($matches[0]),
        ]);
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
                (string) $c['name'],
                ($c['organization_name'] ?? '') !== '' ? ' (' . $c['organization_name'] . ')' : '',
                (string) $c['relationship_status'],
            ),
            array_slice($matches, 0, 5),
        );

        return $this->ephemeral(sprintf(
            "%d contacts match \"%s\":\n%s%s",
            count($matches),
            $query,
            implode("\n", $lines),
            count($matches) > 5 ? "\n…and more. Try a fuller name." : '',
        ));
    }

    /**
     * The dashboard's Overdue list: due before today, minus the going-cold
     * contacts, so this quotes the same number as the web and the email.
     */
    private function commandOverdue(): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $overdue = array_values(array_filter(
            (new ContactModel($this->db))->dueExcludingCold($today),
            static fn(array $c): bool => (string) $c['next_touch_date'] < $today,
        ));

        return $this->contactList(
            $overdue,
            sprintf('*%d overdue follow-up%s*, oldest first:', count($overdue), count($overdue) === 1 ? '' : 's'),
            ':tada: Nothing is overdue.',
            fn(array $c): string => $this->contactLine($c, 'due ' . (string) $c['next_touch_date']),
        );
    }

    private function commandToday(): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $due = array_values(array_filter(
            (new ContactModel($this->db))->dueBy($today, 0),
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
    private function commandWeek(): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $due = array_values(array_filter(
            (new ContactModel($this->db))->dueBy($today, 6),
            static fn(array $c): bool => (string) $c['next_touch_date'] >= $today,
        ));

        return $this->contactList(
            $due,
            sprintf('*%d follow-up%s due in the next 7 days:*', count($due), count($due) === 1 ? '' : 's'),
            'Nothing is due in the next 7 days.',
            fn(array $c): string => $this->contactLine($c, 'due ' . (string) $c['next_touch_date']),
        );
    }

    private function commandCold(): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $cold = (new ContactModel($this->db))->goingCold($today);

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
            (string) $contact['name'],
            $org !== '' ? ' (' . $org . ')' : '',
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

            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $lineFor($contact)],
            ];
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
            (string) $contact['name'],
            $titleOrg !== '' ? "\n" . $titleOrg : '',
            (string) $contact['relationship_status'],
            (string) ($contact['last_touch_date'] ?? 'never'),
            $next !== '' ? $next : 'none',
            $next !== '' && $next < $today ? ' *(overdue)*' : '',
        );

        if (($contact['human_detail'] ?? '') !== '') {
            $headline .= sprintf("\n_%s_", (string) $contact['human_detail']);
        }

        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $headline],
            'accessory' => $this->openAccessory($id),
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
                    (string) ($activity['created_by_name'] ?? ''),
                    $summary !== '' ? ': ' . mb_substr($summary, 0, 90) : '',
                );
            }

            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => implode("\n", $lines)]],
            ];
        }

        $blocks[] = [
            'type' => 'actions',
            'elements' => [[
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Log'],
                'style' => 'primary',
                'action_id' => 'log_' . $id,
                'value' => json_encode(['a' => 'log', 'c' => $id, 'v' => 'list'], JSON_THROW_ON_ERROR),
            ]],
        ];

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
        $url = $this->config->appUrl !== '' ? $this->config->appUrl : 'https://crm.example.com';

        return sprintf('%s/contacts/%d', $url, $contactId);
    }

    private function commandDebrief(string $slackUser): string
    {
        $service = new SlackDebriefService($this->config, $this->db, new SlackClient($this->config));

        try {
            $service->runForUser($slackUser);
        } catch (\RuntimeException $e) {
            return $this->ephemeral('Could not send the debrief: ' . $e->getMessage());
        }

        return $this->ephemeral('On it — check your DMs.');
    }

    private function ephemeral(string $text): string
    {
        return (string) json_encode(['response_type' => 'ephemeral', 'text' => $text]);
    }

    private function appLink(): string
    {
        $url = $this->config->appUrl !== '' ? $this->config->appUrl : 'https://crm.example.com';

        return sprintf('<%s|Rolo>', $url);
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
    private function skipAdvance(array $action, array $payload): void
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

        $seen = array_values(array_unique($seen));

        $undisplayed = array_values(array_filter(
            (new ContactModel($this->db))->dueExcludingCold($today),
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
     * Keep the debrief's "Plus N more on the dashboard." line honest as
     * Skip consumes the queue; at zero the line goes away.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function updateMoreFooter(array $blocks, int $remaining): array
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
                $url = $this->config->appUrl !== '' ? $this->config->appUrl : 'https://crm.example.com';
                $blocks[$i]['elements'] = [[
                    'type' => 'mrkdwn',
                    'text' => sprintf('Plus %d more on the <%s|dashboard>.', $remaining, $url),
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
                            (string) $contact['name'],
                            ($contact['organization_name'] ?? '') !== ''
                                ? ' (' . (string) $contact['organization_name'] . ')'
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
                        'initial_date' => $this->todayLocal()->format('Y-m-d'),
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
                            (string) $contact['name'],
                            ($contact['organization_name'] ?? '') !== ''
                                ? ' (' . (string) $contact['organization_name'] . ')'
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
            $outcome = sprintf('Nothing changed: %s is already %s.', (string) $contact['name'], $stage);
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
                    (string) $contact['name'],
                    $stage,
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
            (string) $contact['name'],
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

        $contacts->snooze($contactId, $newDate, $actorId);

        // Same history trace as ContactController::snooze — last_touch is
        // deliberately untouched.
        (new ActivityModel($this->db))->create(
            contactId: $contactId,
            organizationId: $contact['organization_id'] === null ? null : (int) $contact['organization_id'],
            activityType: 'snoozed',
            activityDate: $this->todayLocal()->format('Y-m-d'),
            summary: sprintf(
                'Modified follow up date from: %s, to: %s (%s)',
                $oldDate === '' ? 'none' : $oldDate,
                $newDate,
                $via,
            ),
            followUpNeeded: false,
            followUpDate: null,
            userId: $actorId,
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
            sprintf(':zzz: Snoozed %s to %s.', (string) $contact['name'], $newDate),
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

        $currentDate = (string) ($contact['next_touch_date'] ?? '');

        $contacts->snooze($contactId, $restoreDate === '' ? null : $restoreDate, $actorId);

        (new ActivityModel($this->db))->create(
            contactId: $contactId,
            organizationId: $contact['organization_id'] === null ? null : (int) $contact['organization_id'],
            activityType: 'snoozed',
            activityDate: $this->todayLocal()->format('Y-m-d'),
            summary: sprintf(
                'Modified follow up date from: %s, to: %s (snooze undone, from Slack)',
                $currentDate === '' ? 'none' : $currentDate,
                $restoreDate === '' ? 'none' : $restoreDate,
            ),
            followUpNeeded: false,
            followUpDate: null,
            userId: $actorId,
        );

        return sprintf(
            ':leftwards_arrow_with_hook: Follow-up for %s restored to %s.',
            (string) $contact['name'],
            $restoreDate === '' ? 'no reminder' : $restoreDate,
        );
    }

    private function decide(int $proposalId, int $actorId, bool $approve): string
    {
        $proposals = new ProposalModel($this->db);
        $proposal = $proposals->find($proposalId);
        $actorName = (string) ((new UserModel($this->db))->find($actorId)['name'] ?? 'someone');

        if ($proposal === null || $proposal['status'] !== 'pending') {
            return 'Nothing done: this proposal was already decided.';
        }

        if (!$approve) {
            $proposals->decide($proposalId, 'rejected', $actorId);

            return sprintf(':x: Rejected by %s.', $actorName);
        }

        try {
            (new ProposalService($this->config, $this->db))->apply($proposal, $actorId);
        } catch (\Throwable $e) {
            $proposals->decide($proposalId, 'rejected', $actorId, $e->getMessage());

            return sprintf(
                ':warning: Could not apply (%s) — rejected with the error recorded.',
                $e->getMessage(),
            );
        }

        $proposals->decide($proposalId, 'approved', $actorId);

        return sprintf(':white_check_mark: Approved by %s.', $actorName);
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
