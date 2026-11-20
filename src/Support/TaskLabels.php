<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * How a task's dates read to a person ("due today", "due Nov 18 (2 days
 * late)", "done yesterday"). One wording for the web pages, the daily
 * email and the Slack bot, kept pure so the day arithmetic is testable.
 *
 * $today is always "today" in APP_TIMEZONE as Y-m-d, computed by the
 * caller, the same convention as the follow-up queries.
 */
final class TaskLabels
{
    public static function isOverdue(?string $dueDate, string $today): bool
    {
        return $dueDate !== null && $dueDate !== '' && $dueDate < $today;
    }

    /**
     * '' when the task has no due date.
     */
    public static function due(?string $dueDate, string $today, DateTimeZone $zone): string
    {
        if ($dueDate === null || $dueDate === '') {
            return '';
        }

        if ($dueDate === $today) {
            return 'due today';
        }

        $todayDate = new DateTimeImmutable($today, $zone);

        if ($dueDate === $todayDate->modify('+1 day')->format('Y-m-d')) {
            return 'due tomorrow';
        }

        $label = 'due ' . self::shortDate($dueDate, $today, $zone);

        if ($dueDate < $today) {
            $late = Dates::daysBetween($dueDate, $today, $zone);
            $label .= sprintf(' (%d day%s late)', $late, $late === 1 ? '' : 's');
        }

        return $label;
    }

    /**
     * $completedAt is the stored UTC DATETIME; the day it reads as is the
     * day it was ticked in the app's timezone.
     */
    public static function done(string $completedAt, string $today, DateTimeZone $zone): string
    {
        $doneDay = (new DateTimeImmutable($completedAt, new DateTimeZone('UTC')))
            ->setTimezone($zone)
            ->format('Y-m-d');

        if ($doneDay === $today) {
            return 'done today';
        }

        if ($doneDay === (new DateTimeImmutable($today, $zone))->modify('-1 day')->format('Y-m-d')) {
            return 'done yesterday';
        }

        return 'done ' . self::shortDate($doneDay, $today, $zone);
    }

    /**
     * "Nov 24", with the year only when it is not this year.
     */
    public static function shortDate(string $date, string $today, DateTimeZone $zone): string
    {
        $parsed = new DateTimeImmutable($date, $zone);

        return $parsed->format(substr($date, 0, 4) === substr($today, 0, 4) ? 'M j' : 'M j, Y');
    }

    /**
     * Add the display fields every surface needs to TaskModel rows:
     * due_label, is_overdue, and done_label ('' while the task is open).
     *
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    public static function decorate(array $tasks, string $today, DateTimeZone $zone): array
    {
        foreach ($tasks as $i => $task) {
            $due = $task['due_date'] === null ? null : (string) $task['due_date'];
            $done = $task['completed_at'] ?? null;

            $tasks[$i]['due_label'] = self::due($due, $today, $zone);
            $tasks[$i]['is_overdue'] = $done === null && self::isOverdue($due, $today);
            $tasks[$i]['done_label'] = $done === null ? '' : self::done((string) $done, $today, $zone);
        }

        return $tasks;
    }
}
