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
 * @var array<int|string, mixed>|null $postedTypes raw POST values when re-rendering after a
 *      validation error, null when rendering saved state; same for the next two
 * @var array<int|string, mixed>|null $postedDays
 * @var list<int>|null $postedHiddenIds
 * @var array<string, mixed>|null $calendarConnection this user's row, or null
 * @var bool $calendarEnabled Google OAuth client + APP_URL configured
 * @var string $calendarFlash ?calendar= outcome from the connect flow
 * @var string|null $mcpUrl the signed-in user's personal MCP connector link
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
            the contact&rsquo;s <strong>next follow-up</strong> using these rules. That&rsquo;s
            what feeds the dashboard and the morning summary email.
        </p>
        <p class="mt-2">Two things take precedence over the cadence:</p>
        <ul class="mt-1 ml-5 list-disc space-y-1">
            <li>
                A <strong>follow-up date</strong> set while logging an interaction always
                wins. If you say &ldquo;follow up by Saturday,&rdquo; Saturday it is.
            </li>
            <li>
                A per-contact <strong>cadence override</strong> (on the contact&rsquo;s edit
                page) replaces the day count below for that contact only.
            </li>
        </ul>
        <p class="mt-2 text-xs text-brand-muted">
            Changes here apply the next time an interaction is logged or a stage changes;
            already-scheduled dates aren&rsquo;t recalculated.
        </p>
        <p class="mt-2 text-xs text-brand-muted">
            Hidden stages never appear on the dashboard or in the morning email.
            Contacts in them keep their history and stay on the Contacts list;
            they just stop generating follow-up reminders. Leads and
            Closed / Not a Fit are hidden by default. Careful with Dormant:
            hiding it silences the six-month check-ins too.
        </p>
    </div>

    <?php if ($saved) : ?>
        <p class="mt-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2">
            Saved.
        </p>
    <?php endif ?>

    <form method="post" action="/settings" class="mt-4 bg-white rounded-lg shadow overflow-x-auto relative">
        <?= Csrf::field() ?>
        <table class="w-full text-sm">
            <thead class="text-left text-brand-muted border-b">
                <tr>
                    <th class="px-4 py-2 font-medium">Stage</th>
                    <th class="px-4 py-2 font-medium">Reminder</th>
                    <th class="px-4 py-2 font-medium w-24">Days</th>
                    <th class="px-4 py-2 font-medium">Default</th>
                    <th class="px-4 py-2 font-medium whitespace-nowrap">
                        Hide from dashboard &amp; email
                    </th>
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
                        : $default['value_days'] . 'd: ' . ($ruleTypes[$default['rule_type']] ?? '');
                    // After a validation error the form re-renders from what
                    // was posted, not from the database, so no edit is lost.
                    $selectedType = $postedTypes !== null
                        ? (string) ($postedTypes[$stageId] ?? 'none')
                        : ($rule === null ? 'none' : (string) $rule['rule_type']);
                    $daysValue = $postedDays !== null
                        ? (string) ($postedDays[$stageId] ?? '')
                        : ($rule === null ? '' : (string) (int) $rule['value_days']);
                    $isHidden = $postedHiddenIds !== null
                        ? in_array($stageId, $postedHiddenIds, true)
                        : (int) ($stage['hidden_from_follow_ups'] ?? 0) === 1;
                    ?>
                    <tr class="border-b last:border-0 align-top">
                        <td class="px-4 py-3 font-medium text-brand-ink whitespace-nowrap">
                            <?= View::e($stageName) ?>
                        </td>
                        <td class="px-4 py-3">
                            <select name="rule_type[<?= $stageId ?>]" class="<?= $selectClass ?>">
                                <option value="none" <?= $selectedType === 'none' ? 'selected' : '' ?>>
                                    No reminder
                                </option>
                                <?php foreach ($ruleTypes as $typeValue => $typeLabel) : ?>
                                    <option value="<?= View::e($typeValue) ?>"
                                        <?= $selectedType === $typeValue ? 'selected' : '' ?>>
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
                                   min="0" max="<?= $maxDays ?>"
                                   value="<?= View::e($daysValue) ?>"
                                   aria-label="Reminder days for <?= View::e($stageName) ?>"
                                   class="border border-brand-sand rounded-lg px-2 py-1.5 text-sm w-20">
                        </td>
                        <td class="px-4 py-3 text-xs text-brand-muted">
                            <?= View::e($defaultLabel) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <input type="checkbox" name="stage_hidden[<?= $stageId ?>]" value="1"
                                   class="rounded"
                                   aria-label="Hide <?= View::e($stageName) ?> from dashboard and email"
                                   <?= $isHidden ? 'checked' : '' ?>>
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
          onsubmit="return confirm(
              'Reset cadence rules AND stage visibility to the original defaults?'
          );">
        <?= Csrf::field() ?>
        <button type="submit"
                class="text-sm text-brand-inksoft border border-brand-sand hover:bg-brand-cream2
                       rounded-lg px-4 py-2">
            Reset to defaults
        </button>
        <span class="ml-2 text-xs text-brand-muted">
            Restores the original cadence for every stage (shown in the Default column)
            and the default visibility: only Leads and Closed / Not a Fit hidden.
        </span>
    </form>
