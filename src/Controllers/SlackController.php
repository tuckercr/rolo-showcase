<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\ProposalModel;
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
            return $this->handleLogSubmission($payload, $actorId);
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
            return $this->openLogModal((int) ($action['c'] ?? 0), $payload);
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

        if (!isset($this->config->slackUserMap[$slackUser])) {
            return $this->ephemeral('Sorry, this Slack account is not connected to Rolo.');
        }

        $text = strtolower(trim((string) ($form['text'] ?? '')));

        if (str_starts_with($text, 'who ')) {
            return $this->commandWho(trim(substr($text, 4)));
        }

        return match ($text) {
            'debrief', 'review' => $this->commandDebrief($slackUser),
            'overdue' => $this->commandOverdue(),
            default => $this->ephemeral(
                "Rolo commands:\n"
                . "• `/rolo debrief` — DM me today's follow-up review with log/snooze buttons\n"
                . "• `/rolo overdue` — the overdue list, right here\n"
                . "• `/rolo who <name>` — a quick contact card before you pick up the phone\n"
                . "Anything else happens in " . $this->appLink() . ' for now.'
            ),
        };
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

        return (string) json_encode([
            'response_type' => 'ephemeral',
            'text' => (string) $matches[0]['name'],
            'blocks' => $this->contactCard($matches[0]),
        ]);
    }

    private function commandOverdue(): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $overdue = array_values(array_filter(
            (new ContactModel($this->db))->dueBy($today, 0),
            static fn(array $c): bool => (string) $c['next_touch_date'] < $today,
        ));

        if ($overdue === []) {
            return $this->ephemeral(':tada: Nothing is overdue.');
        }

        $blocks = [[
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => sprintf(
                    '*%d overdue follow-up%s*, oldest first:',
                    count($overdue),
                    count($overdue) === 1 ? '' : 's',
                ),
            ],
        ]];

        foreach (array_slice($overdue, 0, 10) as $contact) {
            $id = (int) $contact['id'];
            $org = (string) ($contact['organization_name'] ?? '');

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        '*%s*%s · due %s',
                        (string) $contact['name'],
                        $org !== '' ? ' (' . $org . ')' : '',
                        (string) $contact['next_touch_date'],
                    ),
                ],
                'accessory' => $this->openAccessory($id),
            ];
        }

        if (count($overdue) > 10) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => sprintf('Plus %d more in %s.', count($overdue) - 10, $this->appLink()),
                ]],
            ];
        }

        return (string) json_encode([
            'response_type' => 'ephemeral',
            'text' => sprintf('%d overdue follow-ups', count($overdue)),
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
                'text' => ['type' => 'plain_text', 'text' => 'Log touch'],
                'style' => 'primary',
                'action_id' => 'log_' . $id,
                'value' => json_encode(['a' => 'log', 'c' => $id], JSON_THROW_ON_ERROR),
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
     * Raw body, signature-verified and form-decoded; null when the request
     * is not provably from Slack.
     *
     * @return array<string, mixed>|null
     */
    private function verifiedForm(): ?array
    {
        $rawBody = (string) file_get_contents('php://input');

        $validSignature = SlackSignature::verify(
            $this->config->slackSigningSecret,
            (string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? ''),
            $rawBody,
            (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? ''),
        );

        if (!$validSignature) {
            return null;
        }

        parse_str($rawBody, $form);

        return $form;
    }

    /**
     * @param array<string, mixed> $action decoded button value {a, c|p, d?}
     * @return array{0: string, 1: array<string, mixed>|null} outcome line +
     *         an optional actions block to render under it (the snooze Undo)
     */
    private function perform(array $action, int $actorId): array
    {
        return match ((string) $action['a']) {
            'snooze' => $this->snooze((int) ($action['c'] ?? 0), $actorId),
            'unsnooze' => [
                $this->unsnooze((int) ($action['c'] ?? 0), (string) ($action['d'] ?? ''), $actorId),
                null,
            ],
            'skip' => ['Skipped. The contact stays in the next morning email.', null],
            'approve' => [$this->decide((int) ($action['p'] ?? 0), $actorId, approve: true), null],
            'reject' => [$this->decide((int) ($action['p'] ?? 0), $actorId, approve: false), null],
            default => ['Nothing done: unknown action.', null],
        };
    }

    /**
     * Tap on "Log touch": open the log form. The trigger_id expires in ~3s,
     * so this happens before anything else.
     *
     * @param array<string, mixed> $payload the block_actions payload
     */
    private function openLogModal(int $contactId, array $payload): string
    {
        $contact = (new ContactModel($this->db))->find($contactId);

        if ($contact === null) {
            $this->replaceActedOnBlock($payload, 'Nothing done: that contact no longer exists.');

            return (string) json_encode(['ok' => true]);
        }

        $typeOptions = [];

        foreach (ActivityModel::TYPES as $value => $label) {
            $typeOptions[] = [
                'text' => ['type' => 'plain_text', 'text' => $label],
                'value' => (string) $value,
            ];
        }

        $view = [
            'type' => 'modal',
            'callback_id' => 'log_touch',
            // The submission payload carries no message context, so stash
            // what the outcome post needs (3000-char limit, this is tiny).
            'private_metadata' => json_encode([
                'c' => $contactId,
                'r' => (string) ($payload['response_url'] ?? ''),
            ], JSON_THROW_ON_ERROR),
            'title' => ['type' => 'plain_text', 'text' => 'Log a touch'],
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
                            'text' => ['type' => 'plain_text', 'text' => ActivityModel::TYPES['call']],
                            'value' => 'call',
                        ],
                    ],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'summary',
                    'optional' => true,
                    'label' => ['type' => 'plain_text', 'text' => 'What happened?'],
                    'element' => ['type' => 'plain_text_input', 'action_id' => 'v', 'multiline' => true],
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
            (new SlackClient($this->config))->openModal((string) ($payload['trigger_id'] ?? ''), $view);
        } catch (\Throwable $e) {
            $this->replaceActedOnBlock($payload, 'Could not open the log form: ' . $e->getMessage());
        }

        return (string) json_encode(['ok' => true]);
    }

    /**
     * The submitted log form: write the touch through the proposal pipeline
     * (validation + scheduling in one place) and confirm in the DM.
     *
     * @param array<string, mixed> $payload the view_submission payload
     */
    private function handleLogSubmission(array $payload, int $actorId): string
    {
        if ((string) ($payload['view']['callback_id'] ?? '') !== 'log_touch') {
            http_response_code(400);
            return (string) json_encode(['error' => 'unsupported view']);
        }

        $meta = json_decode((string) ($payload['view']['private_metadata'] ?? ''), true);
        $contactId = is_array($meta) ? (int) ($meta['c'] ?? 0) : 0;
        $responseUrl = is_array($meta) ? (string) ($meta['r'] ?? '') : '';

        $values = $payload['view']['state']['values'] ?? [];
        $field = static fn(string $block, string $key): string =>
            trim((string) ($values[$block]['v'][$key] ?? ''));

        $type = (string) ($values['type']['v']['selected_option']['value'] ?? 'call');
        $summary = $field('summary', 'value');
        $followUpDate = (string) ($values['follow_up_date']['v']['selected_date'] ?? '');
        $followUpNote = $field('follow_up_note', 'value');

        $outcome = $this->performLog($contactId, $actorId, $type, $summary, $followUpDate, $followUpNote);

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
        string $summary,
        string $followUpDate,
        string $followUpNote,
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
            'date' => $this->todayLocal()->format('Y-m-d'),
            'summary' => $summary !== '' ? $summary : 'Logged from the Slack debrief',
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
    private function snooze(int $contactId, int $actorId): array
    {
        $contacts = new ContactModel($this->db);
        $contact = $contacts->find($contactId);

        if ($contact === null) {
            return ['Nothing done: that contact no longer exists.', null];
        }

        $oldDate = (string) ($contact['next_touch_date'] ?? '');
        $newDate = $this->todayLocal()
            ->modify(sprintf('+%d days', self::SNOOZE_DAYS))
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
                'Modified follow up date from: %s, to: %s (from the Slack debrief)',
                $oldDate === '' ? 'none' : $oldDate,
                $newDate,
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
                'Modified follow up date from: %s, to: %s (snooze undone, from the Slack debrief)',
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
