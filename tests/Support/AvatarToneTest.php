<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\AvatarTone;
use PHPUnit\Framework\TestCase;

final class AvatarToneTest extends TestCase
{
    public function testToneIsDeterministicAndNormalized(): void
    {
        $tone = AvatarTone::for('Maria Santos');

        self::assertSame($tone, AvatarTone::for('Maria Santos'));
        self::assertSame($tone, AvatarTone::for('  maria santos  '), 'case and whitespace must not change the tone');
        self::assertSame($tone, AvatarTone::for('MARIA SANTOS'));
    }

    public function testToneIsAlwaysAUsableColorPair(): void
    {
        foreach (['A', 'Jean-Luc Picard', 'Åsa Öberg', str_repeat('x', 300)] as $name) {
            $tone = AvatarTone::for($name);

            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $tone['bg']);
            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $tone['text']);
        }
    }

    public function testInitialsUseTheFirstTwoWords(): void
    {
        self::assertSame('MS', AvatarTone::initials('Maria Santos'));
        self::assertSame('ME', AvatarTone::initials('Maria Elena Santos'));
        self::assertSame('MS', AvatarTone::initials('maria santos'));
    }

    public function testInitialsForSingleWordAndEmptyNames(): void
    {
        self::assertSame('A', AvatarTone::initials('ann'));
        self::assertSame('?', AvatarTone::initials(''));
        self::assertSame('?', AvatarTone::initials('   '));
    }

    public function testInitialsHandleMultibyteNames(): void
    {
        self::assertSame('ÅÖ', AvatarTone::initials('Åsa Öberg'));
    }
}
