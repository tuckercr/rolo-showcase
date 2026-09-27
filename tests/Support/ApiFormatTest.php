<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\ApiFormat;
use PHPUnit\Framework\TestCase;

final class ApiFormatTest extends TestCase
{
    public function testCursorRoundTrips(): void
    {
        $cursor = ApiFormat::encodeCursor(42);

        $this->assertSame(42, ApiFormat::decodeCursor($cursor));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor, 'URL-safe');
    }

    public function testInvalidCursorsStartOver(): void
    {
        $this->assertSame(0, ApiFormat::decodeCursor(null));
        $this->assertSame(0, ApiFormat::decodeCursor(''));
        $this->assertSame(0, ApiFormat::decodeCursor('garbage!!'));
        $this->assertSame(0, ApiFormat::decodeCursor(base64_encode('v2:99')));
        $this->assertSame(0, ApiFormat::decodeCursor(base64_encode('v1:not-a-number')));
    }

    public function testIsoDateTimeFormatsUtcDatetimes(): void
    {
        $this->assertSame('2026-09-09T14:00:00Z', ApiFormat::isoDateTime('2026-09-09 14:00:00'));
        $this->assertSame('2026-09-09', ApiFormat::isoDateTime('2026-09-09'), 'dates pass through');
        $this->assertNull(ApiFormat::isoDateTime(null));
        $this->assertNull(ApiFormat::isoDateTime(''));
    }

    public function testClampLimit(): void
    {
        $this->assertSame(25, ApiFormat::clampLimit(null));
        $this->assertSame(25, ApiFormat::clampLimit('abc'));
        $this->assertSame(1, ApiFormat::clampLimit('0'));
        $this->assertSame(100, ApiFormat::clampLimit('9999'));
        $this->assertSame(50, ApiFormat::clampLimit('50'));
    }
}
