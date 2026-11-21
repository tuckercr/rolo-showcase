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
 * Reading the end of a sentence as a date is a guess, so an unquoted line
 * is read conservatively (see splitTrailingWhen): a weekday abbreviation
 * that is also an everyday word needs by/on/due in front of it, "on" is
 * only dropped before a day or a date, and a date that would leave the
 * title hanging mid-phrase ("Plan for next week") is left in the title.
 *
 * A title that would still lose a date word it should keep ("Team lunch
 * friday") can be double-quoted: a quoted title is taken verbatim, with an
 * optional <when> after the closing quote. That is the same escape hatch
 * /rolo snooze uses for names ending in a number. Quotes only count when
 * what follows them is empty or a <when>; otherwise the line is read as
 * an ordinary one, so nothing typed is ever dropped.
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
     * Abbreviations that are also everyday words ("where the proofs sat",
     * "out in the sun"). Unquoted, they are a date only after by/on/due.
     */
    private const AMBIGUOUS_WEEKDAYS = ['mon', 'wed', 'sat', 'sun'];

    /**
     * Words a title cannot end on. When peeling the date off would leave
     * one of these last ("Plan for", "Call the venue about"), the date
     * words were part of the sentence, not a due date.
     */
    private const DANGLING = [
        'a', 'an', 'the', 'this', 'next', 'last', 'every',
        'for', 'to', 'of', 'with', 'about', 'from', 'than',
        'until', 'till', 'before', 'after', 'and', 'or',
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

        [$title, $due] = self::splitQuoted($text, $today) ?? self::splitTrailingWhen($text, $today);

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
     * A double-quoted title, taken verbatim, with an optional due phrase
     * after the closing quote. Null when the line is not of that shape,
     * including when other words follow the quote (`"Q4" report by
     * friday`): those are part of the task, so the caller reads the whole
     * line as an unquoted one instead of dropping them.
     *
     * @return array{0: string, 1: ?string}|null [title, due date]
     */
    private static function splitQuoted(string $text, DateTimeImmutable $today): ?array
    {
        if (preg_match('/^"(.*?)"\s*(.*)$/u', $text, $m) !== 1) {
            return null;
        }

        $after = trim($m[2]);

        if ($after === '') {
            return [trim($m[1]), null];
        }

        $due = self::parseWhen(self::stripConnector($after), $today);

        return $due === null ? null : [trim($m[1]), $due];
    }

    /**
     * Peel a due phrase off the end of an unquoted line. Tries the longest
     * phrase first ("in 3 days" before "days").
     *
     * Deliberately not eager, because a wrong guess silently rewrites what
     * was typed:
     *  - mon, wed, sat and sun only count after by/on/due;
     *  - by and due are always dropped with the date, but "on" only before
     *    a weekday or a date ("on friday"), so "Turn the reminders on
     *    tomorrow" keeps its "on";
     *  - when the title would be left empty or ending on a word that needs
     *    something after it, the line is kept whole and undated.
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

            $phrase = strtolower(implode(' ', array_slice($words, -$take)));
            $due = self::parseWhen($phrase, $today);

            if ($due === null) {
                continue;
            }

            $head = array_slice($words, 0, -$take);
            $before = strtolower($head[count($head) - 1]);
            $isDayOrDate = isset(self::WEEKDAYS[$phrase]) || preg_match('/^\d{4}-/', $phrase) === 1;
            $hasConnector = $before === 'by' || $before === 'due' || ($before === 'on' && $isDayOrDate);

            if (!$hasConnector && in_array($phrase, self::AMBIGUOUS_WEEKDAYS, true)) {
                break;
            }

            if ($hasConnector) {
                array_pop($head);
            }

            // "by friday" alone is not a task, and "Plan for" is not a title.
            if ($head === [] || in_array(strtolower($head[count($head) - 1]), self::DANGLING, true)) {
                break;
            }

            return [implode(' ', $head), $due];
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
            return Dates::isValidYmd($phrase) ? $phrase : null;
        }

        return null;
    }

    /** "by friday" -> "friday" (the phrase after a quoted title). */
    private static function stripConnector(string $phrase): string
    {
        return (string) preg_replace('/^(?:by|on|due)\s+/i', '', $phrase);
    }
}
