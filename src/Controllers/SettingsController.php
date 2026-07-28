<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ReminderRuleModel;
use App\Models\StageModel;

final class SettingsController extends Controller
{
    private const MAX_DAYS = 365;

    public function index(): string
    {
        return $this->renderSettings(errors: [], saved: ($_GET['saved'] ?? '') === '1');
    }

    public function save(): string
    {
        $this->requireValidCsrf();

        $rules = new ReminderRuleModel($this->db);
        $stages = (new StageModel($this->db))->allOrdered();

        $postedTypes = is_array($_POST['rule_type'] ?? null) ? $_POST['rule_type'] : [];
        $postedDays = is_array($_POST['value_days'] ?? null) ? $_POST['value_days'] : [];

        $errors = [];
        $changes = [];

        foreach ($stages as $stage) {
            $stageId = (int) $stage['id'];
            $stageName = (string) $stage['name'];

            $type = (string) ($postedTypes[$stageId] ?? 'none');

            if ($type === 'none') {
                $changes[] = static fn() => $rules->deleteForStatus($stageName);
                continue;
            }

            if (!array_key_exists($type, ReminderRuleModel::RULE_TYPES)) {
                $errors[$stageId] = 'Unknown reminder type.';
                continue;
            }

            $days = filter_var((string) ($postedDays[$stageId] ?? ''), FILTER_VALIDATE_INT);

            if ($days === false || $days < 1 || $days > self::MAX_DAYS) {
                $errors[$stageId] = sprintf('Days must be between 1 and %d.', self::MAX_DAYS);
                continue;
            }

            $userId = $this->auth->id();
            $changes[] = static fn() => $rules->saveForStatus($stageName, $type, $days, $userId);
        }

        if ($errors !== []) {
            // Apply nothing when any row is invalid — all-or-nothing keeps
            // the rule set coherent.
            return $this->renderSettings($errors, saved: false);
        }

        foreach ($changes as $apply) {
            $apply();
        }

        return $this->redirect('/settings?saved=1');
    }

    public function reset(): string
    {
        $this->requireValidCsrf();

        (new ReminderRuleModel($this->db))->resetToDefaults($this->auth->id());

        return $this->redirect('/settings?saved=1');
    }

    /**
     * @param array<int, string> $errors keyed by stage id
     */
    private function renderSettings(array $errors, bool $saved): string
    {
        return $this->render('settings/index', [
            'pageTitle' => 'Settings',
            'stages' => (new StageModel($this->db))->allOrdered(),
            'rulesByStatus' => (new ReminderRuleModel($this->db))->activeByStatus(),
            'ruleTypes' => ReminderRuleModel::RULE_TYPES,
            'defaults' => ReminderRuleModel::DEFAULTS,
            'errors' => $errors,
            'saved' => $saved,
            'maxDays' => self::MAX_DAYS,
        ]);
    }
}
