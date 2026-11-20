<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * The one-line "add a task" form. On a contact or organization page the
 * link is fixed (hidden field); elsewhere an optional contact picker is
 * offered. A task added here belongs to the person adding it unless the
 * owner picker (the /tasks page) says otherwise.
 *
 * @var array{
 *     returnTo: string,
 *     contactId?: int|null,
 *     organizationId?: int|null,
 *     contactOptions?: list<array<string, mixed>>|null,
 *     owners?: list<array<string, mixed>>|null
 * } $taskAdd
 * @var array<string, mixed>|null $currentUser
 */

$taskAddContactId = $taskAdd['contactId'] ?? null;
$taskAddOrganizationId = $taskAdd['organizationId'] ?? null;
$taskAddContacts = $taskAdd['contactOptions'] ?? null;
$taskAddOwners = $taskAdd['owners'] ?? null;
$taskAddField = 'text-sm border border-brand-sand rounded-lg px-3 py-1.5 bg-white';
?>
<form method="post" action="/tasks" class="flex flex-wrap items-center gap-2 px-4 py-2.5">
    <?= Csrf::field() ?>
    <input type="hidden" name="return_to" value="<?= View::e((string) $taskAdd['returnTo']) ?>">
    <?php if ($taskAddContactId !== null) : ?>
        <input type="hidden" name="contact_id" value="<?= (int) $taskAddContactId ?>">
    <?php elseif ($taskAddOrganizationId !== null) : ?>
        <input type="hidden" name="organization_id" value="<?= (int) $taskAddOrganizationId ?>">
    <?php endif ?>
    <input type="text" name="title" required maxlength="255" autocomplete="off"
           placeholder="Add a task" aria-label="New task"
           class="flex-1 min-w-[9rem] <?= $taskAddField ?>">
    <?php if ($taskAddContactId === null && $taskAddOrganizationId === null && $taskAddContacts !== null) : ?>
        <select name="contact_id" aria-label="About a contact (optional)"
                class="max-w-[13rem] text-brand-inksoft <?= $taskAddField ?>">
            <option value="">No contact</option>
            <?php foreach ($taskAddContacts as $option) : ?>
                <option value="<?= (int) $option['id'] ?>">
                    <?= View::e((string) $option['name']) ?><?=
                        ($option['organization_name'] ?? null) !== null
                            ? ' (' . View::e((string) $option['organization_name']) . ')'
                            : '' ?>
                </option>
            <?php endforeach ?>
        </select>
    <?php endif ?>
    <?php if ($taskAddOwners !== null) : ?>
        <select name="owner_id" aria-label="Owner" class="text-brand-inksoft <?= $taskAddField ?>">
            <?php foreach ($taskAddOwners as $owner) : ?>
                <option value="<?= (int) $owner['id'] ?>"
                    <?= (int) $owner['id'] === (int) ($currentUser['id'] ?? 0) ? 'selected' : '' ?>>
                    <?= View::e((string) $owner['name']) ?>
                </option>
            <?php endforeach ?>
            <option value="">Shared</option>
        </select>
    <?php endif ?>
    <input type="date" name="due_date" aria-label="Due date (optional)" title="Due date (optional)"
           class="text-brand-inksoft <?= $taskAddField ?>">
    <button type="submit"
            class="text-sm bg-brand-red hover:bg-brand-reddark text-white rounded-lg px-4 py-1.5">
        Add
    </button>
</form>
