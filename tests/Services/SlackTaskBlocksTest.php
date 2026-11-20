<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\SlackTaskBlocks;
use App\Support\Config;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SlackTaskBlocksTest extends TestCase
{
    private const TODAY = '2026-11-20';

    private function renderer(): SlackTaskBlocks
    {
        return new SlackTaskBlocks(new Config(
            appEnv: 'test',
            appTimezone: new DateTimeZone('America/New_York'),
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
            appUrl: 'https://crm.example.com',
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function task(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'title' => 'Send the deck',
            'due_date' => null,
            'owner_id' => 1,
            'contact_id' => null,
            'contact_name' => null,
            'organization_name' => null,
        ];
    }

    public function testNoTasksNoBlocks(): void
    {
        self::assertSame([], $this->renderer()->listBlocks([], 'Headline', self::TODAY));
    }

    public function testLineShowsWhoItIsAboutAndWhenItIsDue(): void
    {
        $renderer = $this->renderer();

        self::assertSame('*Send the deck*', $renderer->line($this->task(), self::TODAY));
        self::assertSame(
            '*Send the deck* · <https://crm.example.com/contacts/3|Steve Marsh> · due today',
            $renderer->line(
                $this->task(['contact_id' => 3, 'contact_name' => 'Steve Marsh', 'due_date' => self::TODAY]),
                self::TODAY,
            ),
        );
        self::assertSame(
            '*Send the deck* · Northfield Labs · _due Nov 18 (2 days late)_ · shared',
            $renderer->line(
                $this->task(['organization_name' => 'Northfield Labs', 'due_date' => '2026-11-18', 'owner_id' => null]),
                self::TODAY,
            ),
        );
    }

    public function testUserTypedTextIsEscaped(): void
    {
        $line = $this->renderer()->line(
            $this->task(['title' => '<https://evil.example|Click> & run']),
            self::TODAY,
        );

        self::assertSame('*&lt;https://evil.example|Click&gt; &amp; run*', $line);
    }

    public function testEachTaskGetsADoneButtonCarryingItsId(): void
    {
        $blocks = $this->renderer()->listBlocks(
            [$this->task(), $this->task(['id' => 8, 'title' => 'Second'])],
            '*2 open tasks*',
            self::TODAY,
        );

        self::assertCount(5, $blocks, 'headline + (section + actions) per task');
        self::assertSame('actions', $blocks[2]['type']);

        $done = $blocks[2]['elements'][0];
        self::assertSame('task_done_7', $done['action_id']);
        self::assertSame(['a' => 'task_done', 't' => 7], json_decode($done['value'], true));

        $open = $blocks[2]['elements'][1];
        self::assertSame('https://crm.example.com/tasks/7/edit', $open['url']);
        self::assertSame('open', json_decode($open['value'], true)['a'], 'URL buttons are acknowledged, not performed');

        self::assertSame('task_done_8', $blocks[4]['elements'][0]['action_id']);
    }

    public function testRowsAreCappedWithAFooterSkipCannotMistakeForItsOwn(): void
    {
        $tasks = [];

        for ($i = 1; $i <= 7; $i++) {
            $tasks[] = $this->task(['id' => $i]);
        }

        $blocks = $this->renderer()->listBlocks($tasks, 'Headline', self::TODAY, 5);
        $footer = end($blocks);

        self::assertCount(1 + 5 * 2 + 1, $blocks);
        self::assertSame('context', $footer['type']);
        self::assertStringContainsString('And 2 more tasks', $footer['elements'][0]['text']);
        self::assertStringStartsNotWith(
            'Plus ',
            $footer['elements'][0]['text'],
            'updateMoreFooter() rewrites the first "Plus N more on the" footer it finds',
        );
    }

    public function testUndoReopensTheSameTask(): void
    {
        $undo = $this->renderer()->undoActions(7);

        self::assertSame('task_reopen_7', $undo['elements'][0]['action_id']);
        self::assertSame(['a' => 'task_reopen', 't' => 7], json_decode($undo['elements'][0]['value'], true));
    }
}
