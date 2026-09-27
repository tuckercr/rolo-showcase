<?php

declare(strict_types=1);

use App\Support\View;

/**
 * @var list<array<string, mixed>> $attachments
 * @var string $query
 * @var int $totalBytes
 * @var int|null $freeBytes server free space, null if unknown
 */

include __DIR__ . '/../layout/header.php';

$fmtBytes = static function (int $bytes): string {
    if ($bytes === 0) {
        return '0 KB';
    }
    if ($bytes >= 1_073_741_824) {
        return number_format($bytes / 1_073_741_824, 1) . ' GB';
    }
    if ($bytes >= 1_048_576) {
        return number_format($bytes / 1_048_576, 1) . ' MB';
    }

    // Sub-KB files still show as 1 KB rather than a confusing "0 KB".
    return number_format(max(1, (int) round($bytes / 1024))) . ' KB';
};
$lowSpace = $freeBytes !== null && $freeBytes < 2_147_483_648; // < 2 GB
?>

<div class="flex items-center justify-between">
    <h1 class="font-serif text-3xl text-brand-ink">Files</h1>
</div>

<p class="mt-2 text-sm <?= $lowSpace ? 'text-brand-red font-medium' : 'text-brand-muted' ?>">
    <?= count($attachments) ?> file<?= count($attachments) === 1 ? '' : 's' ?>
    &middot; <?= $fmtBytes($totalBytes) ?> used
    <?php if ($freeBytes !== null) : ?>
        &middot; <?= $fmtBytes($freeBytes) ?> free on the server
        <?= $lowSpace ? ' — running low!' : '' ?>
    <?php endif ?>
</p>

<form method="get" action="/attachments" class="mt-4 flex flex-wrap gap-2">
    <input type="search" name="q" value="<?= View::e($query) ?>"
           placeholder="Search file or contact name…"
           class="border border-brand-sand rounded-lg px-3 py-2 text-sm w-64">
    <button type="submit"
            class="bg-brand-ink hover:bg-brand-red text-white rounded-lg px-4 py-2 text-sm">
        Search
    </button>
</form>

<?php if ($attachments === []) : ?>
    <p class="mt-8 text-sm text-brand-muted">
        No files<?= $query !== '' ? ' match that search' : ' yet' ?> — attach documents
        when logging an interaction on a contact&rsquo;s page.
    </p>
<?php else : ?>
    <div class="mt-4 bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-brand-muted border-b">
                <tr>
                    <th class="px-4 py-2 font-medium">File</th>
                    <th class="px-4 py-2 font-medium">Contact</th>
                    <th class="px-4 py-2 font-medium">Interaction</th>
                    <th class="px-4 py-2 font-medium">Size</th>
                    <th class="px-4 py-2 font-medium">Uploaded</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attachments as $att) : ?>
                    <tr class="border-b last:border-0 hover:bg-brand-cream">
                        <td class="px-4 py-2">
                            <a href="/attachments/<?= (int) $att['id'] ?>"
                               class="text-brand-red hover:underline font-medium">
                                &#128206; <?= View::e((string) $att['original_name']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-2">
                            <a href="/contacts/<?= (int) $att['contact_id'] ?>"
                               class="text-brand-red hover:underline">
                                <?= View::e((string) $att['contact_name']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-2 text-brand-inksoft">
                            <?= View::e(ucfirst(str_replace('_', ' ', (string) $att['activity_type']))) ?>
                            &middot; <?= View::e((string) $att['activity_date']) ?>
                        </td>
                        <td class="px-4 py-2 text-brand-inksoft whitespace-nowrap">
                            <?= $fmtBytes((int) $att['size_bytes']) ?>
                        </td>
                        <td class="px-4 py-2 text-brand-muted">
                            by <?= View::e((string) $att['uploaded_by_name']) ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?php include __DIR__ . '/../layout/footer.php';
