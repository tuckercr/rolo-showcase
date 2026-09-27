<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var array<string, mixed> $activity
 * @var array<string, mixed>|null $contact
 * @var array<string, string> $activityTypes
 * @var list<array<string, mixed>> $attachments
 * @var bool $attachmentError
 * @var bool $storageError
 * @var string $allowedTypesLabel
 */

include __DIR__ . '/../layout/header.php';

$contactName = $contact === null ? 'contact' : (string) $contact['name'];
$contactUrl = '/contacts/' . (int) $activity['contact_id'];
$inputClass = 'mt-1 w-full border border-brand-sand rounded-lg px-3 py-2 text-sm';
$followUpValue = (string) ($activity['follow_up_date'] ?? '');
?>

<h1 class="font-serif text-3xl text-brand-ink mb-1">Edit interaction</h1>
<p class="text-sm text-brand-muted mb-6">
    With <a href="<?= View::e($contactUrl) ?>" class="text-brand-red hover:underline">
        <?= View::e($contactName) ?></a>
    on <?= View::e((string) $activity['activity_date']) ?>
    &middot; logged by <?= View::e((string) $activity['created_by_name']) ?>
</p>

<?php if ($attachmentError) : ?>
    <p class="mb-4 max-w-2xl text-sm text-brand-red bg-red-50 border border-red-200 rounded-lg px-4 py-2">
        Attachment refused — up to 5 files per interaction, 10&nbsp;MB each
        (<?= View::e($allowedTypesLabel) ?>). No changes were saved.
    </p>
<?php endif ?>
<?php if ($storageError) : ?>
    <p class="mb-4 max-w-2xl text-sm text-brand-red bg-red-50 border border-red-200 rounded-lg px-4 py-2">
        Couldn&rsquo;t save your file — your other changes went through, but the server
        had a storage problem writing the attachment. Try again in a moment.
    </p>
<?php endif ?>

<form method="post" action="/activities/<?= (int) $activity['id'] ?>"
      enctype="multipart/form-data" class="bg-white rounded-lg shadow p-6 max-w-2xl">
    <?= Csrf::field() ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-medium text-brand-inksoft">Type</label>
            <select name="activity_type" class="<?= $inputClass ?> bg-white">
                <?php foreach ($activityTypes as $typeValue => $typeLabel) : ?>
                    <option value="<?= View::e($typeValue) ?>"
                        <?= (string) $activity['activity_type'] === $typeValue ? 'selected' : '' ?>>
                        <?= View::e($typeLabel) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Date</label>
            <input type="date" name="activity_date"
                   value="<?= View::e((string) $activity['activity_date']) ?>"
                   class="<?= $inputClass ?>">
        </div>

        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft">What happened?</label>
            <textarea name="summary" rows="4"
                      class="<?= $inputClass ?>"><?= View::e((string) ($activity['summary'] ?? '')) ?></textarea>
        </div>

        <div class="sm:col-span-2 flex items-center gap-4">
            <label class="text-sm text-brand-inksoft flex items-center gap-2">
                <input type="checkbox" name="follow_up_needed" value="1" class="rounded"
                       onchange="document.getElementById('fu-note-edit')
                           .classList.toggle('hidden', !this.checked)"
                    <?= (int) $activity['follow_up_needed'] === 1 ? 'checked' : '' ?>>
                Follow-up needed
            </label>
            <input type="date" name="follow_up_date" value="<?= View::e($followUpValue) ?>"
                   class="border border-brand-sand rounded-lg px-3 py-1.5 text-sm"
                   title="Follow-up date (optional)">
        </div>
        <div id="fu-note-edit"
             class="sm:col-span-2 <?= (int) $activity['follow_up_needed'] === 1 ? '' : 'hidden' ?>">
            <input type="text" name="follow_up_note" maxlength="255"
                   value="<?= View::e((string) ($activity['follow_up_note'] ?? '')) ?>"
                   class="<?= $inputClass ?>"
                   placeholder="What needs doing? e.g. Send the proposal draft">
        </div>

        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft">Add attachments</label>
            <input type="file" name="attachments[]" multiple
                   class="mt-1 block w-full text-sm text-brand-muted">
            <p class="mt-1 text-xs text-brand-muted">
                Up to 5 files per interaction, 10&nbsp;MB each (<?= View::e($allowedTypesLabel) ?>).
            </p>
        </div>
    </div>

    <p class="mt-4 text-xs text-brand-muted">
        Saving rebuilds the contact&rsquo;s last touch and next touch from the
        full history — a follow-up date on the most recent interaction wins
        over the stage cadence.
    </p>

    <div class="mt-6 flex items-center gap-3">
        <button type="submit"
                class="bg-brand-red hover:bg-brand-reddark text-white rounded-lg px-5 py-2 text-sm font-medium">
            Save changes
        </button>
        <a href="<?= View::e($contactUrl) ?>" class="text-sm text-brand-muted hover:underline">Cancel</a>
    </div>
</form>

<?php if ($attachments !== []) : ?>
    <div class="mt-4 max-w-2xl bg-white rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-brand-inksoft">Attachments (<?= count($attachments) ?>)</h2>
        <ul class="mt-2 space-y-2">
            <?php foreach ($attachments as $att) : ?>
                <li class="flex items-center justify-between gap-3 text-sm">
                    <a href="/attachments/<?= (int) $att['id'] ?>"
                       class="text-brand-red hover:underline truncate">
                        &#128206; <?= View::e((string) $att['original_name']) ?>
                    </a>
                    <span class="text-xs text-brand-muted whitespace-nowrap">
                        <?= number_format(((int) $att['size_bytes']) / 1024) ?> KB
                    </span>
                    <form method="post" action="/attachments/<?= (int) $att['id'] ?>/delete"
                          onsubmit="return confirm('Remove this attachment permanently?');">
                        <?= Csrf::field() ?>
                        <button type="submit" class="text-xs text-brand-muted hover:text-brand-red
                                                     underline">Remove</button>
                    </form>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<form method="post" action="/activities/<?= (int) $activity['id'] ?>/delete" class="mt-4 max-w-2xl"
      onsubmit="return confirm('Delete this interaction permanently? This cannot be undone.');">
    <?= Csrf::field() ?>
    <button type="submit"
            class="text-sm text-brand-red border border-brand-red/40 hover:bg-red-50
                   rounded-lg px-4 py-2">
        Delete this interaction
    </button>
    <span class="ml-2 text-xs text-brand-muted">
        Removes it from the history and recalculates the contact&rsquo;s touch dates.
    </span>
</form>

<?php include __DIR__ . '/../layout/footer.php';
