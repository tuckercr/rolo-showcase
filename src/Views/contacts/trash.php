<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var list<array<string, mixed>> $contacts trashed rows (+ deleted_at_local)
 */

include __DIR__ . '/../layout/header.php';
?>

<div class="flex items-center justify-between">
    <h1 class="font-serif text-3xl text-brand-ink">Trash</h1>
    <a href="/contacts" class="text-sm text-brand-red hover:underline">&larr; Back to contacts</a>
</div>

<p class="mt-2 text-sm text-brand-muted max-w-2xl">
    Trashed contacts keep their full history and can be restored anytime.
    While here, they don&rsquo;t appear in lists or search, and they never
    show up on the dashboard or in the morning summary email.
</p>

<?php if ($contacts === []) : ?>
    <p class="mt-8 text-sm text-brand-muted">The trash is empty.</p>
<?php else : ?>
    <div class="mt-4 bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-brand-muted border-b">
                <tr>
                    <th class="px-4 py-2 font-medium">Name</th>
                    <th class="px-4 py-2 font-medium">Organization</th>
                    <th class="px-4 py-2 font-medium">Stage</th>
                    <th class="px-4 py-2 font-medium">Trashed</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $contact) : ?>
                    <tr class="border-b last:border-0 hover:bg-brand-cream">
                        <td class="px-4 py-2 font-medium text-brand-inksoft">
                            <?= View::e((string) $contact['name']) ?>
                        </td>
                        <td class="px-4 py-2 text-brand-inksoft">
                            <?= View::e((string) ($contact['organization_name'] ?? '—')) ?>
                        </td>
                        <td class="px-4 py-2 text-brand-inksoft">
                            <?= View::e((string) $contact['relationship_status']) ?>
                        </td>
                        <td class="px-4 py-2 text-brand-muted">
                            <?= View::e((string) $contact['deleted_at_local']) ?>
                            <?php if (($contact['deleted_by_name'] ?? null) !== null) : ?>
                                by <?= View::e((string) $contact['deleted_by_name']) ?>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/restore"
                                  class="inline">
                                <?= Csrf::field() ?>
                                <button type="submit"
                                        class="text-xs bg-brand-ink hover:bg-brand-red text-white
                                               rounded px-3 py-1.5">
                                    Restore
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?php include __DIR__ . '/../layout/footer.php';
