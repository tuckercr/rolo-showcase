<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Slack mrkdwn escaping — the Slack-side analogue of htmlspecialchars() on
 * web views. Slack's text formatting treats &, < and > as control characters
 * (<url|label> renders as a live link), so any externally-controlled value
 * (contact names, calendar event titles, activity summaries) interpolated
 * into a mrkdwn text field must pass through esc() first, or an event titled
 * "<https://evil.example|Open the contract>" becomes a disguised link in a
 * DM. Plain_text fields (button labels, modal inputs) are not mrkdwn and
 * must NOT be escaped — entities would show literally.
 */
final class Mrkdwn
{
    public static function esc(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
