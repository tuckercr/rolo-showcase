<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\OwnerLens;
use PHPUnit\Framework\TestCase;

final class OwnerLensTest extends TestCase
{
    public function testMineIsOwnPlusShared(): void
    {
        self::assertSame(
            '(t.owner_id = :for_user OR t.owner_id IS NULL)',
            OwnerLens::mine('t.owner_id', 'for_user'),
        );
    }

    public function testFilterSharedMatchesUnownedRowsOnly(): void
    {
        self::assertSame(['c.owner_id IS NULL', []], OwnerLens::filter('c.owner_id', 'shared'));
    }

    public function testFilterByUserBindsTheIdNeverInterpolatesIt(): void
    {
        self::assertSame(
            ['c.owner_id = :owner_id', ['owner_id' => 7]],
            OwnerLens::filter('c.owner_id', '7'),
        );
        self::assertSame(
            ['t.owner_id = :who', ['who' => 7]],
            OwnerLens::filter('t.owner_id', '7', 'who'),
        );
    }

    public function testAnythingElseMeansAnyone(): void
    {
        self::assertNull(OwnerLens::filter('c.owner_id', ''));
        self::assertNull(OwnerLens::filter('c.owner_id', 'me'), 'words are resolved before they get here');
        self::assertNull(OwnerLens::filter('c.owner_id', '1 OR 1=1'));
    }
}
