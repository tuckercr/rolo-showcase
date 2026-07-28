<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var list<array<string, mixed>> $stages
 * @var array<string, array{rule_type: string, value_days: int}> $rulesByStatus
 * @var array<string, string> $ruleTypes
 * @var array<string, array{rule_type: string, value_days: int}> $defaults
 * @var array<int, string> $errors keyed by stage id
 * @var bool $saved
 * @var int $maxDays
 */

include __DIR__ . '/../layout/header.php';

$selectClass = 'border border-brand-sand rounded-lg px-2 py-1.5 text-sm bg-white w-full';
?>

<h1 class="font-serif text-3xl text-brand-ink">Settings</h1>

<section class="mt-6 max-w-3xl">
    <h2 class="text-lg font-semibold text-brand-ink">Follow-up cadence</h2>

    <div class="mt-3 bg-brand-cream2 border border-brand-sand rounded-lg px-4 py-3 text-sm text-brand-inksoft">
        <p>
            When an interaction is logged or a contact changes stage, Rolo schedules
            the contact&rsquo;s <strong>next touch</strong> using these rules — that&rsquo;s
            what feeds the dashboard and the morning summary email.
        </p>
        <p class="mt-2">Two things take precedence over the cadence:</p>
        <ul class="mt-1 ml-5 list-disc space-y-1">
            <li>
                A <strong>follow-up date</strong> set while logging an interaction always
                wins — if you say &ldquo;follow up by Saturday,&rdquo; Saturday it is.
            </li>
            <li>
                A per-contact <strong>cadence override</strong> (on the contact&rsquo;s edit
                page) replaces the day count below for that contact only.
            </li>
        </ul>
        <p class="mt-2 text-xs text-brand-muted">
            Changes here apply the next time a contact is touched or changes stage —
            already-scheduled dates aren&rsquo;t recalculated.
        </p>
    </div>

    <?php if ($saved) : ?>
        <p class="mt-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2">
            Saved.
        </p>
    <?php endif ?>

    <form method="post" action="/settings" class="mt-4 bg-white rounded-lg shadow overflow-x-auto">
        <?= Csrf::field() ?>
        <table class="w-full text-sm">
            <thead class="text-left text-brand-muted border-b">
                <tr>
                    <th class="px-4 py-2 font-medium">Stage</th>
                    <th class="px-4 py-2 font-medium">Reminder</th>
                    <th class="px-4 py-2 font-medium w-24">Days</th>
                    <th class="px-4 py-2 font-medium">Default</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stages as $stage) : ?>
                    <?php
                    $stageId = (int) $stage['id'];
                    $stageName = (string) $stage['name'];
                    $rule = $rulesByStatus[$stageName] ?? null;
                    $default = $defaults[$stageName] ?? null;
                    $defaultLabel = $default === null
                        ? 'No reminder'
                        : $default['value_days'] . 'd — ' . ($ruleTypes[$default['rule_type']] ?? '');
                    ?>
                    <tr class="border-b last:border-0 align-top">
                        <td class="px-4 py-3 font-medium text-brand-ink whitespace-nowrap">
                            <?= View::e($stageName) ?>
                        </td>
                        <td class="px-4 py-3">
                            <select name="rule_type[<?= $stageId ?>]" class="<?= $selectClass ?>">
                                <option value="none" <?= $rule === null ? 'selected' : '' ?>>
                                    No reminder
                                </option>
                                <?php foreach ($ruleTypes as $typeValue => $typeLabel) : ?>
                                    <option value="<?= View::e($typeValue) ?>"
                                        <?= ($rule['rule_type'] ?? '') === $typeValue ? 'selected' : '' ?>>
                                        <?= View::e($typeLabel) ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                            <?php if (isset($errors[$stageId])) : ?>
                                <p class="mt-1 text-xs text-brand-red"><?= View::e($errors[$stageId]) ?></p>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-3">
                            <input type="number" name="value_days[<?= $stageId ?>]"
                                   min="1" max="<?= $maxDays ?>"
                                   value="<?= $rule === null ? '' : (int) $rule['value_days'] ?>"
                                   class="border border-brand-sand rounded-lg px-2 py-1.5 text-sm w-20">
                        </td>
                        <td class="px-4 py-3 text-xs text-brand-muted">
                            <?= View::e($defaultLabel) ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        <div class="px-4 py-4 border-t">
            <button type="submit"
                    class="bg-brand-red hover:bg-brand-reddark text-white rounded-lg px-5 py-2
                           text-sm font-medium">
                Save cadence rules
            </button>
        </div>
    </form>

    <form method="post" action="/settings/reset" class="mt-4"
          onsubmit="return confirm('Replace all cadence rules with the original defaults?');">
        <?= Csrf::field() ?>
        <button type="submit"
                class="text-sm text-brand-inksoft border border-brand-sand hover:bg-brand-cream2
                       rounded-lg px-4 py-2">
            Reset to defaults
        </button>
        <span class="ml-2 text-xs text-brand-muted">
            Restores the original cadence for every stage (shown in the Default column).
        </span>
    </form>
</section>

<?php include __DIR__ . '/../layout/footer.php';
