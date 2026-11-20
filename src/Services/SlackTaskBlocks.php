<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\Mrkdwn;
use App\Support\TaskLabels;

/**
 * How tasks render in Slack. The evening debrief's task section and the
 * /rolo tasks reply are built here so the two can never drift apart, the
 * same reason the calendar rows live in one place.
 *
 * Every row is a section plus an actions block (Done, Open). The Done
 * button's value is {a: 'task_done', t: <task id>}; SlackController
 * performs it and swaps the row's buttons for the outcome line.
 */
final class SlackTaskBlocks
{
    /** At most this many tasks get a row; the rest are summarized. */
    public const MAX_ROWS = 10;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param list<array<string, mixed>> $tasks TaskModel rows
     * @param string $today today in APP_TIMEZONE, Y-m-d
     * @return list<array<string, mixed>> empty when there are no tasks
     */
    public function listBlocks(array $tasks, string $headline, string $today, int $maxRows = self::MAX_ROWS): array
    {
        if ($tasks === []) {
            return [];
        }

        $blocks = [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $headline]]];

        foreach (array_slice($tasks, 0, $maxRows) as $task) {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $this->line($task, $today)],
            ];
            $blocks[] = $this->actions($task);
        }

        if (count($tasks) > $maxRows) {
            // Deliberately not worded "Plus N more on the ...": that is the
            // debrief's contact footer, which Skip finds and rewrites by
            // its text (SlackController::updateMoreFooter).
            $blocks[] = [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        'And %d more tasks on the <%s|Tasks page>.',
                        count($tasks) - $maxRows,
                        $this->tasksUrl(),
                    ),
                ]],
            ];
        }

        return $blocks;
    }

    /**
     * One task as a line of mrkdwn: title, who or what it is about, when
     * it is due. Titles and names are user-typed, so they are escaped.
     *
     * @param array<string, mixed> $task
     */
    public function line(array $task, string $today): string
    {
        $line = '*' . Mrkdwn::esc((string) $task['title']) . '*';

        if (($task['contact_id'] ?? null) !== null) {
            $line .= sprintf(
                ' · <%s/contacts/%d|%s>',
                $this->config->baseUrl(),
                (int) $task['contact_id'],
                Mrkdwn::esc((string) $task['contact_name']),
            );
        } elseif (($task['organization_name'] ?? null) !== null) {
            $line .= ' · ' . Mrkdwn::esc((string) $task['organization_name']);
        }

        $due = TaskLabels::due(
            $task['due_date'] === null ? null : (string) $task['due_date'],
            $today,
            $this->config->appTimezone,
        );

        if ($due !== '') {
            $line .= ' · ' . (TaskLabels::isOverdue((string) $task['due_date'], $today) ? '_' . $due . '_' : $due);
        }

        if (($task['owner_id'] ?? null) === null) {
            $line .= ' · shared';
        }

        return $line;
    }

    /**
     * The Undo row shown under "Done": puts the task back to open.
     *
     * @return array<string, mixed>
     */
    public function undoActions(int $taskId): array
    {
        return [
            'type' => 'actions',
            'elements' => [[
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Undo'],
                'action_id' => 'task_reopen_' . $taskId,
                'value' => json_encode(['a' => 'task_reopen', 't' => $taskId], JSON_THROW_ON_ERROR),
            ]],
        ];
    }

    public function tasksUrl(): string
    {
        return $this->config->baseUrl() . '/tasks';
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function actions(array $task): array
    {
        $id = (int) $task['id'];

        return [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Done'],
                    'style' => 'primary',
                    'action_id' => 'task_done_' . $id,
                    'value' => json_encode(['a' => 'task_done', 't' => $id], JSON_THROW_ON_ERROR),
                ],
                [
                    // A URL button still sends a block_actions event; 'open'
                    // is the action SlackController acknowledges and ignores.
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Open'],
                    'url' => sprintf('%s/tasks/%d/edit', $this->config->baseUrl(), $id),
                    'action_id' => 'task_open_' . $id,
                    'value' => json_encode(['a' => 'open', 't' => $id], JSON_THROW_ON_ERROR),
                ],
            ],
        ];
    }
}
