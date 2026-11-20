<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var string $view 'mine'|'everyone' — the follow-up lens
 * @var int|null $ownedCount owned-contact count, set only when the Mine
 *      view has nothing due at all (the empty-state hint)
 * @var int|null $ownerId the lens user id, null on the Everyone view
 * @var string $today
 * @var string $tomorrow
 * @var list<array<string, mixed>> $overdue rows carry days_overdue
 * @var list<array<string, mixed>> $thisWeek
 * @var list<array<string, mixed>> $goingCold rows carry days_since_contact
 * @var array<string, string> $activityTypes
 * @var array<int, string> $snoozeOptions
 * @var list<array<string, mixed>> $tasks open tasks (overdue, due this week,
 *      undated) followed by ones ticked in the last day, decorated
 * @var int $openTaskCount how many of $tasks are open
 * @var int $laterTaskCount open tasks dated beyond this week (on /tasks)
 * @var list<array<string, mixed>> $contactOptions for the add-a-task picker
 * @var string|null $taskError
 */

include __DIR__ . '/../layout/header.php';

// Inline actions per row: a quick-log toggle and a snooze picker. Both
// post return_to=/ so the review flow never leaves the dashboard. The
// picker expands the row inline (like the quick-log row) rather than
// floating absolutely: the table wrapper's overflow-x-auto clips
// anything positioned outside it, which cut the old popover to a sliver
// on short tables (Colin, 2026-11-19).
$actionsCell = static function (array $contact) use ($snoozeOptions): string {
    $id = (int) $contact['id'];
    $html = '<td class="px-4 py-2 text-right whitespace-nowrap">'
        . '<button type="button" onclick="toggleQuickLog(' . $id . ')"'
        . ' class="text-xs text-brand-inksoft border border-brand-sand hover:bg-brand-cream2'
        . ' rounded px-2.5 py-1">Log</button>'
        . '<details class="inline-block ml-1.5 text-left">'
        . '<summary class="text-xs text-brand-inksoft border border-brand-sand hover:bg-brand-cream2'
        . ' rounded px-2.5 py-1 cursor-pointer list-none inline-block">Snooze</summary>'
        . '<form method="post" action="/contacts/' . $id . '/snooze"'
        . ' class="mt-1.5 flex items-center justify-end gap-1.5">'
        . Csrf::field()
        . '<input type="hidden" name="return_to" value="/">'
        . '<select name="days" class="text-xs border border-brand-sand rounded px-1.5 py-1 bg-white">';

    foreach ($snoozeOptions as $days => $label) {
        $html .= '<option value="' . (int) $days . '"' . ($days === 7 ? ' selected' : '') . '>'
            . View::e($label) . '</option>';
    }

    return $html . '</select>'
        . '<button type="submit" class="text-xs text-white bg-brand-red hover:bg-brand-reddark'
        . ' rounded px-2.5 py-1">Go</button>'
        . '</form></details></td>';
};

$quickLogRow = static function (array $contact, int $colspan) use ($activityTypes): string {
    $id = (int) $contact['id'];
    $html = '<tr id="quicklog-' . $id . '" hidden><td colspan="' . $colspan . '"'
        . ' class="bg-brand-cream px-4 py-3">'
        . '<form method="post" action="/contacts/' . $id . '/activities"'
        . ' class="flex flex-wrap items-center gap-2">'
        . Csrf::field()
        . '<input type="hidden" name="return_to" value="/">'
        . '<select name="activity_type" class="text-sm border border-brand-sand rounded-lg'
        . ' px-2 py-1.5 bg-white">';

    foreach ($activityTypes as $value => $label) {
        $html .= '<option value="' . View::e($value) . '">' . View::e($label) . '</option>';
    }

    return $html . '</select>'
        . '<input type="text" name="summary" placeholder="What happened?"'
        . ' class="flex-1 min-w-[14rem] text-sm border border-brand-sand rounded-lg px-3 py-1.5">'
        . '<button type="submit" class="text-sm bg-brand-red hover:bg-brand-reddark text-white'
        . ' rounded-lg px-4 py-1.5">Log it</button>'
        . '<button type="button" onclick="toggleQuickLog(' . $id . ')"'
        . ' class="text-sm text-brand-muted hover:text-brand-inksoft">Cancel</button>'
        . '<span class="text-xs text-brand-muted">Logs a touch today and reschedules'
        . ' the follow-up.</span>'
        . '</form></td></tr>';
};

$rowClick = static fn(int $id): string => "if (!event.target.closest("
    . "'a,button,form,details,select,input,label')"
    . " && window.getSelection().toString() === '')"
    . " window.location = '/contacts/" . $id . "'";
?>

