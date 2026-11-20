<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var list<array<string, mixed>> $tags
 * @var string|null $notice
 * @var string $error
 */

include __DIR__ . '/../layout/header.php';
?>

<div class="flex items-baseline justify-between">
    <h1 class="font-serif text-3xl text-brand-ink">Tags</h1>
</div>
<p class="mt-2 text-sm text-brand-muted max-w-2xl">
    Freeform groupings across contacts. Renaming a tag onto an existing name merges the two.
    A tag with no contacts disappears automatically unless it has a description, which marks
    it as worth keeping while empty.
</p>

<?php if ($notice !== null) : ?>
    <p class="mt-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2">
        <?= View::e($notice) ?>
    </p>
<?php endif ?>
<?php if ($error !== '') : ?>
    <p class="mt-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2">
        <?= View::e($error) ?>
    </p>
<?php endif ?>

<?php if ($tags === []) : ?>
    <p class="mt-6 text-sm text-brand-muted">
        No tags yet. Add some on any contact&rsquo;s form &mdash; they are created automatically.
    </p>
<?php else : ?>
    <div class="mt-6 bg-white rounded-lg shadow">
        <table class="w-full text-sm">
            <thead class="text-left text-brand-muted border-b">
                <tr>
                    <th class="px-4 py-2 font-medium">Tag</th>
                    <th class="px-4 py-2 font-medium hidden sm:table-cell">Description</th>
                    <th class="px-4 py-2 font-medium text-right">Contacts</th>
                    <th class="px-4 py-2 w-20"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tags as $tag) : ?>
                    <?php $tagId = (int) $tag['id']; ?>
                    <tr class="border-b last:border-b-0 align-top">
                        <td class="px-4 py-2.5">
                            <span class="inline-block text-xs bg-brand-cream2 border border-brand-sand
                                         text-brand-inksoft rounded-full px-2.5 py-0.5">
                                <?= View::e((string) $tag['name']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-brand-inksoft hidden sm:table-cell">
                            <?= View::e((string) $tag['description']) ?>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <?php if ((int) $tag['contact_count'] > 0) : ?>
                                <a href="/contacts?tag=<?= View::e(rawurlencode((string) $tag['name'])) ?>"
                                   class="text-brand-red hover:underline"><?= (int) $tag['contact_count'] ?></a>
                            <?php else : ?>
                                <span class="text-brand-muted">0</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <button type="button" class="text-xs text-brand-inksoft underline hover:text-brand-red"
                                    data-edit-toggle="tag-edit-<?= $tagId ?>">Edit</button>
                        </td>
                    </tr>
                    <tr id="tag-edit-<?= $tagId ?>" hidden class="border-b last:border-b-0 bg-brand-cream">
                        <td colspan="4" class="px-4 py-3">
                            <form method="post" action="/tags/<?= $tagId ?>"
                                  class="flex flex-wrap items-end gap-3">
                                <?= Csrf::field() ?>
                                <label class="text-xs text-brand-muted">
                                    Name
                                    <input type="text" name="name" required
                                           value="<?= View::e((string) $tag['name']) ?>"
                                           class="block mt-1 border border-brand-sand rounded-lg px-3 py-1.5
                                                  text-sm bg-white w-44">
                                </label>
                                <label class="text-xs text-brand-muted flex-1 min-w-48">
                                    Description
                                    <input type="text" name="description" maxlength="255"
                                           value="<?= View::e((string) $tag['description']) ?>"
                                           placeholder="What this group is for&hellip;"
                                           class="block mt-1 border border-brand-sand rounded-lg px-3 py-1.5
                                                  text-sm bg-white w-full">
                                </label>
                                <button type="submit"
                                        class="text-sm bg-brand-red hover:bg-brand-reddark text-white
                                               px-3 py-1.5 rounded">
                                    Save
                                </button>
                            </form>
                            <?php
                            $confirmDelete = 'return confirm(\'Delete this tag?\n'
                                . 'It is removed from ' . (int) $tag['contact_count']
                                . ' contact(s); the contacts themselves are untouched.\');';
                            ?>
                            <form method="post" action="/tags/<?= $tagId ?>/delete" class="mt-2"
                                  onsubmit="<?= View::e($confirmDelete) ?>">
                                <?= Csrf::field() ?>
                                <button type="submit" class="text-xs text-brand-muted underline hover:text-brand-red">
                                    Delete tag
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<script>
    document.querySelectorAll('[data-edit-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var row = document.getElementById(button.getAttribute('data-edit-toggle'));

            if (row) {
                row.hidden = !row.hidden;
            }
        });
    });
</script>

<?php include __DIR__ . '/../layout/footer.php';
