<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Slack request-signature verification (the "signing secrets" scheme):
 * Slack sends X-Slack-Signature = "v0=" . HMAC-SHA256 over
 * "v0:<timestamp>:<raw body>". Verifying it proves the request came from
 * Slack and nobody else can press the bot's buttons.
 */
final class SlackSignature
{
    /** Reject requests older than this many seconds (replay protection). */
    private const TOLERANCE_SECONDS = 300;

    public static function verify(
        string $signingSecret,
        string $timestamp,
        string $rawBody,
        string $signature,
        ?int $now = null,
    ): bool {
        if ($signingSecret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        if (!ctype_digit($timestamp)) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = 'v0=' . hash_hmac('sha256', sprintf('v0:%s:%s', $timestamp, $rawBody), $signingSecret);

        return hash_equals($expected, $signature);
    }
}
