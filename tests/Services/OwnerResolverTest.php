<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\OwnerResolver;
use InvalidArgumentException;
use Tests\Database\DatabaseTestCase;

/**
 * Seeded users: 1 = Colin, 2 = Dana (migration 0002).
 */
final class OwnerResolverTest extends DatabaseTestCase
{
    private function resolver(): OwnerResolver
    {
        return new OwnerResolver(self::db());
    }

    public function testSharedIsNull(): void
    {
        self::assertNull($this->resolver()->resolve('shared', 1));
    }

    public function testMeIsThePersonBehindTheToken(): void
    {
        self::assertSame(2, $this->resolver()->resolve('me', 2));
        self::assertSame(2, $this->resolver()->resolve(' ME ', 2));
    }

    public function testMeWithAnAnonymousTokenIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('per-user token');

        $this->resolver()->resolve('me', null);
    }

    public function testIdAndEmail(): void
    {
        self::assertSame(2, $this->resolver()->resolve('2', null));
        self::assertSame(2, $this->resolver()->resolve('Dana@Example.com', null));
    }

    public function testUnknownEmailIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No user with that email.');

        $this->resolver()->resolve('nobody@example.com', 1);
    }

    public function testAnythingElseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver()->resolve('dana', 1);
    }
}
