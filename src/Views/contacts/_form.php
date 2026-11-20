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
 * @var list<string> $allTagNames existing tag names, for suggest-while-typing
 * @var list<array<string, mixed>> $owners assignable users for the Owner select
 * @var array<string, mixed>|null $currentUser from the layout (Controller::render)
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
                Only this field is required. Capture now, enrich later.
            </p>
        </div>

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Organization</label>
            <select name="organization_id" class="<?= $inputClass ?> bg-white">
                <option value="">None</option>
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
                <option value="">None</option>
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

        <div>
            <label class="text-sm font-medium text-brand-inksoft">Owner</label>
            <?php
            // A brand-new form defaults to "me"; after that (edit, or a
            // re-render on errors) the submitted/stored value wins — null
            // stays Shared rather than falling back to the current user.
            $ownerValue = array_key_exists('owner_id', $old)
                ? (string) ($old['owner_id'] ?? '')
                : (string) ($currentUser['id'] ?? '');
            ?>
            <select name="owner_id" class="<?= $inputClass ?> bg-white">
                <option value="">Shared (everyone)</option>
                <?php foreach ($owners as $owner) : ?>
                    <option value="<?= (int) $owner['id'] ?>"
                        <?= $ownerValue === (string) $owner['id'] ? 'selected' : '' ?>>
                        <?= View::e((string) $owner['name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?= $fieldError('owner_id') ?>
            <p class="mt-1 text-xs text-brand-muted">
                Owned contacts drive the owner&rsquo;s follow-ups; shared ones show for everyone.
            </p>
        </div>

        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft">Human detail</label>
            <input type="text" name="human_detail" value="<?= $value('human_detail') ?>"
                   placeholder="One memorable line about this person…" class="<?= $inputClass ?>">
        </div>

        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-brand-inksoft" for="tags-input">Tags</label>
            <div class="relative">
                <input type="text" name="tags" id="tags-input" value="<?= $value('tags') ?>"
                       placeholder="Comma-separated, e.g. Nashville, Academic Innovation"
                       autocomplete="off"
                       data-tag-names="<?= View::e((string) json_encode($allTagNames ?? [])) ?>"
                       class="<?= $inputClass ?>">
                <div id="tags-suggestions" hidden
                     class="absolute z-10 left-0 right-0 mt-1 bg-white border border-brand-sand
                            rounded-lg shadow max-h-48 overflow-y-auto"></div>
            </div>
            <?= $fieldError('tags') ?>
            <p class="mt-1 text-xs text-brand-muted">
                Freeform context labels. New tags are created automatically &mdash;
                existing ones are suggested while you type (manage them under
                <a href="/tags" class="underline hover:text-brand-red">Tags</a>).
            </p>
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

<script>
    (function () {
        var input = document.getElementById('tags-input');
        var box = document.getElementById('tags-suggestions');

        if (!input || !box) {
            return;
        }

        var names = [];

        try {
            names = JSON.parse(input.getAttribute('data-tag-names') || '[]');
        } catch (e) {
            return;
        }

        var active = -1;

        function segments(value) {
            return value.split(',').map(function (s) { return s.trim(); });
        }

        function close() {
            box.hidden = true;
            box.textContent = '';
            active = -1;
        }

        function pick(name) {
            var parts = segments(input.value);
            parts[parts.length - 1] = name;
            input.value = parts.filter(function (s) { return s !== ''; }).join(', ') + ', ';
            input.focus();
            close();
        }

        function render(matches) {
            close();

            if (matches.length === 0) {
                return;
            }

            matches.forEach(function (name) {
                var item = document.createElement('button');
                item.type = 'button';
                item.textContent = name;
                item.className = 'block w-full text-left px-3 py-1.5 text-sm hover:bg-brand-cream';
                // mousedown so the click lands before the input's blur closes the box
                item.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    pick(name);
                });
                box.appendChild(item);
            });
            box.hidden = false;
        }

        function update() {
            var parts = segments(input.value);
            var current = parts[parts.length - 1].toLowerCase();
            var chosen = parts.slice(0, -1).map(function (s) { return s.toLowerCase(); });

            if (current === '') {
                close();
                return;
            }

            render(names.filter(function (name) {
                var lower = name.toLowerCase();

                return lower.indexOf(current) !== -1
                    && lower !== current
                    && chosen.indexOf(lower) === -1;
            }).slice(0, 8));
        }

        input.addEventListener('input', update);
        input.addEventListener('blur', close);
        input.addEventListener('keydown', function (event) {
            var items = box.querySelectorAll('button');

            if (box.hidden || items.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                active += event.key === 'ArrowDown' ? 1 : -1;
                active = (active + items.length) % items.length;
                items.forEach(function (item, i) {
                    item.classList.toggle('bg-brand-cream', i === active);
                });
            } else if (event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                pick(items[active].textContent);
            } else if (event.key === 'Escape') {
                close();
            }
        });
    })();
</script>
