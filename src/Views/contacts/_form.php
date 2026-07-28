<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * Shared contact form fields. Included by create.php / edit.php, which set:
 *
 * @var string $formAction
 * @var string $submitLabel
 * @var array<string, mixed> $old current values (POST on error, else the contact row)
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $stages
 * @var list<array<string, mixed>> $organizations
 * @var list<string> $relationshipTypes
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
        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft">Name *</label>
            <input type="text" name="name" value="<?= $value('name') ?>" required
                   autofocus class="<?= $inputClass ?>">
            <?= $fieldError('name') ?>
            <p class="mt-1 text-xs text-brand-muted">
                Only this field is required — capture now, enrich later.
            </p>
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Organization</label>
            <select name="organization_id" class="<?= $inputClass ?> bg-white">
                <option value="">— None —</option>
                <?php foreach ($organizations as $org) : ?>
                    <option value="<?= (int) $org['id'] ?>"
                        <?= (string) ($old['organization_id'] ?? '') === (string) $org['id'] ? 'selected' : '' ?>>
                        <?= View::e((string) $org['name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $fieldError('organization_id') ?>
            <p class="mt-1 text-xs text-brand-muted">
                Missing one? <a href="/organizations/new" class="text-brand-red hover:underline">Add
                an organization</a> first.
            </p>
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Title / role</label>
            <input type="text" name="title" value="<?= $value('title') ?>" class="<?= $inputClass ?>">
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Stage</label>
            <select name="relationship_status" class="<?= $inputClass ?> bg-white">
                <?php $currentStatus = (string) ($old['relationship_status'] ?? 'New/Captured'); ?>
                <?php foreach ($stages as $stage) : ?>
                    <option value="<?= View::e((string) $stage['name']) ?>"
                        <?= $currentStatus === (string) $stage['name'] ? 'selected' : '' ?>>
                        <?= View::e((string) $stage['name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $fieldError('relationship_status') ?>
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Relationship type</label>
            <select name="relationship_type" class="<?= $inputClass ?> bg-white">
                <option value="">— None —</option>
                <?php foreach ($relationshipTypes as $type) : ?>
                    <option value="<?= View::e($type) ?>"
                        <?= (string) ($old['relationship_type'] ?? '') === $type ? 'selected' : '' ?>>
                        <?= View::e(ucwords(str_replace('_', ' / ', $type))) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $fieldError('relationship_type') ?>
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Email</label>
            <input type="email" name="email" value="<?= $value('email') ?>" class="<?= $inputClass ?>">
            <?= $fieldError('email') ?>
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Phone</label>
            <input type="text" name="phone" value="<?= $value('phone') ?>" class="<?= $inputClass ?>">
            <?= $fieldError('phone') ?>
        </div>

        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft">LinkedIn URL</label>
            <input type="url" name="linkedin_url" value="<?= $value('linkedin_url') ?>"
                   placeholder="https://www.linkedin.com/in/…" class="<?= $inputClass ?>">
            <?= $fieldError('linkedin_url') ?>
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Cadence override (days)</label>
            <input type="number" name="cadence_days_override" min="1"
                   value="<?= $value('cadence_days_override') ?>" class="<?= $inputClass ?>">
            <?= $fieldError('cadence_days_override') ?>
            <p class="mt-1 text-xs text-brand-muted">
                Overrides the stage&rsquo;s default follow-up cadence for this contact.
            </p>
        </div>

        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft">Human detail</label>
            <input type="text" name="human_detail" value="<?= $value('human_detail') ?>"
                   placeholder="One memorable line about this person…" class="<?= $inputClass ?>">
        </div>
    </div>

    <div class="mt-6 flex items-center gap-3">
        <button type="submit"
                class="bg-brand-red hover:bg-brand-reddark text-white rounded-lg px-5 py-2 text-sm font-medium">
            <?= View::e($submitLabel) ?>
        </button>
        <a href="/contacts" class="text-sm text-brand-muted hover:underline">Cancel</a>
    </div>
</form>
