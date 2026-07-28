<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var string $pageTitle
 * @var array<string, mixed>|null $currentUser
 */

$headTitle = $pageTitle . ' — Rolo';
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
        <a href="/" class="text-sm text-brand-inksoft hover:text-brand-red">Dashboard</a>
        <a href="/contacts" class="text-sm text-brand-inksoft hover:text-brand-red">Contacts</a>
        <a href="/organizations" class="text-sm text-brand-inksoft hover:text-brand-red">Organizations</a>
        <a href="/settings" class="text-sm text-brand-inksoft hover:text-brand-red">Settings</a>
        <a href="/contacts/new"
           class="text-sm bg-brand-red hover:bg-brand-reddark text-white px-3 py-1 rounded">
            + Add contact
        </a>
        <div class="ml-auto flex items-center gap-3 text-sm text-brand-muted">
            <?php if ($currentUser !== null) : ?>
                <span><?= View::e((string) $currentUser['name']) ?></span>
                <form method="post" action="/logout">
                    <?= Csrf::field() ?>
                    <button type="submit" class="hover:text-brand-red underline">Sign out</button>
                </form>
            <?php endif ?>
        </div>
    </div>
</nav>
<main class="max-w-5xl mx-auto px-4 py-8">
