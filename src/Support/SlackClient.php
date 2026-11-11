<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Minimal Slack Web API client — chat.postMessage is all the bot needs to
 * send (button clicks are answered inline in the webhook response). Raw curl
 * in the same style as GoogleAuthService::fetchIdToken; no SDK for one call.
 */
class SlackClient
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * DM a user (channel = a U… member id works with the im:write scope) or
     * post to a channel id.
     *
     * @param list<array<string, mixed>> $blocks Block Kit blocks; $text is
     *        the notification fallback and the whole message when empty
     */
    public function postMessage(string $channel, string $text, array $blocks = []): void
    {
        if ($this->config->slackBotToken === '') {
            throw new RuntimeException('SLACK_BOT_TOKEN is not configured.');
        }

        $payload = ['channel' => $channel, 'text' => $text];

        if ($blocks !== []) {
            $payload['blocks'] = $blocks;
        }

        $ch = curl_init('https://slack.com/api/chat.postMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Bearer ' . $this->config->slackBotToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($response === false) {
            throw new RuntimeException('Slack request failed: ' . $error);
        }

        if ($status !== 200) {
            throw new RuntimeException('Slack returned HTTP ' . $status);
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Slack error: ' . (string) ($decoded['error'] ?? 'unparseable response')
            );
        }
    }

    /**
     * Open a modal in response to an interaction (views.open). The trigger_id
     * from the tap is only valid for ~3 seconds, so call this promptly.
     *
     * @param array<string, mixed> $view a Block Kit modal view
     */
    public function openModal(string $triggerId, array $view): void
    {
        if ($this->config->slackBotToken === '') {
            throw new RuntimeException('SLACK_BOT_TOKEN is not configured.');
        }

        $ch = curl_init('https://slack.com/api/views.open');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Bearer ' . $this->config->slackBotToken,
            ],
            CURLOPT_POSTFIELDS => json_encode(
                ['trigger_id' => $triggerId, 'view' => $view],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($response === false || $status !== 200) {
            throw new RuntimeException('Slack views.open returned HTTP ' . $status);
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Slack error: ' . (string) ($decoded['error'] ?? 'unparseable response')
            );
        }
    }

    /**
     * Answer an interaction via its response_url (replace or append to the
     * original message). No auth token needed — the URL itself is the grant.
     *
     * @param array<string, mixed> $payload e.g. ['replace_original' => true,
     *        'text' => …, 'blocks' => […]]
     */
    public function respond(string $responseUrl, array $payload): void
    {
        $ch = curl_init($responseUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($response === false || $status !== 200) {
            throw new RuntimeException('Slack response_url returned HTTP ' . $status);
        }
    }
}