</section>

<section class="mt-10 max-w-3xl">
    <h2 class="text-lg font-semibold text-brand-ink">Google Calendar</h2>

    <div class="mt-3 bg-brand-cream2 border border-brand-sand rounded-lg px-4 py-3 text-sm text-brand-inksoft">
        <p>
            Connect your calendar and the evening Slack debrief adds a short
            <strong>&ldquo;from your calendar today&rdquo;</strong> section: meetings whose
            attendees match a contact&rsquo;s email, each with a Log button that
            opens the usual form prefilled with the meeting title.
        </p>
        <p class="mt-2 text-xs text-brand-muted">
            Access is read-only and per person: connecting your calendar never shows
            it to anyone else, and Rolo never writes to it. Nothing is logged
            automatically; every entry still goes through the Log form.
        </p>
    </div>

    <?php
    $calendarFlashes = [
        'connected' => [true, 'Calendar connected.'],
        'disconnected' => [true, 'Calendar disconnected.'],
        'failed' => [false, 'Google didn\'t complete the connection. Please try again.'],
        'no_scope' => [false, 'The calendar permission wasn\'t granted. Try again and leave the '
            . 'calendar checkbox ticked on Google\'s consent screen.'],
        'not_configured' => [false, 'Google OAuth isn\'t configured on this install.'],
    ];
    ?>
    <?php if (isset($calendarFlashes[$calendarFlash])) : ?>
        <?php [$ok, $message] = $calendarFlashes[$calendarFlash]; ?>
        <p class="mt-4 text-sm rounded-lg px-4 py-2 border <?= $ok
            ? 'text-green-700 bg-green-50 border-green-200'
            : 'text-brand-red bg-red-50 border-red-200' ?>">
            <?= View::e($message) ?>
        </p>
    <?php endif ?>

    <?php if ($calendarConnection !== null) : ?>
        <div class="mt-4 flex items-center gap-4 bg-white rounded-lg shadow px-4 py-3">
            <p class="text-sm text-brand-ink flex-1">
                Connected as <strong><?= View::e((string) $calendarConnection['google_email']) ?></strong>
                <span class="text-brand-muted">
                    since <?= View::e(substr((string) $calendarConnection['connected_at'], 0, 10)) ?>
                </span>
            </p>
            <form method="post" action="/settings/calendar/disconnect">
                <?= Csrf::field() ?>
                <button type="submit"
                        class="text-sm text-brand-inksoft border border-brand-sand hover:bg-brand-cream2
                               rounded-lg px-4 py-2">
                    Disconnect
                </button>
            </form>
        </div>
    <?php elseif ($calendarEnabled) : ?>
        <a href="/settings/calendar/connect"
           class="mt-4 inline-block bg-brand-red hover:bg-brand-reddark text-white rounded-lg px-5 py-2
                  text-sm font-medium">
            Connect Google Calendar
        </a>
    <?php else : ?>
        <p class="mt-4 text-sm text-brand-muted">
            Google OAuth isn&rsquo;t configured on this install, so the calendar
            can&rsquo;t be connected yet.
        </p>
    <?php endif ?>
</section>

