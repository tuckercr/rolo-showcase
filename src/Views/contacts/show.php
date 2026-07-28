<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var array<string, mixed> $contact
 * @var list<array<string, mixed>> $activities
 * @var array<string, string> $activityTypes loggable types (form select)
 * @var array<string, string> $historyTypes display labels incl. system entries
 * @var string $today
 * @var bool $activityError
 * @var array<int, string> $snoozeOptions days => label
 */

include __DIR__ . '/../layout/header.php';

$next = (string) ($contact['next_touch_date'] ?? '');
$isOverdue = $next !== '' && $next < $today;
$inputClass = 'mt-1 w-full border border-brand-sand rounded-lg px-3 py-2 text-sm';
?>

<div class="flex items-start justify-between flex-wrap gap-3">
    <div>
        <h1 class="font-serif text-3xl text-brand-ink"><?= View::e((string) $contact['name']) ?></h1>
        <p class="mt-1 text-sm text-brand-muted">
            <?= View::e((string) ($contact['title'] ?? '')) ?>
            <?php if (($contact['title'] ?? null) !== null && ($contact['organization_name'] ?? null) !== null) : ?>
                &middot;
            <?php endif ?>
            <?= View::e((string) ($contact['organization_name'] ?? '')) ?>
        </p>
    </div>
    <div class="flex items-center gap-2">
        <a href="/contacts/<?= (int) $contact['id'] ?>/edit"
           class="text-sm bg-brand-ink hover:bg-brand-red text-white px-4 py-2 rounded-lg">Edit</a>
        <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/trash"
              onsubmit="return confirm('Move <?= View::e((string) $contact['name']) ?> to the trash?\n'
                  + 'Nothing is lost — you can restore from Contacts > Trash.');">
            <?= Csrf::field() ?>
            <button type="submit"
                    class="text-sm text-brand-red border border-brand-red/40 hover:bg-red-50
                           px-4 py-2 rounded-lg">
                Move to trash
            </button>
        </form>
    </div>
</div>

<?php if (($contact['human_detail'] ?? null) !== null) : ?>
    <p class="mt-4 bg-brand-cream2 border border-brand-sand text-brand-inksoft text-sm rounded-lg px-4 py-2">
        <?= View::e((string) $contact['human_detail']) ?>
    </p>
<?php endif ?>