<div class="flex items-center justify-between gap-4">
    <h1 class="font-serif text-3xl text-brand-ink">Follow-ups</h1>
    <nav aria-label="Whose follow-ups to show"
         class="flex text-sm border border-brand-sand rounded-lg overflow-hidden">
        <?php foreach (['mine' => 'Mine', 'everyone' => 'Everyone'] as $key => $label) : ?>
            <a href="/?view=<?= $key ?>"
               <?= $view === $key ? 'aria-current="page"' : '' ?>
               class="px-3 py-1.5 <?= $view === $key
                    ? 'bg-brand-ink text-white'
                    : 'bg-white text-brand-inksoft hover:bg-brand-cream' ?>">
                <?= $label ?>
            </a>
        <?php endforeach ?>
    </nav>
</div>
<p class="mt-1 text-sm text-brand-muted">
    <?= $view === 'mine'
        ? 'Your contacts and shared ones: overdue, due within the next 7 days, and going cold.'
        : 'Everyone&rsquo;s contacts: overdue, due within the next 7 days, and going cold.' ?>
</p>

<?php if ($ownedCount !== null) : ?>
    <div class="mt-6 bg-white rounded-lg shadow px-5 py-4 text-sm text-brand-inksoft">
        <?php if ($ownedCount > 0) : ?>
            Nothing needs your attention right now. You own
            <?= $ownedCount ?> contact<?= $ownedCount === 1 ? '' : 's' ?>: browse them on
            <a href="/contacts?owner=<?= (int) $ownerId ?>"
               class="text-brand-red hover:underline">the Contacts list</a>.
        <?php else : ?>
            You don&rsquo;t own any contacts yet. Claim one by setting yourself as
            Owner on its edit form, or switch to Everyone above.
        <?php endif ?>
    </div>
<?php endif ?>

<section class="mt-8">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-inksoft">
        Tasks (<?= $openTaskCount ?>)
    </h2>
    <?php if ($taskError !== null) : ?>
        <p class="mt-2 text-sm text-brand-red"><?= View::e($taskError) ?></p>
    <?php endif ?>
    <div class="mt-2 bg-white rounded-lg shadow">
        <?php
        if ($tasks !== []) {
            $taskList = ['rows' => $tasks, 'returnTo' => '/'];
            include __DIR__ . '/../partials/task_rows.php';
        }
        ?>
        <div class="<?= $tasks !== [] ? 'border-t border-brand-cream2' : '' ?>">
            <?php
            $taskAdd = ['returnTo' => '/', 'contactOptions' => $contactOptions];
            include __DIR__ . '/../partials/task_add.php';
            ?>
        </div>
    </div>
    <p class="mt-1.5 text-xs text-brand-muted">
        <?php if ($laterTaskCount > 0) : ?>
            Plus <?= $laterTaskCount ?> due later.
        <?php endif ?>
        <a href="/tasks" class="text-brand-red hover:underline">All tasks</a>
    </p>
</section>

