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
