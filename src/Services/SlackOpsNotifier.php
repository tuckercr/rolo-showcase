<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\SlackClient;

/**
 * DMs devops notices (deploys, migrations, failures) to SLACK_OPS_USERS.
 * Separate from the debrief list because Dana cares about follow-ups,
 * not server plumbing; the list is normally just Colin.
 *
 * Every send is best-effort: an ops notice exists to describe an operation,
 * so it must never be able to fail one. notify() swallows everything and
 * reports what happened as a string for the caller's own log output.
 */
final class SlackOpsNotifier
{
    public function __construct(
        private readonly Config $config,
        private readonly SlackClient $slack,
    ) {
    }

    /**
     * @return string human-readable outcome (for the caller's text output)
     */
    public function notify(string $text): string
    {
        if ($this->config->slackBotToken === '' || $this->config->slackOpsUsers === []) {
            return 'ops notice skipped: SLACK_OPS_USERS is not configured';
        }

        $sent = 0;
        $failed = 0;

        foreach ($this->config->slackOpsUsers as $slackUser) {
            try {
                $this->slack->postMessage($slackUser, $text);
                $sent++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        return sprintf('ops notice: sent %d%s', $sent, $failed > 0 ? ", failed {$failed}" : '');
    }
}