<div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <div class="bg-white rounded-lg shadow p-5 text-sm space-y-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-brand-muted">Stage</p>
                <p class="text-brand-ink font-medium"><?= View::e((string) $contact['relationship_status']) ?></p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-brand-muted">Next touch</p>
                <p class="<?= $isOverdue ? 'text-brand-red font-semibold' : 'text-brand-ink' ?>">
                    <?= View::e($next === '' ? 'No reminder' : $next) ?>
                    <?= $isOverdue ? ' (overdue)' : '' ?>
                </p>
                <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/snooze"
                      id="snooze-form" class="mt-1.5 flex items-center gap-1.5">
                    <?= Csrf::field() ?>
                    <select name="days" title="How long to snooze"
                            class="text-xs text-brand-inksoft border border-brand-sand rounded
                                   px-1.5 py-1 bg-white">
                        <?php foreach ($snoozeOptions as $days => $label) : ?>
                            <option value="<?= (int) $days ?>" <?= $days === 7 ? 'selected' : '' ?>>
                                <?= View::e($label) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                    <button type="submit"
                            class="text-xs text-brand-inksoft border border-brand-sand
                                   hover:bg-brand-cream2 rounded px-2.5 py-1"
                            title="Set the next touch to today + the chosen length">
                        Snooze
                    </button>
                </form>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-brand-muted">Last touch</p>
                <p class="text-brand-ink"><?= View::e((string) ($contact['last_touch_date'] ?? 'Never')) ?></p>
            </div>
            <?php if (($contact['cadence_days_override'] ?? null) !== null) : ?>
                <div>
                    <p class="text-xs uppercase tracking-wide text-brand-muted">Cadence override</p>
                    <p class="text-brand-ink">every <?= (int) $contact['cadence_days_override'] ?> days</p>
                </div>
            <?php endif ?>
            <?php if (($contact['relationship_type'] ?? null) !== null) : ?>
                <div>
                    <p class="text-xs uppercase tracking-wide text-brand-muted">Relationship type</p>
                    <p class="text-brand-ink">
                        <?= View::e(ucwords(str_replace('_', ' / ', (string) $contact['relationship_type']))) ?>
                    </p>
                </div>
            <?php endif ?>
        </div>

        <div class="bg-white rounded-lg shadow p-5 text-sm space-y-3">
            <?php if (($contact['email'] ?? null) !== null) : ?>
                <div>
                    <p class="text-xs uppercase tracking-wide text-brand-muted">Email</p>
                    <a href="mailto:<?= View::e((string) $contact['email']) ?>"
                       class="text-brand-red hover:underline"><?= View::e((string) $contact['email']) ?></a>
                </div>
            <?php endif ?>
            <?php if (($contact['phone'] ?? null) !== null) : ?>
                <div>
                    <p class="text-xs uppercase tracking-wide text-brand-muted">Phone</p>
                    <p class="text-brand-ink"><?= View::e((string) $contact['phone']) ?></p>
                </div>
            <?php endif ?>
            <?php if (($contact['linkedin_url'] ?? null) !== null) : ?>
                <div>
                    <p class="text-xs uppercase tracking-wide text-brand-muted">LinkedIn</p>
                    <a href="<?= View::e((string) $contact['linkedin_url']) ?>" target="_blank" rel="noopener"
                       class="text-brand-red hover:underline break-all">
                        <?= View::e((string) $contact['linkedin_url']) ?>
                    </a>
                </div>
            <?php endif ?>
            <p class="text-xs text-brand-muted pt-2 border-t">
                Added by <?= View::e((string) $contact['created_by_name']) ?> &middot;
                last updated by <?= View::e((string) $contact['updated_by_name']) ?>
            </p>
        </div>
    </div>

    <div class="md:col-span-2 space-y-6">
        <div class="bg-white rounded-lg shadow p-5">
            <h2 class="text-sm font-semibold text-brand-inksoft">Log an interaction</h2>
            <?php if ($activityError) : ?>
                <p class="mt-2 text-xs text-brand-red">
                    Couldn&rsquo;t save that — check the type and dates and try again.
                </p>
            <?php endif ?>
            <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/activities" class="mt-3">
                <?= Csrf::field() ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium text-brand-inksoft">Type</label>
                        <select name="activity_type" class="<?= $inputClass ?> bg-white">
                            <?php foreach ($activityTypes as $typeValue => $typeLabel) : ?>
                                <option value="<?= View::e($typeValue) ?>"><?= View::e($typeLabel) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-brand-inksoft">Date</label>
                        <input type="date" name="activity_date" value="<?= View::e($today) ?>"
                               class="<?= $inputClass ?>">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-sm font-medium text-brand-inksoft">What happened?</label>
                        <textarea name="summary" rows="2" class="<?= $inputClass ?>"
                                  placeholder="Quick summary of the interaction…"></textarea>
                    </div>
                    <div class="sm:col-span-2 flex items-center gap-4">
                        <label class="text-sm text-brand-inksoft flex items-center gap-2">
                            <input type="checkbox" name="follow_up_needed" value="1" class="rounded">
                            Follow-up needed
                        </label>
                        <input type="date" name="follow_up_date" class="border border-brand-sand
                               rounded-lg px-3 py-1.5 text-sm" title="Follow-up date (optional)">
                    </div>
                </div>
                <button type="submit"
                        class="mt-4 bg-brand-red hover:bg-brand-reddark text-white rounded-lg px-5 py-2
                               text-sm font-medium">
                    Log it
                </button>
                <span class="ml-2 text-xs text-brand-muted">
                    Updates last touch and reschedules the next one — a follow-up
                    date you set here wins over the stage cadence.
                </span>
            </form>
        </div>

        <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-inksoft">
                History (<?= count($activities) ?>)
            </h2>
            <?php if ($activities === []) : ?>
                <p class="mt-2 text-sm text-brand-muted">No interactions logged yet.</p>
            <?php else : ?>
                <ul class="mt-2 space-y-2">
                    <?php foreach ($activities as $activity) : ?>
                        <li class="bg-white rounded-lg shadow px-4 py-3 text-sm">
                            <?php $isSnooze = (string) $activity['activity_type'] === 'snoozed'; ?>
                            <div class="flex items-center justify-between flex-wrap gap-2">
                                <span class="font-medium text-brand-ink">
                                    <?= View::e($historyTypes[(string) $activity['activity_type']]
                                        ?? (string) $activity['activity_type']) ?>
                                </span>
                                <span class="text-brand-muted text-xs">
                                    <?= View::e((string) $activity['activity_date']) ?>
                                    &middot; logged by <?= View::e((string) $activity['created_by_name']) ?>
                                    <?php if (($activity['updated_by_name'] ?? null) !== null) : ?>
                                        &middot; edited by <?= View::e((string) $activity['updated_by_name']) ?>
                                    <?php endif ?>
                                    <?php if (!$isSnooze) : ?>
                                        &middot; <a href="/activities/<?= (int) $activity['id'] ?>/edit"
                                                    class="text-brand-red hover:underline">Edit</a>
                                    <?php endif ?>
                                </span>
                            </div>
                            <?php if ((string) ($activity['summary'] ?? '') !== '') : ?>
                                <p class="mt-1 text-brand-inksoft"><?= View::e((string) $activity['summary']) ?></p>
                            <?php endif ?>
                            <?php if ((int) $activity['follow_up_needed'] === 1) : ?>
                                <p class="mt-1 text-xs text-brand-red">
                                    Follow-up needed
                                    <?php if (($activity['follow_up_date'] ?? null) !== null) : ?>
                                        by <?= View::e((string) $activity['follow_up_date']) ?>
                                    <?php endif ?>
                                </p>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    </div>
</div>

<script>
    // Submit the snooze in place. A normal POST+redirect adds a second
    // history entry for this page, so Back had to be pressed twice to reach
    // the dashboard. fetch + reload keeps history at one entry; if JS is
    // unavailable the form still posts normally.
    (function () {
        var form = document.getElementById('snooze-form');
        if (!form) {
            return;
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            fetch(form.action, { method: 'POST', body: new FormData(form) })
                .then(function (response) {
                    if (response.ok) {
                        window.location.reload();
                    } else {
                        // Native submit doesn't re-fire this listener.
                        form.submit();
                    }
                })
                .catch(function () {
                    form.submit();
                });
        });
    })();
</script>

<?php include __DIR__ . '/../layout/footer.php';
