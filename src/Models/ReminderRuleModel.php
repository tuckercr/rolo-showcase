<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

final class ReminderRuleModel
{
    public const RULE_TYPES = [
        'fixed_days' => 'Days after the triggering event',
        'cadence_from_last_touch' => 'Days after last touch (ongoing cadence)',
        'business_days' => 'Business days (skips weekends)',
    ];

    /**
     * Jessica's SOP cadence numbers — the same values seeded by migration
     * 0002 and restored by "Reset to defaults" on the settings page.
     * A stage absent here (Closed / Not a Fit) gets no rule = no reminder.
     */
    public const DEFAULTS = [
        'Leads' => ['rule_type' => 'fixed_days', 'value_days' => 0],
        'New/Captured' => ['rule_type' => 'fixed_days', 'value_days' => 3],
        'Researching' => ['rule_type' => 'fixed_days', 'value_days' => 7],
        'Intro Sent' => ['rule_type' => 'fixed_days', 'value_days' => 7],
        'Conversation Started' => ['rule_type' => 'fixed_days', 'value_days' => 14],
        'Relationship Building' => ['rule_type' => 'cadence_from_last_touch', 'value_days' => 30],
        'Opportunity Identified' => ['rule_type' => 'fixed_days', 'value_days' => 5],
        'Proposal / Pitch' => ['rule_type' => 'business_days', 'value_days' => 5],
        'Active client' => ['rule_type' => 'cadence_from_last_touch', 'value_days' => 30],
        'Dormant' => ['rule_type' => 'fixed_days', 'value_days' => 180],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Create or replace the rule for a stage (settings page save).
     */
    public function saveForStatus(string $status, string $ruleType, int $valueDays, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO reminder_rules (relationship_status, rule_type, value_days, is_active, updated_by)
             VALUES (:status, :rule_type, :value_days, 1, :updated_by)
             ON DUPLICATE KEY UPDATE
                rule_type = VALUES(rule_type),
                value_days = VALUES(value_days),
                is_active = 1,
                updated_by = VALUES(updated_by)'
        );
        $stmt->execute([
            'status' => $status,
            'rule_type' => $ruleType,
            'value_days' => $valueDays,
            'updated_by' => $userId,
        ]);
    }

    /**
     * "No reminder" for a stage — the rule row is removed entirely.
     */
    public function deleteForStatus(string $status): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM reminder_rules WHERE relationship_status = :status'
        );
        $stmt->execute(['status' => $status]);
    }

    /**
     * Wipe all rules and restore the SOP defaults.
     */
    public function resetToDefaults(int $userId): void
    {
        $this->db->pdo()->exec('DELETE FROM reminder_rules');

        foreach (self::DEFAULTS as $status => $rule) {
            $this->saveForStatus($status, $rule['rule_type'], $rule['value_days'], $userId);
        }
    }

    /**
     * Active rules keyed by relationship status — the shape ReminderService
     * consumes. A status with no entry generates no reminder.
     *
     * @return array<string, array{rule_type: string, value_days: int}>
     */
    public function activeByStatus(): array
    {
        $rows = $this->db->pdo()
            ->query('SELECT relationship_status, rule_type, value_days FROM reminder_rules WHERE is_active = 1')
            ->fetchAll();

        $byStatus = [];

        foreach ($rows as $row) {
            $byStatus[(string) $row['relationship_status']] = [
                'rule_type' => (string) $row['rule_type'],
                'value_days' => (int) $row['value_days'],
            ];
        }

        return $byStatus;
    }
}
