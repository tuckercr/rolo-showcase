<?php

declare(strict_types=1);

use App\Support\View;

/**
 * @var array<string, mixed> $organization
 * @var array<string, string> $orgTypes
 * @var list<array<string, mixed>> $contacts
 * @var string $today
 */

include __DIR__ . '/../layout/header.php';
?>

<div class="flex items-start justify-between flex-wrap gap-3">
    <div>
        <h1 class="font-serif text-3xl text-brand-ink"><?= View::e((string) $organization['name']) ?></h1>
        <p class="mt-1 text-sm text-brand-muted">
            <?= View::e($orgTypes[(string) $organization['org_type']] ?? (string) $organization['org_type']) ?>
            <?php if (($organization['website'] ?? null) !== null) : ?>
                &middot;
                <a href="<?= View::e((string) $organization['website']) ?>" target="_blank" rel="noopener"
                   class="text-brand-red hover:underline">
                    <?= View::e((string) $organization['website']) ?>
                </a>
            <?php endif ?>
            <?php if (($organization['linkedin_url'] ?? null) !== null) : ?>
                &middot;
                <a href="<?= View::e((string) $organization['linkedin_url']) ?>" target="_blank"
                   rel="noopener" class="text-brand-red hover:underline">LinkedIn</a>
            <?php endif ?>
        </p>
    </div>
    <a href="/organizations/<?= (int) $organization['id'] ?>/edit"
       class="text-sm bg-brand-ink hover:bg-brand-red text-white px-4 py-2 rounded-lg">Edit</a>
</div>

<?php if (($organization['notes'] ?? null) !== null) : ?>
    <div class="mt-4 bg-white rounded-lg shadow p-5 text-sm text-brand-inksoft whitespace-pre-line max-w-2xl">
        <?= View::e((string) $organization['notes']) ?>
    </div>
<?php endif ?>

<section class="mt-8">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-inksoft">
        Contacts here (<?= count($contacts) ?>)
    </h2>

    <?php if ($contacts === []) : ?>
        <p class="mt-2 text-sm text-brand-muted">
            No contacts at this organization yet.
            <a href="/contacts/new" class="text-brand-red hover:underline">Add one</a>.
        </p>
    <?php else : ?>
        <div class="mt-2 bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-brand-muted border-b">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Title</th>
                        <th class="px-4 py-2 font-medium">Stage</th>
                        <th class="px-4 py-2 font-medium">Next touch</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $contact) : ?>
                        <?php
                        $next = (string) ($contact['next_touch_date'] ?? '');
                        $isOverdue = $next !== '' && $next < $today;
                        ?>
                        <tr class="border-b last:border-0 hover:bg-brand-cream">
                            <td class="px-4 py-2">
                                <a href="/contacts/<?= (int) $contact['id'] ?>"
                                   class="text-brand-red hover:underline font-medium">
                                    <?= View::e((string) $contact['name']) ?>
                                </a>
                            </td>
                            <td class="px-4 py-2 text-brand-inksoft">
                                <?= View::e((string) ($contact['title'] ?? '—')) ?>
                            </td>
                            <td class="px-4 py-2 text-brand-inksoft">
                                <?= View::e((string) $contact['relationship_status']) ?>
                            </td>
                            <?php $dateClass = $isOverdue ? 'text-brand-red font-medium' : 'text-brand-inksoft'; ?>
                            <td class="px-4 py-2 <?= $dateClass ?>">
                                <?= View::e($next === '' ? '—' : $next) ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>

<?php include __DIR__ . '/../layout/footer.php';
