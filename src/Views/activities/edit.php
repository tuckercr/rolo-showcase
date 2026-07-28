<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var array<string, mixed> $activity
 * @var array<string, mixed>|null $contact
 * @var array<string, string> $activityTypes
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

<form method="post" action="/activities/<?= (int) $activity['id'] ?>"
      class="bg-white rounded-lg shadow p-6 max-w-2xl">
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
            <input type="text" value="<?= View::e((string) $activity['activity_date']) ?>" disabled
                   class="<?= $inputClass ?> bg-brand-cream text-brand-muted">
            <p class="mt-1 text-xs text-brand-muted">The date can&rsquo;t be changed after logging.</p>
        </div>

        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft">What happened?</label>
            <textarea name="summary" rows="4"
                      class="<?= $inputClass ?>"><?= View::e((string) ($activity['summary'] ?? '')) ?></textarea>
        </div>

        <div class="sm:col-span-2 flex items-center gap-4">
            <label class="text-sm text-brand-inksoft flex items-center gap-2">
                <input type="checkbox" name="follow_up_needed" value="1" class="rounded"
                    <?= (int) $activity['follow_up_needed'] === 1 ? 'checked' : '' ?>>
                Follow-up needed
            </label>
            <input type="date" name="follow_up_date" value="<?= View::e($followUpValue) ?>"
                   class="border border-brand-sand rounded-lg px-3 py-1.5 text-sm"
                   title="Follow-up date (optional)">
        </div>
    </div>

    <p class="mt-4 text-xs text-brand-muted">
        If this is the most recent interaction, changing the follow-up here
        reschedules the contact&rsquo;s next touch.
    </p>

    <div class="mt-6 flex items-center gap-3">
        <button type="submit"
                class="bg-brand-red hover:bg-brand-reddark text-white rounded-lg px-5 py-2 text-sm font-medium">
            Save changes
        </button>
        <a href="<?= View::e($contactUrl) ?>" class="text-sm text-brand-muted hover:underline">Cancel</a>
    </div>
</form>

<?php include __DIR__ . '/../layout/footer.php';
