<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var string $pageTitle
 * @var array<string, mixed>|null $currentUser
 */

$headTitle = $pageTitle . ' · Rolo';

// The desktop nav is one fixed-width row with no room to spare (it wrapped
// once Tasks joined it), so it shows the signed-in person's first name and
// nothing in it may wrap. The full name stays on hover and in the mobile menu.
// It needs the full 1024px container to fit, hence the lg breakpoint:
// narrower screens (tablets included) get the menu button instead.
$navUserName = (string) ($currentUser['name'] ?? '');
$navFirstName = explode(' ', trim($navUserName))[0];

$navLinks = [
    '/' => 'Dashboard',
    '/tasks' => 'Tasks',
    '/contacts' => 'Contacts',
    '/organizations' => 'Organizations',
    '/tags' => 'Tags',
    '/attachments' => 'Files',
    '/settings' => 'Settings',
    '/help' => 'Help',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/head.php'; ?>
</head>
<body class="bg-brand-cream min-h-screen font-sans text-brand-ink">
<nav class="bg-brand-cream2 border-b border-brand-sand">
    <div class="max-w-5xl mx-auto px-4 py-3 flex items-center gap-6">
        <a href="/" class="font-serif text-xl text-brand-ink">
            Rolo<span class="text-brand-red">.</span>
        </a>

        <div class="hidden lg:flex items-center gap-4 flex-1 whitespace-nowrap">
            <?php foreach ($navLinks as $href => $label) : ?>
                <a href="<?= View::e($href) ?>"
                   class="text-sm text-brand-inksoft hover:text-brand-red"><?= View::e($label) ?></a>
            <?php endforeach ?>
            <?php if (($pendingProposals ?? 0) > 0) : ?>
                <a href="/proposals"
                   class="text-sm text-brand-red font-medium hover:underline">
                    Approvals (<?= (int) $pendingProposals ?>)
                </a>
            <?php endif ?>
            <a href="/contacts/new"
               class="text-sm bg-brand-red hover:bg-brand-reddark text-white px-3 py-1 rounded whitespace-nowrap">
                + Add contact
            </a>
            <div class="ml-auto flex items-center gap-3 text-sm text-brand-muted">
                <?php if ($currentUser !== null) : ?>
                    <span title="<?= View::e($navUserName) ?>"><?= View::e($navFirstName) ?></span>
                    <form method="post" action="/logout">
                        <?= Csrf::field() ?>
                        <button type="submit" class="hover:text-brand-red underline">Sign out</button>
                    </form>
                <?php endif ?>
            </div>
        </div>

        <div class="lg:hidden ml-auto flex items-center gap-4">
            <?php if (($pendingProposals ?? 0) > 0) : ?>
                <a href="/proposals"
                   class="text-sm text-brand-red font-medium hover:underline whitespace-nowrap">
                    Approvals (<?= (int) $pendingProposals ?>)
                </a>
            <?php endif ?>
            <button type="button" id="nav-toggle" aria-expanded="false" aria-controls="nav-menu"
                    aria-label="Menu" class="p-1 -mr-1 text-brand-inksoft hover:text-brand-red">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
        </div>
    </div>

    <div id="nav-menu" hidden class="lg:hidden border-t border-brand-sand px-4 pt-3 pb-4">
        <div class="flex flex-col gap-3">
            <?php foreach ($navLinks as $href => $label) : ?>
                <a href="<?= View::e($href) ?>"
                   class="text-sm text-brand-inksoft hover:text-brand-red py-0.5"><?= View::e($label) ?></a>
            <?php endforeach ?>
            <a href="/contacts/new"
               class="text-sm bg-brand-red hover:bg-brand-reddark text-white px-3 py-1.5 rounded self-start">
                + Add contact
            </a>
            <?php if ($currentUser !== null) : ?>
                <div class="flex items-center gap-3 text-sm text-brand-muted border-t border-brand-sand pt-3">
                    <span><?= View::e((string) $currentUser['name']) ?></span>
                    <form method="post" action="/logout">
                        <?= Csrf::field() ?>
                        <button type="submit" class="hover:text-brand-red underline">Sign out</button>
                    </form>
                </div>
            <?php endif ?>
        </div>
    </div>
</nav>
<script>
    (function () {
        var button = document.getElementById('nav-toggle');
        var menu = document.getElementById('nav-menu');

        if (!button || !menu) {
            return;
        }

        button.addEventListener('click', function () {
            var open = menu.hidden;
            menu.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    })();
</script>
<main class="max-w-5xl mx-auto px-4 py-8">
