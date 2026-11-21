<?php

declare(strict_types=1);

use App\Support\View;

/**
 * @var list<array<string, mixed>> $contacts
 * @var list<array<string, mixed>> $stages
 * @var list<array<string, mixed>> $allTags name + contact_count
 * @var list<array<string, mixed>> $owners assignable users for the Owner filter
 * @var string $query
 * @var string $status
 * @var string $tag
 * @var string $owner '' (anyone), 'shared', or a user id as a string
 * @var string $sort current sort key (whitelisted by the controller)
 * @var string $dir 'asc'|'desc'
 * @var string $today
 * @var int $trashedCount
 */

include __DIR__ . '/../layout/header.php';

$sortHeader = static function (
    string $key,
    string $label,
) use (
    $query,
    $status,
    $tag,
    $owner,
    $sort,
    $dir,
): string {
    $isCurrent = $sort === $key;
    $nextDir = $isCurrent && $dir === 'asc' ? 'desc' : 'asc';
    $qs = http_build_query([
        'q' => $query,
        'status' => $status,
        'tag' => $tag,
        'owner' => $owner,
        'sort' => $key,
        'dir' => $nextDir,
    ]);
    $arrow = $isCurrent ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';

    return '<a href="/contacts?' . View::e($qs) . '" class="hover:text-brand-ink hover:underline">'
        . View::e($label) . '</a>' . $arrow;
};
?>

<div class="flex items-center justify-between">
    <h1 class="font-serif text-3xl text-brand-ink">Contacts</h1>
    <?php if ($trashedCount > 0) : ?>
        <a href="/contacts/trash" class="text-sm text-brand-muted hover:text-brand-red hover:underline">
            Trash (<?= $trashedCount ?>)
        </a>
    <?php endif ?>
</div>

<form method="get" action="/contacts" class="mt-4 flex flex-wrap gap-2">
    <input type="search" name="q" value="<?= View::e($query) ?>"
           placeholder="Search name or organization…"
           class="border border-brand-sand rounded-lg px-3 py-2 text-sm w-64">
    <select name="status" class="border border-brand-sand rounded-lg px-3 py-2 text-sm bg-white">
        <option value="">All stages</option>
        <?php foreach ($stages as $stage) : ?>
            <option value="<?= View::e((string) $stage['name']) ?>"
                <?= $status === (string) $stage['name'] ? 'selected' : '' ?>>
                <?= View::e((string) $stage['name']) ?>
            </option>
        <?php endforeach ?>
    </select>
    <?php if ($allTags !== []) : ?>
        <select name="tag" class="border border-brand-sand rounded-lg px-3 py-2 text-sm bg-white">
            <option value="">All tags</option>
            <?php foreach ($allTags as $tagRow) : ?>
                <option value="<?= View::e((string) $tagRow['name']) ?>"
                    <?= $tag === (string) $tagRow['name'] ? 'selected' : '' ?>>
                    <?= View::e((string) $tagRow['name']) ?> (<?= (int) $tagRow['contact_count'] ?>)
                </option>
            <?php endforeach ?>
        </select>
    <?php endif ?>
    <select name="owner" class="border border-brand-sand rounded-lg px-3 py-2 text-sm bg-white">
        <option value="">Any owner</option>
        <?php foreach ($owners as $ownerRow) : ?>
            <option value="<?= (int) $ownerRow['id'] ?>"
                <?= $owner === (string) $ownerRow['id'] ? 'selected' : '' ?>>
                <?= View::e((string) $ownerRow['name']) ?>
            </option>
        <?php endforeach ?>
        <option value="shared" <?= $owner === 'shared' ? 'selected' : '' ?>>Shared</option>
    </select>
    <input type="hidden" name="sort" value="<?= View::e($sort) ?>">
    <input type="hidden" name="dir" value="<?= View::e($dir) ?>">
    <button type="submit"
            class="bg-brand-ink hover:bg-brand-red text-white rounded-lg px-4 py-2 text-sm">
        Filter
    </button>
</form>

<?php if ($contacts === []) : ?>
    <p class="mt-8 text-sm text-brand-muted">
        No contacts found. <a href="/contacts/new" class="text-brand-red hover:underline">Add the first one</a>.
    </p>
<?php else : ?>
    <div class="mt-4 bg-white rounded-lg shadow overflow-x-auto relative">
        <table class="w-full text-sm">
            <thead class="text-left text-brand-muted border-b">
                <tr>
                    <th class="px-4 py-2 font-medium"><?= $sortHeader('name', 'Name') ?></th>
                    <th class="px-4 py-2 font-medium"><?= $sortHeader('organization', 'Organization') ?></th>
                    <th class="px-4 py-2 font-medium"><?= $sortHeader('stage', 'Stage') ?></th>
                    <th class="px-4 py-2 font-medium"><?= $sortHeader('last_touch', 'Last contacted') ?></th>
                    <th class="px-4 py-2 font-medium"><?= $sortHeader('next_touch', 'Next follow-up') ?></th>
                    <th class="px-4 py-2 font-medium">Owner</th>
                    <th class="px-4 py-2"></th>
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
                        <td class="px-4 py-2 text-brand-inksoft">
                            <?= View::e((string) ($contact['last_touch_date'] ?? 'Never')) ?>
                        </td>
                        <td class="px-4 py-2 <?= $isOverdue ? 'text-brand-red font-medium' : 'text-brand-inksoft' ?>">
                            <?= View::e($next === '' ? 'No reminder' : $next) ?>
                        </td>
                        <td class="px-4 py-2 text-brand-muted">
                            <?= $contact['owner_id'] === null
                                ? 'Shared'
                                : View::e((string) $contact['owner_name']) ?>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="/contacts/<?= (int) $contact['id'] ?>/edit"
                               class="text-xs text-brand-red hover:underline">Edit</a>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?php include __DIR__ . '/../layout/footer.php';
