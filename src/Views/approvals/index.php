<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var list<array<string, mixed>> $pending proposals, each with a 'view'
 *      card model from ProposalService::describe()
 * @var list<array<string, mixed>> $decided
 * @var string|null $applyError
 */

include __DIR__ . '/../layout/header.php';

$prettyPayload = static function (string $json): string {
    $decoded = json_decode($json, true);

    return is_array($decoded)
        ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : $json;
};
?>

<h1 class="font-serif text-3xl text-brand-ink">Approvals</h1>
<p class="mt-2 text-sm text-brand-muted max-w-2xl">
    Changes proposed by the AI agents. Nothing touches the CRM until you
    approve it here; approved changes are recorded under your name.
</p>

<?php if ($applyError !== null) : ?>
    <p class="mt-4 max-w-2xl text-sm text-brand-red bg-red-50 border border-red-200 rounded-lg px-4 py-2">
        Applying that proposal failed and it was marked rejected:
        <?= View::e($applyError) ?>
    </p>
<?php endif ?>

<section class="mt-6">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-inksoft">
        Pending (<?= count($pending) ?>)
    </h2>

    <?php if ($pending === []) : ?>
        <p class="mt-2 text-sm text-brand-muted">Nothing waiting for review.</p>
    <?php else : ?>
        <ul class="mt-2 space-y-4 max-w-3xl">
            <?php foreach ($pending as $proposal) : ?>
                <?php
                $view = $proposal['view'];
                $kind = (string) ($view['kind'] ?? 'unknown');
                $contact = $view['contact'] ?? null;
                ?>
                <li class="bg-white rounded-lg shadow px-5 py-4">
                    <p class="text-base font-medium text-brand-ink">
                        <?php if ($kind === 'unknown') : ?>
                            <?= View::e((string) $proposal['summary']) ?>
                        <?php else : ?>
                            <?= View::e((string) $view['title']) ?><?php if ($contact !== null) :
                                ?>:
                                <a href="/contacts/<?= (int) $contact['id'] ?>"
                                   class="text-brand-red hover:underline"><?= View::e((string) $contact['name']) ?></a>
                            <?php endif ?>
                        <?php endif ?>
                    </p>
                    <p class="mt-0.5 text-xs text-brand-muted">
                        Proposed by an AI agent (<?= View::e((string) $proposal['token_name']) ?> token)
                        &middot; <?= View::e((string) $proposal['created_at']) ?> UTC
                    </p>

                    <?php if (($view['rows'] ?? []) !== []) : ?>
                        <table class="mt-3 w-full text-sm">
                            <?php foreach ($view['rows'] as $row) : ?>
                                <tr class="border-t border-brand-cream2">
                                    <td class="py-1.5 pr-4 text-brand-muted w-36 align-top">
                                        <?= View::e((string) $row['label']) ?>
                                    </td>
                                    <?php if (($row['before'] ?? null) === null) : ?>
                                        <td class="py-1.5 text-brand-ink" colspan="3">
                                            <?= View::e((string) $row['after']) ?>
                                        </td>
                                    <?php elseif (!($row['changed'] ?? true)) : ?>
                                        <td class="py-1.5 text-brand-inksoft" colspan="3">
                                            <?= View::e((string) $row['after']) ?>
                                            <span class="ml-2 text-xs text-brand-muted">(no change)</span>
                                        </td>
                                    <?php else : ?>
                                        <td class="py-1.5 text-brand-muted line-through align-top">
                                            <?= View::e((string) $row['before']) ?>
                                        </td>
                                        <td class="py-1.5 px-2 text-brand-muted align-top w-6">&rarr;</td>
                                        <td class="py-1.5 font-medium text-brand-ink align-top">
                                            <?= View::e((string) $row['after']) ?>
                                        </td>
                                    <?php endif ?>
                                </tr>
                            <?php endforeach ?>
                        </table>
                    <?php endif ?>

                    <?php if ($kind === 'change_tags') : ?>
                        <p class="mt-3 text-sm">
                            <?php foreach (($view['tags_add'] ?? []) as $tag) : ?>
                                <span class="inline-block bg-green-50 text-green-800 border border-green-200
                                             rounded-full px-2.5 py-0.5 text-xs mr-1">
                                    + <?= View::e((string) $tag) ?></span>
                            <?php endforeach ?>
                            <?php foreach (($view['tags_remove'] ?? []) as $tag) : ?>
                                <span class="inline-block bg-red-50 text-brand-red border border-red-200
                                             rounded-full px-2.5 py-0.5 text-xs mr-1 line-through">
                                    <?= View::e((string) $tag) ?></span>
                            <?php endforeach ?>
                        </p>
                        <?php if (($view['tags_current'] ?? []) !== []) : ?>
                            <p class="mt-1.5 text-xs text-brand-muted">
                                Currently tagged: <?= View::e(implode(', ', $view['tags_current'])) ?>
                            </p>
                        <?php endif ?>
                    <?php endif ?>

                    <?php if ($kind === 'create_contact' && ($view['tags_add'] ?? []) !== []) : ?>
                        <p class="mt-2 text-sm">
                            <span class="text-brand-muted text-xs mr-1">Tags:</span>
                            <?php foreach ($view['tags_add'] as $tag) : ?>
                                <span class="inline-block bg-green-50 text-green-800 border border-green-200
                                             rounded-full px-2.5 py-0.5 text-xs mr-1">
                                    + <?= View::e((string) $tag) ?></span>
                            <?php endforeach ?>
                        </p>
                    <?php endif ?>

                    <?php if ($kind === 'log_activity') : ?>
                        <div class="mt-3 border border-brand-sand rounded-lg px-3.5 py-2.5 text-sm">
                            <span class="inline-block bg-brand-cream2 text-brand-inksoft rounded-full
                                         px-2.5 py-0.5 text-xs">
                                <?= View::e((string) $view['activity']['type_label']) ?></span>
                            <span class="ml-1.5 text-xs text-brand-muted">
                                <?= View::e((string) $view['activity']['date']) ?></span>
                            <p class="mt-1.5 text-brand-ink">
                                <?= View::e((string) $view['activity']['summary']) ?>
                            </p>
                            <?php
                            $fuDate = $view['activity']['follow_up_date'] ?? null;
                            $fuNote = $view['activity']['follow_up_note'] ?? null;
                            ?>
                            <?php if ($fuDate !== null || $fuNote !== null) : ?>
                                <p class="mt-1 text-xs text-brand-red">
                                    Follow-up<?= $fuDate !== null ? ' by ' . View::e((string) $fuDate) : '' ?>
                                    <?php if ($fuNote !== null) : ?>
                                        <span class="text-brand-inksoft">
                                            &middot; <?= View::e((string) $fuNote) ?>
                                        </span>
                                    <?php endif ?>
                                </p>
                            <?php endif ?>
                        </div>
                    <?php endif ?>

                    <?php if (($view['consequence'] ?? null) !== null) : ?>
                        <p class="mt-2.5 text-xs <?=
                            ($view['consequence_level'] ?? 'info') === 'warning'
                                ? 'text-amber-800'
                                : 'text-brand-muted'
                        ?>">
                            <?= View::e((string) $view['consequence']) ?>
                        </p>
                    <?php endif ?>

                    <?php if ($view['also_pending'] ?? false) : ?>
                        <p class="mt-1.5 text-xs text-amber-800">
                            Heads up: other pending proposals also target this contact.
                            Review them together; the one approved last wins on any shared field.
                        </p>
                    <?php endif ?>

                    <div class="mt-3.5 flex items-center gap-2">
                        <form method="post" action="/proposals/<?= (int) $proposal['id'] ?>/approve">
                            <?= Csrf::field() ?>
                            <button type="submit"
                                    class="text-sm bg-brand-red hover:bg-brand-reddark text-white
                                           rounded-lg px-4 py-1.5">Approve</button>
                        </form>
                        <form method="post" action="/proposals/<?= (int) $proposal['id'] ?>/reject">
                            <?= Csrf::field() ?>
                            <button type="submit"
                                    class="text-sm text-brand-inksoft border border-brand-sand
                                           hover:bg-brand-cream2 rounded-lg px-4 py-1.5">
                                Reject
                            </button>
                        </form>
                        <details class="ml-auto text-right">
                            <summary class="text-xs text-brand-muted cursor-pointer hover:text-brand-red">
                                View raw request
                            </summary>
                            <pre class="mt-1 text-xs text-left bg-brand-cream rounded-lg p-3 overflow-x-auto"><?=
                                View::e($prettyPayload((string) $proposal['payload']))
                            ?></pre>
                        </details>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>

<?php if ($decided !== []) : ?>
    <section class="mt-10">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-muted">
            Recently decided
        </h2>
        <ul class="mt-2 space-y-1.5 max-w-3xl text-sm">
            <?php foreach ($decided as $proposal) : ?>
                <li class="flex items-baseline gap-2 text-brand-inksoft">
                    <?php $approved = (string) $proposal['status'] === 'approved'; ?>
                    <span class="text-xs font-medium <?= $approved ? 'text-green-700' : 'text-brand-red' ?>">
                        <?= $approved ? 'approved' : 'rejected' ?>
                    </span>
                    <span><?= View::e((string) $proposal['summary']) ?></span>
                    <span class="text-xs text-brand-muted">
                        by <?= View::e((string) ($proposal['reviewed_by_name'] ?? '?')) ?>
                        <?php if (($proposal['error'] ?? null) !== null) : ?>
                            &middot; <?= View::e((string) $proposal['error']) ?>
                        <?php endif ?>
                    </span>
                </li>
            <?php endforeach ?>
        </ul>
    </section>
<?php endif ?>

<?php include __DIR__ . '/../layout/footer.php';
