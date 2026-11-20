<?php

declare(strict_types=1);

use App\Support\View;

/**
 * @var string $view 'mine'|'everyone', the same lens as the dashboard
 * @var string $status 'open'|'done'
 * @var array<string, list<array<string, mixed>>> $groups heading => task rows
 * @var int $taskCount
 * @var list<array<string, mixed>> $contactOptions
 * @var list<array<string, mixed>> $owners
 * @var string|null $taskError
 */

include __DIR__ . '/../layout/header.php';

$pill = static fn(bool $active): string => 'px-3 py-1.5 ' . ($active
    ? 'bg-brand-ink text-white'
    : 'bg-white text-brand-inksoft hover:bg-brand-cream');
?>

<div class="flex items-center justify-between gap-4 flex-wrap">
    <h1 class="font-serif text-3xl text-brand-ink">Tasks</h1>
    <div class="flex items-center gap-3">
        <nav aria-label="Open or done"
             class="flex text-sm border border-brand-sand rounded-lg overflow-hidden">
            <?php foreach (['open' => 'Open', 'done' => 'Done'] as $key => $label) : ?>
                <a href="/tasks?status=<?= $key ?>"
                   <?= $status === $key ? 'aria-current="page"' : '' ?>
                   class="<?= $pill($status === $key) ?>"><?= $label ?></a>
            <?php endforeach ?>
        </nav>
        <nav aria-label="Whose tasks to show"
             class="flex text-sm border border-brand-sand rounded-lg overflow-hidden">
            <?php foreach (['mine' => 'Mine', 'everyone' => 'Everyone'] as $key => $label) : ?>
                <a href="/tasks?view=<?= $key ?>&amp;status=<?= View::e($status) ?>"
                   <?= $view === $key ? 'aria-current="page"' : '' ?>
                   class="<?= $pill($view === $key) ?>"><?= $label ?></a>
            <?php endforeach ?>
        </nav>
    </div>
</div>
<p class="mt-1 text-sm text-brand-muted">
    <?= $view === 'mine' ? 'Your tasks and shared ones.' : 'Everyone&rsquo;s tasks.' ?>
    One-off things to do, separate from follow-ups.
    <a href="/help/tasks" class="text-brand-red hover:underline">How tasks work</a>
</p>

<?php if ($taskError !== null) : ?>
    <p class="mt-4 max-w-2xl text-sm text-brand-red bg-red-50 border border-red-200 rounded-lg px-4 py-2">
        <?= View::e($taskError) ?>
    </p>
<?php endif ?>

<?php if ($status === 'open') : ?>
    <div class="mt-6 bg-white rounded-lg shadow">
        <?php
        $taskAdd = ['returnTo' => '/tasks', 'contactOptions' => $contactOptions, 'owners' => $owners];
        include __DIR__ . '/../partials/task_add.php';
        ?>
    </div>
<?php endif ?>

<?php if ($taskCount === 0) : ?>
    <p class="mt-6 text-sm text-brand-muted">
        <?php if ($status === 'done') : ?>
            Nothing ticked off yet.
        <?php elseif ($view === 'mine') : ?>
            No open tasks. Add one above, or switch to Everyone to see the whole list.
        <?php else : ?>
            No open tasks. Add one above.
        <?php endif ?>
    </p>
<?php endif ?>

<?php foreach ($groups as $heading => $rows) : ?>
    <section class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wide
                   <?= $heading === 'Overdue' ? 'text-brand-red' : 'text-brand-inksoft' ?>">
            <?= View::e($heading) ?> (<?= count($rows) ?>)
        </h2>
        <div class="mt-2 bg-white rounded-lg shadow">
            <?php
            $taskList = ['rows' => $rows, 'returnTo' => '/tasks'];
            include __DIR__ . '/../partials/task_rows.php';
            ?>
        </div>
    </section>
<?php endforeach ?>

<?php include __DIR__ . '/../layout/footer.php';
