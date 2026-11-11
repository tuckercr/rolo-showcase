<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\SlackClient;

/**
 * A small party in the DMs when a contact reaches Active client — fired from
 * both stage-change paths (the web edit and an approved agent proposal).
 * Celebrating must never break the save, so every Slack call is swallowed.
 */
final class SlackCelebrationService
{
    /** Hardcoded stage name, same precedent as ContactModel::goingCold(). */
    public const CELEBRATED_STAGE = 'Active client';

    public function __construct(
        private readonly Config $config,
        private readonly SlackClient $slack,
    ) {
    }

    public function maybeCelebrate(
        string $oldStage,
        string $newStage,
        int $contactId,
        string $name,
        ?string $orgName,
    ): void {
        if (
            $this->config->slackBotToken === ''
            || $this->config->slackUserMap === []
            || $newStage !== self::CELEBRATED_STAGE
            || $oldStage === self::CELEBRATED_STAGE
        ) {
            return;
        }

        $url = $this->config->appUrl !== '' ? $this->config->appUrl : 'https://crm.example.com';
        $text = sprintf(
            ':tada: %s%s is now an Active client!',
            $name,
            ($orgName ?? '') !== '' ? ' (' . $orgName . ')' : '',
        );

        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => '*' . $text . '*'],
            'accessory' => [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Open'],
                'url' => sprintf('%s/contacts/%d', $url, $contactId),
                'action_id' => 'open_' . $contactId,
                'value' => '{"a":"open"}',
            ],
        ]];

        foreach (array_keys($this->config->slackUserMap) as $slackUser) {
            try {
                $this->slack->postMessage($slackUser, $text, $blocks);
            } catch (\Throwable) {
                // A failed celebration is nothing; the stage change already saved.
            }
        }
    }
}
