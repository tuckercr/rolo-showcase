<?php

declare(strict_types=1);

use App\Support\View;

/**
 * @var list<array<string, mixed>> $organizations
 * @var array<string, string> $orgTypes
 * @var string $query
 * @var string $orgType
 */

include __DIR__ . '/../layout/header.php';
?>

<div class="flex items-center justify-between">
    <h1 class="font-serif text-3xl text-brand-ink">Organizations</h1>
    <a href="/organizations/new"
       class="text-sm bg-brand-red hover:bg-brand-reddark text-white px-4 py-2 rounded-lg">
        + Add organization
    </a>
</div>

<form method="get" action="/organizations" class="mt-4 flex flex-wrap gap-2">
    <input type="search" name="q" value="<?= View::e($query) ?>" placeholder="Search organizations…"
           class="border border-brand-sand rounded-lg px-3 py-2 text-sm w-64">
    <select name="type" class="border border-brand-sand rounded-lg px-3 py-2 text-sm bg-white">
        <option value="">All types</option>
        <?php foreach ($orgTypes as $typeValue => $typeLabel) : ?>
            <option value="<?= View::e($typeValue) ?>" <?= $orgType === $typeValue ? 'selected' : '' ?>>
                <?= View::e($typeLabel) ?>
            </option>
        <?php endforeach ?>
    </select>
    <button type="submit"
            class="bg-brand-ink hover:bg-brand-red text-white rounded-lg px-4 py-2 text-sm">
        Filter
    </button>
</form>

<?php if ($organizations === []) : ?>
    <p class="mt-8 text-sm text-brand-muted">
        No organizations found.
        <a href="/organizations/new" class="text-brand-red hover:underline">Add the first one</a>.
    </p>
<?php else : ?>
    <div class="mt-4 bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-brand-muted border-b">
                <tr>
                    <th class="px-4 py-2 font-medium">Name</th>
                    <th class="px-4 py-2 font-medium">Type</th>
                    <th class="px-4 py-2 font-medium">Website</th>
                    <th class="px-4 py-2 font-medium">Contacts</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($organizations as $org) : ?>
                    <tr class="border-b last:border-0 hover:bg-brand-cream">
                        <td class="px-4 py-2">
                            <a href="/organizations/<?= (int) $org['id'] ?>"
                               class="text-brand-red hover:underline font-medium">
                                <?= View::e((string) $org['name']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-2 text-brand-inksoft">
                            <?= View::e($orgTypes[(string) $org['org_type']] ?? (string) $org['org_type']) ?>
                        </td>
                        <td class="px-4 py-2 text-brand-inksoft">
                            <?php if (($org['website'] ?? null) !== null) : ?>
                                <a href="<?= View::e((string) $org['website']) ?>" target="_blank" rel="noopener"
                                   class="text-brand-red hover:underline">
                                    <?= View::e((string) $org['website']) ?>
                                </a>
                            <?php else : ?>
                                —
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2 text-brand-inksoft"><?= (int) $org['contact_count'] ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?php include __DIR__ . '/../layout/footer.php';
