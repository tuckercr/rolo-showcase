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
}
