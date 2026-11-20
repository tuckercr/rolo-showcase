<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\UserModel;
use Tests\Database\DatabaseTestCase;

/**
 * The assignable() allowlist filter decides who can own contacts: retired
 * identities in the users table must stay out of the Owner dropdown while
 * keeping their attribution rows.
 */
final class UserModelTest extends DatabaseTestCase
{
    public function testAssignableFiltersByTheAllowlist(): void
    {
        // Seeded users (migration 0002): colin@example.com and
        // dana@example.com. Allowlist only Dana.
        $names = array_column(
            (new UserModel(self::db()))->assignable(['dana@example.com']),
            'name',
        );

        self::assertSame(['Dana'], $names);
    }

    public function testAssignableFallsBackToEveryoneWithoutAnAllowlist(): void
    {
        $rows = (new UserModel(self::db()))->assignable([]);

        self::assertCount(2, $rows, 'an empty allowlist (bare local dev) offers every user');
    }
}
