<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Support\SlackClient;

/**
 * Records what would have been sent to Slack instead of calling the API.
 */
final class RecordingSlackClient extends SlackClient
{
    /** @var list<array{channel: string, text: string, blocks: array<int, mixed>}> */
    public array $messages = [];

    /** @var list<array{url: string, payload: array<string, mixed>}> */
    public array $responses = [];

    public function postMessage(string $channel, string $text, array $blocks = []): void
    {
        $this->messages[] = ['channel' => $channel, 'text' => $text, 'blocks' => $blocks];
    }

    public function respond(string $responseUrl, array $payload): void
    {
        $this->responses[] = ['url' => $responseUrl, 'payload' => $payload];
    }
}
