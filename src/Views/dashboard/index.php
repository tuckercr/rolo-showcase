<?php

declare(strict_types=1);

use App\Support\View;

/**
 * @var string $today
 * @var list<array<string, mixed>> $overdue
 * @var list<array<string, mixed>> $thisWeek
 */

include __DIR__ . '/../layout/header.php';
?>

<h1 class="font-serif text-3xl text-brand-ink">Follow-ups</h1>
<p class="mt-1 text-sm text-brand-muted">
    Overdue and due within the next 7 days — your Monday review list.
</p>

<?php $sections = [['Overdue', $overdue, true], ['Due this week', $thisWeek, false]]; ?>
<?php foreach ($sections as [$heading, $rows, $isOverdue]) : ?>
    <section class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wide
                   <?= $isOverdue ? 'text-brand-red' : 'text-brand-inksoft' ?>">
            <?= View::e($heading) ?> (<?= count($rows) ?>)
        </h2>

        <?php if ($rows === []) : ?>
            <p class="mt-2 text-sm text-brand-muted">Nothing here. 🎉</p>
        <?php else : ?>
            <div class="mt-2 bg-white rounded-lg shadow overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-brand-muted border-b">
                        <tr>
                            <th class="px-4 py-2 font-medium">Contact</th>
                            <th class="px-4 py-2 font-medium">Organization</th>
                            <th class="px-4 py-2 font-medium">Stage</th>
                            <th class="px-4 py-2 font-medium">Next touch</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $contact) : ?>
                            <tr class="border-b last:border-0 hover:bg-brand-cream">
                                <td class="px-4 py-2">
                                    <a href="/contacts/<?= (int) $contact['id'] ?>"
                                       class="text-brand-red hover:underline font-medium">
                                        <?= View::e((string) $contact['name']) ?>
                                    </a>
                                </td>
                                <td class="px-4 py-2 text-brand-inksoft">
                                    <?= View::e((string) ($contact['organization_name'] ?? '—')) ?>
                                </td>
                                <td class="px-4 py-2 text-brand-inksoft">
                                    <?= View::e((string) $contact['relationship_status']) ?>
                                </td>
                                <?php $dateClass = $isOverdue ? 'text-brand-red font-medium' : 'text-brand-inksoft'; ?>
                                <td class="px-4 py-2 <?= $dateClass ?>">
                                    <?= View::e((string) $contact['next_touch_date']) ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>
<?php endforeach ?>

<?php include __DIR__ . '/../layout/footer.php';
