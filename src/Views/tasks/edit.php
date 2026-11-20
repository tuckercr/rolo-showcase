<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var array<string, mixed> $task decorated row (TaskLabels::decorate)
 * @var array<string, string> $errors keyed by field
 * @var array<string, mixed> $old the rejected POST when re-rendering
 * @var list<array<string, mixed>> $contactOptions
 * @var list<array<string, mixed>> $organizations
 * @var list<array<string, mixed>> $owners
 * @var string $returnTo
 */

include __DIR__ . '/../layout/header.php';

$value = static fn(string $field): string => (string) (
    array_key_exists($field, $old) ? $old[$field] : ($task[$field] ?? '')
);
$inputClass = 'mt-1 w-full border border-brand-sand rounded-lg px-3 py-2 text-sm bg-white';
$labelClass = 'text-sm font-medium text-brand-inksoft';
$isDone = $task['completed_at'] !== null;
$errorLine = static fn(string $field): string => isset($errors[$field])
    ? '<p class="mt-1 text-xs text-brand-red">' . View::e($errors[$field]) . '</p>'
    : '';
?>

<h1 class="font-serif text-3xl text-brand-ink">Edit task</h1>
<p class="mt-1 text-sm text-brand-muted">
    <?= $isDone
        ? View::e(ucfirst((string) $task['done_label'])
            . (($task['completed_by_name'] ?? null) !== null ? ' by ' . (string) $task['completed_by_name'] : ''))
            . '.'
        : 'Open' . ((string) $task['due_label'] !== '' ? ', ' . View::e((string) $task['due_label']) : '') . '.' ?>
    Added by <?= View::e((string) $task['created_by_name']) ?> &middot;
    last updated by <?= View::e((string) $task['updated_by_name']) ?>
</p>

<form method="post" action="/tasks/<?= (int) $task['id'] ?>"
      class="mt-6 max-w-2xl bg-white rounded-lg shadow p-6 space-y-4">
    <?= Csrf::field() ?>
    <input type="hidden" name="return_to" value="<?= View::e($returnTo) ?>">

    <div>
        <label for="task-title" class="<?= $labelClass ?>">Task</label>
        <input type="text" id="task-title" name="title" required maxlength="255"
               value="<?= View::e($value('title')) ?>" class="<?= $inputClass ?>">
        <?= $errorLine('title') ?>
    </div>

    <div>
        <label for="task-notes" class="<?= $labelClass ?>">Notes</label>
        <textarea id="task-notes" name="notes" rows="3" class="<?= $inputClass ?>"
                  placeholder="Anything worth remembering (optional)"><?= View::e($value('notes')) ?></textarea>
        <?= $errorLine('notes') ?>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="task-due" class="<?= $labelClass ?>">Due date</label>
            <input type="date" id="task-due" name="due_date" value="<?= View::e($value('due_date')) ?>"
                   class="<?= $inputClass ?>">
            <p class="mt-1 text-xs text-brand-muted">Leave empty for no date.</p>
            <?= $errorLine('due_date') ?>
        </div>
        <div>
            <label for="task-owner" class="<?= $labelClass ?>">Owner</label>
            <select id="task-owner" name="owner_id" class="<?= $inputClass ?>">
                <option value="">Shared</option>
                <?php foreach ($owners as $owner) : ?>
                    <option value="<?= (int) $owner['id'] ?>"
                        <?= $value('owner_id') === (string) $owner['id'] ? 'selected' : '' ?>>
                        <?= View::e((string) $owner['name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <p class="mt-1 text-xs text-brand-muted">
                Shared tasks show up for everyone. Owned ones only for their owner.
            </p>
            <?= $errorLine('owner_id') ?>
        </div>
        <div>
            <label for="task-contact" class="<?= $labelClass ?>">About a contact</label>
            <select id="task-contact" name="contact_id" class="<?= $inputClass ?>">
                <option value="">No contact</option>
                <?php foreach ($contactOptions as $option) : ?>
                    <option value="<?= (int) $option['id'] ?>"
                        <?= $value('contact_id') === (string) $option['id'] ? 'selected' : '' ?>>
                        <?= View::e((string) $option['name']) ?><?=
                            ($option['organization_name'] ?? null) !== null
                                ? ' (' . View::e((string) $option['organization_name']) . ')'
                                : '' ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $errorLine('contact_id') ?>
        </div>
        <div>
            <label for="task-organization" class="<?= $labelClass ?>">Or about an organization</label>
            <select id="task-organization" name="organization_id" class="<?= $inputClass ?>">
                <option value="">No organization</option>
                <?php foreach ($organizations as $organization) : ?>
                    <option value="<?= (int) $organization['id'] ?>"
                        <?= $value('organization_id') === (string) $organization['id'] ? 'selected' : '' ?>>
                        <?= View::e((string) $organization['name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <p class="mt-1 text-xs text-brand-muted">
                Pick one or the other. A contact already brings its organization along.
            </p>
            <?= $errorLine('organization_id') ?>
        </div>
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit"
                class="bg-brand-red hover:bg-brand-reddark text-white rounded-lg px-5 py-2 text-sm font-medium">
            Save
        </button>
        <a href="<?= View::e($returnTo) ?>" class="text-sm text-brand-muted hover:text-brand-inksoft">Cancel</a>
    </div>
</form>

<div class="mt-4 max-w-2xl flex items-center gap-3">
    <form method="post" action="/tasks/<?= (int) $task['id'] ?>/<?= $isDone ? 'reopen' : 'complete' ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="<?= View::e($returnTo) ?>">
        <button type="submit"
                class="text-sm text-brand-inksoft border border-brand-sand hover:bg-brand-cream2
                       rounded-lg px-4 py-2">
            <?= $isDone ? 'Mark not done' : 'Mark done' ?>
        </button>
    </form>
    <form method="post" action="/tasks/<?= (int) $task['id'] ?>/delete"
          onsubmit="return confirm('Delete this task? This cannot be undone.');">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="<?= View::e($returnTo) ?>">
        <button type="submit" class="text-sm text-brand-muted hover:text-brand-red underline">
            Delete task
        </button>
    </form>
</div>

<?php include __DIR__ . '/../layout/footer.php';
