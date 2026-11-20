<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * A list of tasks with their checkboxes. Included wherever tasks appear
 * (dashboard, /tasks, contact and organization pages), so a task looks and
 * behaves the same everywhere.
 *
 * Each checkbox is its own small POST form: ticking works without
 * JavaScript and always returns to the page it was ticked on.
 *
 * @var array{
 *     rows: list<array<string, mixed>>,
 *     returnTo: string,
 *     showLink?: bool,
 *     compact?: bool,
 *     hostOrganizationId?: int|null
 * } $taskList rows are TaskLabels::decorate() output; showLink adds the
 *     linked contact or organization after the title (off on a contact's
 *     own page); compact stacks the date under the title for narrow
 *     columns; hostOrganizationId is the organization whose page this is,
 *     so its own name is not repeated on every row
 * @var array<string, mixed>|null $currentUser
 */

$taskRows = $taskList['rows'];
$taskReturnTo = (string) $taskList['returnTo'];
$taskShowLink = (bool) ($taskList['showLink'] ?? true);
$taskCompact = (bool) ($taskList['compact'] ?? false);
$taskHostOrganizationId = $taskList['hostOrganizationId'] ?? null;
$taskViewerId = (int) ($currentUser['id'] ?? 0);
?>
<ul>
    <?php foreach ($taskRows as $task) : ?>
        <?php
        $taskId = (int) $task['id'];
        $taskDone = $task['completed_at'] !== null;
        $taskTitle = (string) $task['title'];
        $taskWhen = $taskDone
            ? (string) $task['done_label']
                . (($task['completed_by_name'] ?? null) !== null ? ' by ' . (string) $task['completed_by_name'] : '')
            : (string) $task['due_label'];
        // Overdue is emphasized with weight, not color: red reads as a link
        // in this app (same rule as the dashboard's overdue dates).
        $taskWhenClass = ($task['is_overdue'] ?? false) ? 'text-brand-ink font-semibold' : 'text-brand-muted';
        $taskOwnerTag = $task['owner_id'] === null
            ? 'Shared'
            : ((int) $task['owner_id'] !== $taskViewerId ? (string) $task['owner_name'] : '');
        // An organization's own page does not repeat its name on every row.
        $taskShowsOrganization = $taskShowLink
            && $task['organization_id'] !== null
            && (int) $task['organization_id'] !== $taskHostOrganizationId;
        ?>
        <li class="flex items-start gap-3 px-4 py-2.5 border-b border-brand-cream2 last:border-b-0 text-sm">
            <form method="post" action="/tasks/<?= $taskId ?>/<?= $taskDone ? 'reopen' : 'complete' ?>"
                  class="pt-0.5 shrink-0">
                <?= Csrf::field() ?>
                <input type="hidden" name="return_to" value="<?= View::e($taskReturnTo) ?>">
                <button type="submit" role="checkbox"
                        aria-checked="<?= $taskDone ? 'true' : 'false' ?>"
                        aria-label="<?= View::e(($taskDone ? 'Mark not done: ' : 'Mark done: ') . $taskTitle) ?>"
                        title="<?= $taskDone ? 'Mark not done' : 'Mark done' ?>"
                        class="flex items-center justify-center w-[18px] h-[18px] rounded border
                               <?= $taskDone
                                    ? 'bg-brand-red border-brand-red hover:bg-brand-reddark'
                                    : 'bg-white border-brand-muted hover:border-brand-red' ?>">
                    <?php if ($taskDone) : ?>
                        <svg viewBox="0 0 12 12" class="w-3 h-3" fill="none" stroke="#ffffff"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true"><path d="M2.5 6.2 5 8.6 9.5 3.6"/></svg>
                    <?php endif ?>
                </button>
            </form>
            <div class="flex-1 min-w-0">
                <a href="/tasks/<?= $taskId ?>/edit?return_to=<?= View::e(rawurlencode($taskReturnTo)) ?>"
                   title="Edit task"
                   class="hover:underline <?= $taskDone ? 'line-through text-brand-muted' : 'text-brand-ink' ?>">
                    <?= View::e($taskTitle) ?></a>
                <?php if ($taskShowLink && $task['contact_id'] !== null) : ?>
                    <a href="/contacts/<?= (int) $task['contact_id'] ?>"
                       class="ml-1.5 text-brand-red hover:underline whitespace-nowrap">
                        <?= View::e((string) $task['contact_name']) ?></a>
                    <?php if (($task['organization_name'] ?? null) !== null && $taskHostOrganizationId === null) : ?>
                        <span class="text-brand-muted">&middot;
                            <?= View::e((string) $task['organization_name']) ?></span>
                    <?php endif ?>
                <?php elseif ($taskShowsOrganization) : ?>
                    <a href="/organizations/<?= (int) $task['organization_id'] ?>"
                       class="ml-1.5 text-brand-red hover:underline whitespace-nowrap">
                        <?= View::e((string) $task['organization_name']) ?></a>
                <?php endif ?>
                <?php if ($taskOwnerTag !== '') : ?>
                    <span class="ml-1.5 inline-block text-xs bg-brand-cream2 border border-brand-sand
                                 text-brand-inksoft rounded-full px-2 py-px align-middle whitespace-nowrap">
                        <?= View::e($taskOwnerTag) ?></span>
                <?php endif ?>
                <?php if (($task['notes'] ?? null) !== null && !$taskDone) : ?>
                    <p class="mt-0.5 text-xs text-brand-muted truncate">
                        <?= View::e((string) $task['notes']) ?>
                    </p>
                <?php endif ?>
                <?php if ($taskCompact && $taskWhen !== '') : ?>
                    <p class="mt-0.5 text-xs <?= $taskWhenClass ?>"><?= View::e($taskWhen) ?></p>
                <?php endif ?>
            </div>
            <?php if (!$taskCompact && $taskWhen !== '') : ?>
                <span class="pt-0.5 text-xs whitespace-nowrap <?= $taskWhenClass ?>">
                    <?= View::e($taskWhen) ?>
                </span>
            <?php endif ?>
        </li>
    <?php endforeach ?>
</ul>
