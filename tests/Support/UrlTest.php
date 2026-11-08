<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function urls(): array
    {
        return [
            'https' => ['https://www.linkedin.com/in/someone', true],
            'http' => ['http://example.com', true],
            'scheme case-insensitive' => ['HTTPS://example.com/path?q=1', true],
            'javascript scheme' => ['javascript:alert(1)', false],
            // The FILTER_VALIDATE_URL blind spot this helper exists for:
            // a javascript: URL dressed up with a host part passes it.
            'javascript with host' => ['javascript://example.com/%0aalert(1)', false],
            'data scheme' => ['data:text/html,<script>alert(1)</script>', false],
            'ftp scheme' => ['ftp://example.com/file', false],
            'no scheme' => ['www.example.com', false],
            'empty' => ['', false],
            'garbage' => ['not a url', false],
        ];
    }

    #[DataProvider('urls')]
    public function testIsHttp(string $url, bool $expected): void
    {
        self::assertSame($expected, Url::isHttp($url));
    }
}
