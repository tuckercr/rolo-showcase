<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Parses one typed line into a task: the text after "/rolo task".
 *
 *   [for <contact>:] <title> [by|on|due] [<when>]
 *
 * <when> is the LAST words of the line: today, tomorrow, a weekday name
 * (the next one after today), "next week", "in N days", "in N weeks", or
 * a YYYY-MM-DD date. Anything else leaves the task undated.
 *
 * A title that happens to end in one of those words ("Call the venue about
 * Friday") would lose it to the due date, so a double-quoted title is
 * taken verbatim, with an optional <when> after the closing quote. That is
 * the same escape hatch /rolo snooze uses for names ending in a number.
 *
 * Pure: resolving the contact name against the database is the caller's
 * job. $today is today in APP_TIMEZONE.
 */
final class TaskQuickAdd
{
    private const WEEKDAYS = [
        'mon' => 1, 'monday' => 1,
        'tue' => 2, 'tues' => 2, 'tuesday' => 2,
        'wed' => 3, 'wednesday' => 3,
        'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4,
        'fri' => 5, 'friday' => 5,
        'sat' => 6, 'saturday' => 6,
        'sun' => 7, 'sunday' => 7,
    ];

    /**
     * @return array{title: string, due_date: ?string, contact_query: ?string}|null
     *         null when there is no title to save
     */
    public static function parse(string $text, DateTimeImmutable $today): ?array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $contactQuery = null;

        if (preg_match('/^for\s+([^:"]{1,100}):\s*(.+)$/iu', $text, $m) === 1) {
            $contactQuery = trim($m[1]);
            $text = trim($m[2]);
        }

        // Slack's desktop client may turn straight quotes into curly ones.
        $text = str_replace(["\u{201C}", "\u{201D}"], '"', $text);

        if (preg_match('/^"(.*?)"\s*(.*)$/u', $text, $m) === 1) {
            $title = trim($m[1]);
            $due = self::parseWhen(self::stripConnector(trim($m[2])), $today);
        } else {
            [$title, $due] = self::splitTrailingWhen($text, $today);
        }

        if ($title === '') {
            return null;
        }

        return [
            'title' => $title,
            'due_date' => $due,
            'contact_query' => $contactQuery === '' ? null : $contactQuery,
        ];
    }

    /**
     * Peel a due phrase off the end of an unquoted line. Tries the longest
     * phrase first ("in 3 days" before "days").
     *
     * @return array{0: string, 1: ?string} [title, due date]
     */
    private static function splitTrailingWhen(string $text, DateTimeImmutable $today): array
    {
        $words = explode(' ', $text);

        foreach ([3, 2, 1] as $take) {
            if (count($words) <= $take) {
                continue;
            }

            $due = self::parseWhen(implode(' ', array_slice($words, -$take)), $today);

            if ($due === null) {
                continue;
            }

            $title = self::stripTrailingConnector(implode(' ', array_slice($words, 0, -$take)));

            // "by friday" alone is not a task.
            return $title === '' ? [$text, null] : [$title, $due];
        }

        return [$text, null];
    }

    /**
     * A due phrase to a Y-m-d date, or null when it is not one.
     */
    public static function parseWhen(string $phrase, DateTimeImmutable $today): ?string
    {
        $phrase = strtolower(trim($phrase));

        if ($phrase === '') {
            return null;
        }

        if ($phrase === 'today') {
            return $today->format('Y-m-d');
        }

        if ($phrase === 'tomorrow') {
            return $today->modify('+1 day')->format('Y-m-d');
        }

        if ($phrase === 'next week') {
            return $today->modify('+7 days')->format('Y-m-d');
        }

        if (isset(self::WEEKDAYS[$phrase])) {
            // The next such day strictly after today: "friday" said on a
            // Friday means next week's, and "today" covers today.
            $ahead = (self::WEEKDAYS[$phrase] - (int) $today->format('N') + 7) % 7;

            return $today->modify(sprintf('+%d days', $ahead === 0 ? 7 : $ahead))->format('Y-m-d');
        }

        if (preg_match('/^in (\d{1,3}) (day|days|week|weeks)$/', $phrase, $m) === 1) {
            $days = (int) $m[1] * (str_starts_with($m[2], 'week') ? 7 : 1);

            return $days > 730 ? null : $today->modify(sprintf('+%d days', $days))->format('Y-m-d');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $phrase) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $phrase);

            return $parsed !== false && $parsed->format('Y-m-d') === $phrase ? $phrase : null;
        }

        return null;
    }

    /** "by friday" -> "friday" (the phrase after a quoted title). */
    private static function stripConnector(string $phrase): string
    {
        return (string) preg_replace('/^(?:by|on|due)\s+/i', '', $phrase);
    }

    /** "Send the deck by" -> "Send the deck". */
    private static function stripTrailingConnector(string $title): string
    {
        return trim((string) preg_replace('/\s+(?:by|on|due)$/i', '', ' ' . $title));
    }
}
