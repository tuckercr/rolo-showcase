<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\StageModel;
use Tests\Database\DatabaseTestCase;

final class StageModelTest extends DatabaseTestCase
{
    public function testDefaultHiddenStagesAreLeadsAndClosed(): void
    {
        self::assertSame(['Closed / Not a Fit', 'Leads'], $this->hiddenStageNames());
    }

    public function testResetHiddenToDefaultRestoresExactlyTheDefaultPair(): void
    {
        $stages = new StageModel(self::db());
        $pdo = self::db()->pdo();

        // Scramble every flag, then reset.
        $pdo->exec('UPDATE stages SET hidden_from_follow_ups = 1');
        $stages->resetHiddenToDefault();
        self::assertSame(['Closed / Not a Fit', 'Leads'], $this->hiddenStageNames());

        $pdo->exec('UPDATE stages SET hidden_from_follow_ups = 0');
        $stages->resetHiddenToDefault();
        self::assertSame(['Closed / Not a Fit', 'Leads'], $this->hiddenStageNames());
    }

    /**
     * @return list<string>
     */
    private function hiddenStageNames(): array
    {
        return self::db()->pdo()
            ->query('SELECT name FROM stages WHERE hidden_from_follow_ups = 1 ORDER BY name')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }
}
