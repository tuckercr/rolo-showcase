<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Config;
use App\Support\PhotoLink;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class PhotoLinkTest extends TestCase
{
    private function config(string $cronSecret = 'shhh', string $appUrl = 'https://crm.example.com'): Config
    {
        return new Config(
            appEnv: 'test',
            appTimezone: new DateTimeZone('America/New_York'),
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
            appUrl: $appUrl,
            cronSecret: $cronSecret,
        );
    }

    public function testUrlAndVerifyRoundTrip(): void
    {
        $contact = ['id' => 7, 'name' => 'Pat Doyle', 'photo' => 'ab12cd.jpg'];
        $url = PhotoLink::url($this->config(), $contact);

        self::assertNotNull($url);
        self::assertMatchesRegularExpression('#^https://crm\.example\.com/photo/7/[a-f0-9]{64}$#', $url);

        $sig = substr($url, strrpos($url, '/') + 1);
        self::assertTrue(PhotoLink::verify($this->config(), 7, 'ab12cd.jpg', $sig));
    }

    public function testVerifyRejectsTamperingAndStaleLinks(): void
    {
        $sig = (string) PhotoLink::sig($this->config(), 7, 'ab12cd.jpg');

        self::assertFalse(PhotoLink::verify($this->config(), 8, 'ab12cd.jpg', $sig), 'other contact');
        self::assertFalse(
            PhotoLink::verify($this->config(), 7, 'replaced.jpg', $sig),
            'a replaced photo kills the old link',
        );
        self::assertFalse(PhotoLink::verify($this->config(), 7, 'ab12cd.jpg', strrev($sig)), 'mangled sig');
    }

    public function testQuietlyOffWithoutSecretPhotoOrAppUrl(): void
    {
        $contact = ['id' => 7, 'name' => 'Pat Doyle', 'photo' => 'ab12cd.jpg'];

        self::assertNull(PhotoLink::url($this->config(cronSecret: ''), $contact));
        self::assertNull(PhotoLink::url($this->config(appUrl: ''), $contact));
        self::assertNull(PhotoLink::url($this->config(), ['id' => 7, 'name' => 'No Photo']));
        self::assertFalse(PhotoLink::verify($this->config(cronSecret: ''), 7, 'ab12cd.jpg', str_repeat('a', 64)));
    }

    public function testAccessoryIsASlackImageBlock(): void
    {
        $accessory = PhotoLink::accessory(
            $this->config(),
            ['id' => 7, 'name' => 'Pat Doyle', 'photo' => 'ab12cd.jpg'],
        );

        self::assertNotNull($accessory);
        self::assertSame('image', $accessory['type']);
        self::assertSame('Pat Doyle', $accessory['alt_text']);
        self::assertStringStartsWith('https://crm.example.com/photo/7/', (string) $accessory['image_url']);
    }
}
