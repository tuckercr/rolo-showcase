<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\TaskController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskControllerTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string}>
     */
    public static function returnTargets(): array
    {
        return [
            'dashboard' => ['/', '/'],
            'tasks page' => ['/tasks', '/tasks'],
            'a contact' => ['/contacts/110', '/contacts/110'],
            'an organization' => ['/organizations/4', '/organizations/4'],
            'missing' => [null, '/tasks'],
            'another site' => ['https://evil.example/', '/tasks'],
            'protocol-relative' => ['//evil.example', '/tasks'],
            'a path this app does not return to' => ['/settings', '/tasks'],
            'query strings are not carried' => ['/tasks?status=done', '/tasks'],
            'trailing junk' => ['/contacts/110/edit', '/tasks'],
            'not a string' => [['/'], '/tasks'],
        ];
    }

    #[DataProvider('returnTargets')]
    public function testReturnToOnlyAcceptsPagesThatHostTaskControls(mixed $raw, string $expected): void
    {
        self::assertSame($expected, TaskController::returnTo($raw));
    }

    public function testOpenTasksGroupIntoSectionsAndEmptyOnesDisappear(): void
    {
        $groups = TaskController::groupOpen([
            ['title' => 'a', 'due_date' => '2026-11-18'],
            ['title' => 'b', 'due_date' => '2026-11-20'],
            ['title' => 'c', 'due_date' => '2026-11-27'],
            ['title' => 'd', 'due_date' => null],
            ['title' => 'e', 'due_date' => '2026-11-19'],
        ], '2026-11-20');

        self::assertSame(['Overdue', 'Today', 'Upcoming', 'No date'], array_keys($groups));
        self::assertSame(['a', 'e'], array_column($groups['Overdue'], 'title'));
        self::assertSame(['b'], array_column($groups['Today'], 'title'));

        self::assertSame(
            ['No date'],
            array_keys(TaskController::groupOpen([['title' => 'd', 'due_date' => null]], '2026-11-20')),
        );
        self::assertSame([], TaskController::groupOpen([], '2026-11-20'));
    }
}