<section class="mt-10 max-w-3xl">
    <h2 class="text-lg font-semibold text-brand-ink">Claude connection</h2>

    <div class="mt-3 bg-brand-cream2 border border-brand-sand rounded-lg px-4 py-3 text-sm text-brand-inksoft">
        <p>
            Connect Rolo to Claude and your AI agent can search contacts, look up
            history, and propose changes. Nothing it proposes touches the CRM until
            a person approves it on the <a href="/proposals"
            class="text-brand-red hover:underline">Approvals</a> page.
        </p>
        <p class="mt-2 text-xs text-brand-muted">
            The link below is personal: requests through it are recorded under your
            name, and contacts your agent proposes are assigned to you.
        </p>
    </div>

    <?php if ($mcpUrl !== null) : ?>
        <?php $mcpMasked = substr($mcpUrl, 0, (int) strrpos($mcpUrl, '/') + 1)
            . "\u{2026}" . substr($mcpUrl, -4); ?>
        <ol class="mt-4 text-sm text-brand-inksoft list-decimal ml-5 space-y-1">
            <li>Copy your personal connector link below.</li>
            <li>
                On claude.ai, open <strong>Settings &rsaquo; Connectors</strong>
                (or go to claude.ai/settings/connectors).
            </li>
            <li>
                Click <strong>Add custom connector</strong>, name it
                <strong>Rolo</strong>, and paste the link as the URL.
            </li>
            <li>
                In a chat, enable Rolo under the tools menu and try
                &ldquo;search my Rolo contacts&rdquo;.
            </li>
        </ol>

        <div class="mt-4 flex items-center gap-2 bg-white rounded-lg shadow px-4 py-3">
            <code id="mcp-url" data-url="<?= View::e($mcpUrl) ?>"
                  class="flex-1 text-xs text-brand-ink break-all"></code>
            <button type="button" id="mcp-toggle"
                    class="text-xs text-brand-inksoft border border-brand-sand hover:bg-brand-cream2
                           rounded px-2.5 py-1">Show</button>
            <button type="button" id="mcp-copy"
                    class="text-xs text-white bg-brand-red hover:bg-brand-reddark
                           rounded px-2.5 py-1">Copy</button>
        </div>
        <p class="mt-2 text-xs text-brand-muted">
            Treat this link like a password: anyone who has it can read the CRM and
            queue proposals in your name. Also works in Claude Code:
            <code>claude mcp add --transport http rolo &lt;link&gt;</code>.
        </p>

        <script>
            (function () {
                var box = document.getElementById('mcp-url');
                var toggle = document.getElementById('mcp-toggle');
                var copy = document.getElementById('mcp-copy');
                var full = box.getAttribute('data-url');
                var masked = <?= (string) json_encode($mcpMasked) ?>;
                var shown = false;

                box.textContent = masked;
                toggle.addEventListener('click', function () {
                    shown = !shown;
                    box.textContent = shown ? full : masked;
                    toggle.textContent = shown ? 'Hide' : 'Show';
                });
                function copied() {
                    copy.textContent = 'Copied!';
                    setTimeout(function () { copy.textContent = 'Copy'; }, 2000);
                }

                // Clipboard API first; selection + execCommand when it is
                // unavailable or denied (older browsers, embedded webviews).
                function fallbackCopy() {
                    var area = document.createElement('textarea');

                    area.value = full;
                    document.body.appendChild(area);
                    area.select();
                    document.execCommand('copy');
                    document.body.removeChild(area);
                    copied();
                }

                copy.addEventListener('click', function () {
                    if (!navigator.clipboard || !navigator.clipboard.writeText) {
                        fallbackCopy();
                        return;
                    }

                    navigator.clipboard.writeText(full).then(copied, fallbackCopy);
                });
            })();
        </script>
    <?php else : ?>
        <p class="mt-4 text-sm text-brand-muted">
            No personal agent token is set up for your account yet. Add an entry for
            your email to <code>API_USER_TOKENS</code> in the server&rsquo;s
            <code>.env</code> (see <code>docs/API.md</code>) and your connector link
            will appear here.
        </p>
    <?php endif ?>
</section>

<?php include __DIR__ . '/../layout/footer.php';
