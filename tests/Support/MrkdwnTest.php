<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Mrkdwn;
use PHPUnit\Framework\TestCase;

final class MrkdwnTest extends TestCase
{
    public function testEscapesSlackControlCharacters(): void
    {
        self::assertSame('Jones &amp; Co', Mrkdwn::esc('Jones & Co'));
        self::assertSame('a &lt;b&gt; c', Mrkdwn::esc('a <b> c'));
        self::assertSame('plain name', Mrkdwn::esc('plain name'));
    }

    public function testDisarmsAnInjectedLink(): void
    {
        // A calendar event titled like this must not render as a live
        // disguised link in the debrief (Slack renders <url|label>).
        $escaped = Mrkdwn::esc('<https://evil.example|Open the contract>');

        self::assertSame('&lt;https://evil.example|Open the contract&gt;', $escaped);
        self::assertStringNotContainsString('<', $escaped);
    }

    public function testDoesNotDoubleEscape(): void
    {
        // esc() is applied once at the interpolation site; a literal & in a
        // name becomes &amp; exactly once, which Slack renders back as &.
        self::assertSame('&amp;amp;', Mrkdwn::esc('&amp;'));
    }

    public function testToPlainDecodesWhatSlackEncoded(): void
    {
        self::assertSame('Send Q&A doc', Mrkdwn::toPlain('Send Q&amp;A doc'));
        self::assertSame('Check a < b > c', Mrkdwn::toPlain('Check a &lt; b &gt; c'));
        self::assertSame('plain text', Mrkdwn::toPlain('plain text'));
    }

    public function testToPlainDecodesExactlyOnce(): void
    {
        // Someone who really typed "&lt;" gets it back, not a "<".
        self::assertSame('Explain &lt; to the intern', Mrkdwn::toPlain('Explain &amp;lt; to the intern'));
        self::assertSame('&amp;', Mrkdwn::toPlain('&amp;amp;'));
    }

    public function testToPlainUndoesEsc(): void
    {
        foreach (['Jones & Co', 'a <b> c', '<https://example.com|label>', '&amp; &lt;'] as $typed) {
            self::assertSame($typed, Mrkdwn::toPlain(Mrkdwn::esc($typed)), $typed);
        }
    }

    public function testToPlainReducesLinksToWhatWasTyped(): void
    {
        self::assertSame(
            'Read the Q4 doc today',
            Mrkdwn::toPlain('Read <https://example.com/q4?a=1&amp;b=2|the Q4 doc> today'),
        );
        self::assertSame(
            'Read https://example.com/q4?a=1&b=2 today',
            Mrkdwn::toPlain('Read <https://example.com/q4?a=1&amp;b=2> today'),
        );
        // Slack auto-links a bare domain; the label is what was typed.
        self::assertSame('Renew example.com', Mrkdwn::toPlain('Renew <http://example.com|example.com>'));
        self::assertSame('Email dana@example.com', Mrkdwn::toPlain('Email <mailto:dana@example.com>'));
        self::assertSame(
            'Email dana@example.com',
            Mrkdwn::toPlain('Email <mailto:dana@example.com|dana@example.com>'),
        );
    }

    public function testToPlainReducesMentionsToNames(): void
    {
        self::assertSame('Ask @dana in #general', Mrkdwn::toPlain('Ask <@U123ABC|dana> in <#C123ABC|general>'));
        self::assertSame('Ask @U123ABC in #C123ABC', Mrkdwn::toPlain('Ask <@U123ABC> in <#C123ABC>'));
        self::assertSame('Tell @here and @channel', Mrkdwn::toPlain('Tell <!here> and <!channel|channel>'));
        self::assertSame('Ping @design-team', Mrkdwn::toPlain('Ping <!subteam^S123ABC|@design-team>'));
        self::assertSame('Ping @subteam', Mrkdwn::toPlain('Ping <!subteam^S123ABC>'));
    }
}
