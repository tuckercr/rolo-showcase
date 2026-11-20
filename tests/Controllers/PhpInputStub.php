<?php

declare(strict_types=1);

namespace Tests\Controllers;

/**
 * Stands in for php://input so a controller that reads the raw request
 * body (Slack's webhooks verify a signature over it) can be driven from a
 * test. Swapped in for the built-in "php" stream wrapper only for the
 * duration of one controller call, see SlackTaskFlowTest::post().
 *
 * The method names are the ones PHP's streamWrapper contract dictates.
 */
// phpcs:disable PSR1.Methods.CamelCapsMethodName
final class PhpInputStub
{
    public static string $body = '';

    /** @var resource|null set by PHP */
    public $context;

    private int $position = 0;

    public function stream_open(): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
