<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProposalModel;
use App\Support\Config;
use App\Support\Database;
use App\Support\Mrkdwn;
use App\Support\SlackClient;

/**
 * DMs pending agent proposals to everyone in SLACK_USER_MAP with
 * Approve / Reject buttons — the Approvals page as a 5-second tap
 * (docs/SLACK_BOT_SPEC.md). Runs on every cron tick; api_proposals
 * .slack_notified_at makes each proposal announce exactly once.
 */
final class SlackApprovalNotifier
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly SlackClient $slack,
    ) {
    }

    /**
     * @return string human-readable outcome (logged by the caller)
     */
    public function run(): string
    {
        if ($this->config->slackBotToken === '' || $this->config->slackUserMap === []) {
            return 'skipped: Slack approvals are not configured';
        }

        $proposals = new ProposalModel($this->db);
        $service = new ProposalService($this->config, $this->db);
        $announced = 0;
        $failed = 0;

        foreach ($proposals->pending() as $proposal) {
            if (($proposal['slack_notified_at'] ?? null) !== null) {
                continue;
            }

            $id = (int) $proposal['id'];
            $view = $service->describe($proposal);
            $title = (string) ($view['title'] ?? $proposal['summary']);
            $consequence = (string) ($view['consequence'] ?? '');

            $text = sprintf('Approval needed: %s', $title);
            $body = sprintf(
                "*%s*\n%s%s",
                Mrkdwn::esc($title),
                Mrkdwn::esc((string) $proposal['summary']),
                $consequence !== '' ? "\n_" . Mrkdwn::esc($consequence) . '_' : '',
            );

            $blocks = [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $body]],
                [
                    'type' => 'context',
                    'elements' => [[
                        'type' => 'mrkdwn',
                        'text' => sprintf(
                            'Proposed by %s · %s UTC',
                            $proposal['proposed_by'] === null
                                ? sprintf(
                                    'an AI agent (%s token)',
                                    Mrkdwn::esc((string) $proposal['token_name']),
                                )
                                : Mrkdwn::esc((string) $proposal['proposed_by_name']) . "'s AI agent",
                            (string) $proposal['created_at'],
                        ),
                    ]],
                ],
                [
                    'type' => 'actions',
                    'elements' => [
                        [
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Approve'],
                            'style' => 'primary',
                            'action_id' => 'approve_' . $id,
                            'value' => json_encode(['a' => 'approve', 'p' => $id], JSON_THROW_ON_ERROR),
                        ],
                        [
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Reject'],
                            'style' => 'danger',
                            'action_id' => 'reject_' . $id,
                            'value' => json_encode(['a' => 'reject', 'p' => $id], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ];

            $delivered = 0;

            foreach (array_keys($this->config->slackUserMap) as $slackUser) {
                // One person's broken DM (deactivated account, bad member
                // id) must not block the other's, abort the loop so later
                // proposals are never announced, or — by leaving the stamp
                // unset — re-DM the working account every cron tick.
                try {
                    $this->slack->postMessage($slackUser, $text, $blocks);
                    $delivered++;
                } catch (\Throwable) {
                    $failed++;
                }
            }

            // Stamp once anyone got it; if every DM failed (Slack is down),
            // leave the proposal unstamped so the next tick retries.
            if ($delivered > 0) {
                $proposals->markSlackNotified($id);
                $announced++;
            }
        }

        return sprintf(
            'approvals: announced %d%s',
            $announced,
            $failed > 0 ? sprintf(', %d DM(s) failed', $failed) : '',
        );
    }
}
