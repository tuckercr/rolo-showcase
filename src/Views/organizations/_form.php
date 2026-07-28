<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * Shared organization form fields. Included by create.php / edit.php, which set:
 *
 * @var string $formAction
 * @var string $submitLabel
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var array<string, string> $orgTypes
 */

$value = static fn(string $key): string => View::e((string) ($old[$key] ?? ''));
$fieldError = static function (string $key) use ($errors): string {
    if (!isset($errors[$key])) {
        return '';
    }

    return '<p class="mt-1 text-xs text-brand-red">' . View::e($errors[$key]) . '</p>';
};
$inputClass = 'mt-1 w-full border border-brand-sand rounded-lg px-3 py-2 text-sm';
?>
<form method="post" action="<?= View::e($formAction) ?>" class="bg-white rounded-lg shadow p-6 max-w-2xl">
    <?= Csrf::field() ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-medium text-brand-inksoft">Name *</label>
            <input type="text" name="name" value="<?= $value('name') ?>" required
                   autofocus class="<?= $inputClass ?>">
            <?= $fieldError('name') ?>
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Type</label>
            <select name="org_type" class="<?= $inputClass ?> bg-white">
                <?php $currentType = (string) ($old['org_type'] ?? 'other'); ?>
                <?php foreach ($orgTypes as $typeValue => $typeLabel) : ?>
                    <option value="<?= View::e($typeValue) ?>"
                        <?= $currentType === $typeValue ? 'selected' : '' ?>>
                        <?= View::e($typeLabel) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $fieldError('org_type') ?>
        </div>

        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft">Website</label>
            <input type="url" name="website" value="<?= $value('website') ?>"
                   placeholder="https://…" class="<?= $inputClass ?>">
            <?= $fieldError('website') ?>
        </div>

        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft">Notes</label>
            <textarea name="notes" rows="4" class="<?= $inputClass ?>"><?= $value('notes') ?></textarea>
        </div>
    </div>

    <div class="mt-6 flex items-center gap-3">
        <button type="submit"
                class="bg-brand-red hover:bg-brand-reddark text-white rounded-lg px-5 py-2 text-sm font-medium">
            <?= View::e($submitLabel) ?>
        </button>
        <a href="/organizations" class="text-sm text-brand-muted hover:underline">Cancel</a>
    </div>
</form>
