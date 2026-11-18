<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\CalendarConnectionModel;
use Tests\Database\DatabaseTestCase;

final class CalendarConnectionModelTest extends DatabaseTestCase
{
    public function testSaveFindReplaceDelete(): void
    {
        $model = new CalendarConnectionModel(self::db());

        self::assertNull($model->findForUser(1));

        $model->save(1, 'Colin@Example.com', 'refresh-1');
        $row = $model->findForUser(1);

        self::assertNotNull($row);
        self::assertSame('colin@example.com', $row['google_email'], 'stored lowercased');
        self::assertSame('refresh-1', $row['refresh_token']);

        // Reconnecting replaces the row (one connection per user).
        $model->save(1, 'other@example.com', 'refresh-2');
        $row = $model->findForUser(1);
        self::assertSame('other@example.com', $row['google_email'] ?? null);
        self::assertSame('refresh-2', $row['refresh_token'] ?? null);

        $model->delete(1);
        self::assertNull($model->findForUser(1));
    }
}
