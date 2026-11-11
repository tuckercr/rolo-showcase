<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\SlackSignature;
use PHPUnit\Framework\TestCase;

final class SlackSignatureTest extends TestCase
{
    private const SECRET = '8f742231b10e8888abcd99yyyzzz85a5';
    private const BODY = 'payload=%7B%22type%22%3A%22block_actions%22%7D';

    private function sign(string $timestamp, string $body, string $secret = self::SECRET): string
    {
        return 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret);
    }

    public function testAcceptsAValidSignature(): void
    {
        $now = 1_700_000_000;
        $ts = (string) $now;

        self::assertTrue(
            SlackSignature::verify(self::SECRET, $ts, self::BODY, $this->sign($ts, self::BODY), now: $now)
        );
    }

    public function testRejectsATamperedBody(): void
    {
        $now = 1_700_000_000;
        $ts = (string) $now;

        self::assertFalse(
            SlackSignature::verify(self::SECRET, $ts, self::BODY . 'x', $this->sign($ts, self::BODY), now: $now)
        );
    }

    public function testRejectsTheWrongSecret(): void
    {
        $now = 1_700_000_000;
        $ts = (string) $now;

        self::assertFalse(
            SlackSignature::verify(self::SECRET, $ts, self::BODY, $this->sign($ts, self::BODY, 'other'), now: $now)
        );
    }

    public function testRejectsAStaleTimestampReplay(): void
    {
        $then = 1_700_000_000;
        $ts = (string) $then;

        self::assertFalse(
            SlackSignature::verify(
                self::SECRET,
                $ts,
                self::BODY,
                $this->sign($ts, self::BODY),
                now: $then + 301,
            )
        );
    }

    public function testRejectsEmptySecretTimestampOrSignature(): void
    {
        $now = 1_700_000_000;
        $ts = (string) $now;
        $sig = $this->sign($ts, self::BODY);

        self::assertFalse(SlackSignature::verify('', $ts, self::BODY, $sig, now: $now));
        self::assertFalse(SlackSignature::verify(self::SECRET, '', self::BODY, $sig, now: $now));
        self::assertFalse(SlackSignature::verify(self::SECRET, $ts, self::BODY, '', now: $now));
        self::assertFalse(SlackSignature::verify(self::SECRET, 'not-a-number', self::BODY, $sig, now: $now));
    }
}