<?php $sections = [['Overdue', $overdue, true], ['Due this week', $thisWeek, false]]; ?>
<?php foreach ($sections as [$heading, $rows, $isOverdue]) : ?>
    <section class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wide
                   <?= $isOverdue ? 'text-brand-red' : 'text-brand-inksoft' ?>">
            <?= View::e($heading) ?> (<?= count($rows) ?>)
        </h2>

        <?php if ($rows === []) : ?>
            <p class="mt-2 text-sm text-brand-muted">Nothing here. 🎉</p>
        <?php else : ?>
            <div class="mt-2 bg-white rounded-lg shadow overflow-x-auto relative">
                <table class="w-full text-sm">
                    <thead class="text-left text-brand-muted border-b">
                        <tr>
                            <th class="px-4 py-2 font-medium">Contact</th>
                            <th class="px-4 py-2 font-medium">Organization</th>
                            <th class="px-4 py-2 font-medium">Stage</th>
                            <th class="px-4 py-2 font-medium whitespace-nowrap w-36">Next follow-up</th>
                            <th class="px-4 py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $contact) : ?>
                            <tr class="border-b hover:bg-brand-cream cursor-pointer"
                                onclick="<?= $rowClick((int) $contact['id']) ?>">
                                <td class="px-4 py-2">
                                    <span class="flex items-center gap-2.5">
                                        <?php
                                        $avatar = ['contact' => $contact];
                                        include __DIR__ . '/../partials/avatar.php';
                                        ?>
                                        <span>
                                            <a href="/contacts/<?= (int) $contact['id'] ?>"
                                               class="text-brand-red hover:underline font-medium">
                                                <?= View::e((string) $contact['name']) ?>
                                            </a>
                                    <?php if (($contact['follow_up_note'] ?? '') !== '') : ?>
                                        <p class="text-xs text-brand-muted mt-0.5">
                                            <?= View::e((string) $contact['follow_up_note']) ?>
                                        </p>
                                    <?php endif ?>
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-brand-inksoft">
                                    <?php if ($contact['organization_id'] !== null) : ?>
                                        <a href="/organizations/<?= (int) $contact['organization_id'] ?>"
                                           class="hover:text-brand-red hover:underline">
                                            <?= View::e((string) $contact['organization_name']) ?>
                                        </a>
                                    <?php endif ?>
                                </td>
                                <td class="px-4 py-2 text-brand-inksoft">
                                    <?= View::e((string) $contact['relationship_status']) ?>
                                </td>
                                <?php
                                // Overdue dates are emphasized with weight, not color: red
                                // reads as a link in this app (links are brand red).
                                $dateClass = $isOverdue ? 'text-brand-ink font-semibold' : 'text-brand-inksoft';
                                ?>
                                <td class="px-4 py-2 whitespace-nowrap <?= $dateClass ?>">
                                    <?php $dueDate = (string) $contact['next_touch_date']; ?>
                                    <?php if (!$isOverdue && $dueDate === $today) : ?>
                                        Today
                                    <?php elseif (!$isOverdue && $dueDate === $tomorrow) : ?>
                                        Tomorrow
                                    <?php else : ?>
                                        <?= View::e($dueDate) ?>
                                    <?php endif ?>
                                    <?php if (($contact['days_overdue'] ?? 0) > 0) : ?>
                                        <span class="block text-xs text-brand-muted font-normal">
                                            (<?= (int) $contact['days_overdue'] ?>
                                            day<?= (int) $contact['days_overdue'] === 1 ? '' : 's' ?>)
                                        </span>
                                    <?php endif ?>
                                </td>
                                <?= $actionsCell($contact) ?>
                            </tr>
                            <?= $quickLogRow($contact, 5) ?>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>
<?php endforeach ?>

<?php if ($goingCold !== []) : ?>
    <section class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-inksoft">
            Going cold (<?= count($goingCold) ?>)
        </h2>
        <p class="mt-1 text-xs text-brand-muted">
            More than 30 days past their follow-up, or nothing scheduled at all.
            Log a touch, snooze, or move them to Dormant.
        </p>
        <div class="mt-2 bg-white rounded-lg shadow overflow-x-auto relative">
            <table class="w-full text-sm">
                <thead class="text-left text-brand-muted border-b">
                    <tr>
                        <th class="px-4 py-2 font-medium">Contact</th>
                        <th class="px-4 py-2 font-medium">Organization</th>
                        <th class="px-4 py-2 font-medium">Stage</th>
                        <th class="px-4 py-2 font-medium whitespace-nowrap w-36">Last contacted</th>
                        <th class="px-4 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($goingCold as $contact) : ?>
                        <tr class="border-b hover:bg-brand-cream cursor-pointer"
                            onclick="<?= $rowClick((int) $contact['id']) ?>">
                            <td class="px-4 py-2">
                                <span class="flex items-center gap-2.5">
                                    <?php
                                    $avatar = ['contact' => $contact];
                                    include __DIR__ . '/../partials/avatar.php';
                                    ?>
                                    <a href="/contacts/<?= (int) $contact['id'] ?>"
                                       class="text-brand-red hover:underline font-medium">
                                        <?= View::e((string) $contact['name']) ?>
                                    </a>
                                </span>
                            </td>
                            <td class="px-4 py-2 text-brand-inksoft">
                                <?php if ($contact['organization_id'] !== null) : ?>
                                    <a href="/organizations/<?= (int) $contact['organization_id'] ?>"
                                       class="hover:text-brand-red hover:underline">
                                        <?= View::e((string) $contact['organization_name']) ?>
                                    </a>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2 text-brand-inksoft">
                                <?= View::e((string) $contact['relationship_status']) ?>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap text-brand-ink font-semibold">
                                <?php if ($contact['days_since_contact'] === null) : ?>
                                    never
                                <?php else : ?>
                                    <?= (int) $contact['days_since_contact'] ?> days ago
                                <?php endif ?>
                            </td>
                            <?= $actionsCell($contact) ?>
                        </tr>
                        <?= $quickLogRow($contact, 5) ?>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif ?>

<script>
    function toggleQuickLog(id) {
        const row = document.getElementById('quicklog-' + id);
        row.hidden = !row.hidden;

        if (!row.hidden) {
            row.querySelector('input[name="summary"]').focus();
        }
    }
</script>

<?php include __DIR__ . '/../layout/footer.php';
