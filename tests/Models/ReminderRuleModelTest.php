<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\ReminderRuleModel;
use Tests\Database\DatabaseTestCase;

final class ReminderRuleModelTest extends DatabaseTestCase
{
    private ReminderRuleModel $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new ReminderRuleModel(self::db());
    }

    public function testSaveForStatusUpsertsOneRulePerStage(): void
    {
        $this->rules->saveForStatus('Researching', 'fixed_days', 10, 1);
        $this->rules->saveForStatus('Researching', 'business_days', 3, 2);

        $active = $this->rules->activeByStatus();

        self::assertSame('business_days', $active['Researching']['rule_type']);
        self::assertSame(3, $active['Researching']['value_days']);
    }

    public function testDeleteForStatusMeansNoReminder(): void
    {
        $this->rules->deleteForStatus('Researching');

        self::assertArrayNotHasKey('Researching', $this->rules->activeByStatus());
    }

    public function testResetToDefaultsRestoresTheFullSopRuleSet(): void
    {
        $this->rules->deleteForStatus('Researching');
        $this->rules->saveForStatus('Dormant', 'fixed_days', 5, 1);

        $this->rules->resetToDefaults(1);

        $active = $this->rules->activeByStatus();
        foreach (ReminderRuleModel::DEFAULTS as $status => $rule) {
            self::assertSame($rule['rule_type'], $active[$status]['rule_type'], $status);
            self::assertSame($rule['value_days'], $active[$status]['value_days'], $status);
        }
        self::assertCount(count(ReminderRuleModel::DEFAULTS), $active);
    }
}
