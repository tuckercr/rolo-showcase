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

    /**
     * The reverse trip, for text Slack hands to us (slash-command text):
     * what the person actually typed, as plain text fit to store.
     *
     * Slack delivers that text with &, < and > entity-encoded and with
     * links and mentions wrapped in <...> markup. Saved verbatim, "Send
     * Q&A doc" would be stored as "Send Q&amp;A doc" and then be escaped
     * a second time on the web, in email and back in Slack.
     *
     * A real < typed by the person arrives as &lt;, so any literal <...>
     * left in the text is Slack's markup, and is reduced to its readable
     * part: the label when there is one (for an auto-linked "example.com"
     * that is exactly what was typed), otherwise the URL, address or id.
     *
     *   <https://example.com/q4|the Q4 doc>  the Q4 doc
     *   <https://example.com/q4>             https://example.com/q4
     *   <mailto:dana@example.com>            dana@example.com
     *   <@U123|dana>  <#C123|general>        @dana  #general
     *   <@U123>  <!here>                     @U123  @here
     *
     * Entities are decoded last, &amp; last of all, so "&amp;lt;" (a typed
     * "&lt;") comes out as "&lt;" rather than being decoded twice.
     */
    public static function toPlain(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/<([^<>|]+)(?:\|([^<>]*))?>/',
            static function (array $m): string {
                $target = $m[1];
                $label = $m[2] ?? '';
                $sigil = match ($target[0]) {
                    '@', '!' => '@',
                    '#' => '#',
                    default => '',
                };

                if ($label !== '') {
                    return $sigil !== '' && !str_starts_with($label, $sigil) ? $sigil . $label : $label;
                }

                if ($sigil !== '') {
                    // <!subteam^S123> and <!date^...> carry arguments after ^.
                    return $sigil . explode('^', substr($target, 1))[0];
                }

                return (string) preg_replace('/^(?:mailto|tel):/i', '', $target);
            },
            $text,
        );

        return str_replace(['&lt;', '&gt;', '&amp;'], ['<', '>', '&'], $text);
    }
}
