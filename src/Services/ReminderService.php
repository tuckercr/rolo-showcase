<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * The follow-up rules engine (docs/SCHEMA.md). Pure date math over the
 * reminder_rules rows — cadence lives in the database, not in code, so
 * Jessica can tune it without a deploy.
 *
 * Rules are injected as an array (see ReminderRuleModel::activeByStatus())
 * to keep this class free of I/O and fully unit-testable.
 */
final class ReminderService
{
    /**
     * @param array<string, array{rule_type: string, value_days: int}> $rulesByStatus
     */
    public function __construct(private readonly array $rulesByStatus)
    {
    }

    /**
     * Compute the next touch date, or null when the status has no active rule
     * (e.g. "Closed / Not a Fit" — no reminder is ever generated).
     *
     * @param string $relationshipStatus the contact's CURRENT status — on a
     *        status change, pass the NEW status (status-triggered defaults)
     * @param DateTimeImmutable $referenceDate the triggering event's date:
     *        the activity date when logging, "today" on a status change
     * @param DateTimeImmutable|null $lastTouchDate used by
     *        cadence_from_last_touch rules; falls back to $referenceDate
     * @param int|null $cadenceDaysOverride per-contact override — when set it
     *        replaces the rule's value_days, whatever the rule type
     */
    public function nextTouchDate(
        string $relationshipStatus,
        DateTimeImmutable $referenceDate,
        ?DateTimeImmutable $lastTouchDate = null,
        ?int $cadenceDaysOverride = null,
    ): ?DateTimeImmutable {
        $rule = $this->rulesByStatus[$relationshipStatus] ?? null;

        if ($rule === null) {
            return null;
        }

        $days = $cadenceDaysOverride ?? $rule['value_days'];

        return match ($rule['rule_type']) {
            'fixed_days' => $referenceDate->modify(sprintf('+%d days', $days)),
            'cadence_from_last_touch' => ($lastTouchDate ?? $referenceDate)
                ->modify(sprintf('+%d days', $days)),
            'business_days' => self::addBusinessDays($referenceDate, $days),
            default => null,
        };
    }

    /**
     * Add N business days, skipping Saturdays and Sundays — the result always
     * lands on a weekday (for N >= 1).
     */
    private static function addBusinessDays(DateTimeImmutable $date, int $days): DateTimeImmutable
    {
        $remaining = $days;

        while ($remaining > 0) {
            $date = $date->modify('+1 day');

            if (!self::isWeekend($date)) {
                $remaining--;
            }
        }

        return $date;
    }

    private static function isWeekend(DateTimeImmutable $date): bool
    {
        return (int) $date->format('N') >= 6;
    }
}
