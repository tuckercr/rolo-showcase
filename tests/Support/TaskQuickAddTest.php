<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\TaskQuickAdd;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text after "/rolo task". 2026-11-20 is a Friday.
 */
final class TaskQuickAddTest extends TestCase
{
    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-20', new DateTimeZone('America/New_York'));
    }

    public function testPlainTitleHasNoDateAndNoContact(): void
    {
        self::assertSame(
            ['title' => 'Prep slides for the biotech webinar', 'due_date' => null, 'contact_query' => null],
            TaskQuickAdd::parse('Prep slides for the biotech webinar', $this->today()),
        );
    }

    public function testTitleKeepsItsCasing(): void
    {
        self::assertSame(
            'Send the Q1 proposal to Northfield Labs',
            TaskQuickAdd::parse('Send the Q1 proposal to Northfield Labs', $this->today())['title'],
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function trailingWhen(): array
    {
        return [
            'today' => ['Call the venue today', 'Call the venue', '2026-11-20'],
            'tomorrow' => ['Call the venue tomorrow', 'Call the venue', '2026-11-21'],
            'weekday' => ['Call the venue monday', 'Call the venue', '2026-11-23'],
            'short weekday, any case' => ['Call the venue Tue', 'Call the venue', '2026-11-24'],
            'same weekday means next week' => ['Call the venue friday', 'Call the venue', '2026-11-27'],
            'next week' => ['Call the venue next week', 'Call the venue', '2026-11-27'],
            'in N days' => ['Call the venue in 3 days', 'Call the venue', '2026-11-23'],
            'in N weeks' => ['Call the venue in 2 weeks', 'Call the venue', '2026-12-04'],
            'iso date' => ['Call the venue 2026-12-01', 'Call the venue', '2026-12-01'],
            'by is dropped' => ['Send the deck by friday', 'Send the deck', '2026-11-27'],
            'on is dropped' => ['Send the deck on 2026-12-01', 'Send the deck', '2026-12-01'],
            'due is dropped' => ['Send the deck due tomorrow', 'Send the deck', '2026-11-21'],
            'ambiguous short weekday after by' => ['Send the deck by sat', 'Send the deck', '2026-11-21'],
            'ambiguous short weekday after on' => ['Send the deck on Wed', 'Send the deck', '2026-11-25'],
            'ambiguous short weekday after due' => ['Send the deck due mon', 'Send the deck', '2026-11-23'],
            'on stays when it is not a date word' => [
                'Turn the reminders on tomorrow',
                'Turn the reminders on',
                '2026-11-21',
            ],
            'on stays before a longer phrase' => [
                'Switch the heating on in 2 days',
                'Switch the heating on',
                '2026-11-22',
            ],
            'a phrasal verb keeps its date' => ['Hand the report in friday', 'Hand the report in', '2026-11-27'],
        ];
    }

    #[DataProvider('trailingWhen')]
    public function testTrailingWhenBecomesTheDueDate(string $text, string $title, string $due): void
    {
        $parsed = TaskQuickAdd::parse($text, $this->today());

        self::assertSame($title, $parsed['title']);
        self::assertSame($due, $parsed['due_date']);
    }

    public function testOnlyTheEndOfTheLineIsReadAsADate(): void
    {
        $parsed = TaskQuickAdd::parse('Book the friday dinner table', $this->today());

        self::assertSame('Book the friday dinner table', $parsed['title']);
        self::assertNull($parsed['due_date']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everydayWords(): array
    {
        return [
            'sat is a verb' => ['Find out where the proofs sat'],
            'sun is a noun' => ['Move the plants out of the sun'],
            'wed is a verb' => ['Send a card to the couple who wed'],
            'mon without a connector' => ['Ask about the rota for mon'],
            'for needs its object' => ['Plan for next week'],
            'about needs its object' => ['Call the venue about Friday'],
            'the needs its noun' => ['Write up the notes from the 2026-12-01'],
            'before is not a due word' => ['Send the deck before friday'],
            'this needs its day' => ['Send the deck this friday'],
            'a connector cannot rescue a dangling title' => ['Send it to the by friday'],
        ];
    }

    #[DataProvider('everydayWords')]
    public function testADateWordThatBelongsToTheSentenceStaysInTheTitle(string $text): void
    {
        $parsed = TaskQuickAdd::parse($text, $this->today());

        self::assertSame($text, $parsed['title']);
        self::assertNull($parsed['due_date']);
    }

    public function testAnImpossibleDateStaysInTheTitle(): void
    {
        $parsed = TaskQuickAdd::parse('File the report 2026-02-30', $this->today());

        self::assertSame('File the report 2026-02-30', $parsed['title']);
        self::assertNull($parsed['due_date']);
    }

    public function testQuotedTitleIsVerbatimWithAnOptionalDateAfterIt(): void
    {
        self::assertSame(
            ['title' => 'Call the venue about Friday', 'due_date' => null, 'contact_query' => null],
            TaskQuickAdd::parse('"Call the venue about Friday"', $this->today()),
        );
        self::assertSame(
            ['title' => 'Call the venue about Friday', 'due_date' => '2026-11-23', 'contact_query' => null],
            TaskQuickAdd::parse('"Call the venue about Friday" by monday', $this->today()),
        );
    }

    public function testAnyDuePhraseWorksAfterAClosingQuote(): void
    {
        // The quotes already say where the title ends, so the short
        // weekdays need no connector here.
        $parsed = TaskQuickAdd::parse('"Call the venue" sat', $this->today());

        self::assertSame('Call the venue', $parsed['title']);
        self::assertSame('2026-11-21', $parsed['due_date']);
    }

    public function testWordsAfterAClosingQuoteAreNeverDropped(): void
    {
        // Not a quoted title: the quotes are part of the sentence.
        self::assertSame(
            ['title' => '"Q4" report', 'due_date' => '2026-11-27', 'contact_query' => null],
            TaskQuickAdd::parse('"Q4" report by friday', $this->today()),
        );
        self::assertSame(
            ['title' => '"Launch" checklist for Northfield Labs', 'due_date' => null, 'contact_query' => null],
            TaskQuickAdd::parse('"Launch" checklist for Northfield Labs', $this->today()),
        );
        // A mistyped date is kept in the title rather than thrown away.
        self::assertSame(
            ['title' => '"Call the venue" by fryday', 'due_date' => null, 'contact_query' => null],
            TaskQuickAdd::parse('"Call the venue" by fryday', $this->today()),
        );
    }

    public function testCurlyQuotesFromSlackCountAsQuotes(): void
    {
        $parsed = TaskQuickAdd::parse("\u{201C}Plan for tomorrow\u{201D}", $this->today());

        self::assertSame('Plan for tomorrow', $parsed['title']);
        self::assertNull($parsed['due_date']);
    }

    public function testForNamePrefixNamesTheContact(): void
    {
        self::assertSame(
            ['title' => 'Send the pricing one-pager', 'due_date' => '2026-11-24', 'contact_query' => 'steve marsh'],
            TaskQuickAdd::parse('for steve marsh: Send the pricing one-pager tuesday', $this->today()),
        );
    }

    public function testForWithoutAColonIsJustATitle(): void
    {
        $parsed = TaskQuickAdd::parse('for the record check the invoice', $this->today());

        self::assertNull($parsed['contact_query']);
        self::assertSame('for the record check the invoice', $parsed['title']);
    }

    public function testADateAloneIsATitleNotAnEmptyTask(): void
    {
        self::assertSame('friday', TaskQuickAdd::parse('friday', $this->today())['title']);
        self::assertSame('by friday', TaskQuickAdd::parse('by friday', $this->today())['title']);
    }

    public function testNothingToSaveIsNull(): void
    {
        self::assertNull(TaskQuickAdd::parse('   ', $this->today()));
        self::assertNull(TaskQuickAdd::parse('""', $this->today()));
        self::assertNull(TaskQuickAdd::parse('"" friday', $this->today()));
    }
}
