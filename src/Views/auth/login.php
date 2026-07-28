<?php

declare(strict_types=1);

use App\Support\Csrf;
use App\Support\View;

/**
 * @var bool $isLocal
 * @var bool $googleEnabled
 * @var list<array<string, mixed>> $devUsers
 * @var string|null $error
 */

$headTitle = 'Sign in — Rolo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../layout/head.php'; ?>
</head>
<body class="bg-brand-cream2 min-h-screen flex items-center justify-center font-sans">
    <main class="bg-white rounded-xl shadow-xl p-10 text-center w-full max-w-sm border border-brand-sand">
        <h1 class="font-serif text-3xl text-brand-ink">Rolo<span class="text-brand-red">.</span></h1>
        <p class="mt-2 text-sm text-brand-muted">Sign in to continue.</p>

        <?php if ($error !== null) : ?>
            <p class="mt-4 text-sm text-brand-red bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                <?= View::e($error) ?>
            </p>
        <?php endif ?>

        <?php if ($googleEnabled) : ?>
            <a href="/auth/google"
               class="mt-6 flex items-center justify-center gap-3 w-full bg-brand-red hover:bg-brand-reddark
                      text-white rounded-lg py-2.5 text-sm font-medium">
                <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#FFFFFF" d="M24 9.5c3.5 0 6.6 1.2 9 3.5l6.7-6.7C35.6 2.4 30.2 0 24 0
                        14.6 0 6.5 5.4 2.5 13.2l7.8 6.1C12.2 13.4 17.6 9.5 24 9.5z" opacity="0.9"/>
                    <path fill="#FFFFFF" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8
                        7.2l7.5 5.8c4.4-4.1 7.1-10.1 7.1-17.5z" opacity="0.75"/>
                    <path fill="#FFFFFF" d="M10.3 28.7a14.4 14.4 0 0 1 0-9.4l-7.8-6.1a24 24 0 0 0 0
                        21.6l7.8-6.1z" opacity="0.6"/>
                    <path fill="#FFFFFF" d="M24 48c6.2 0 11.4-2 15.4-5.5l-7.5-5.8c-2.1 1.4-4.8
                        2.3-7.9 2.3-6.4 0-11.8-3.9-13.7-9.3l-7.8 6.1C6.5 42.6 14.6 48 24 48z" opacity="0.85"/>
                </svg>
                Sign in with Google
            </a>
        <?php elseif (!$isLocal) : ?>
            <p class="mt-6 text-sm text-brand-muted">
                Google Sign-In isn&rsquo;t configured yet — check back soon.
            </p>
        <?php endif ?>

        <?php if ($isLocal) : ?>
            <p class="mt-6 text-xs uppercase tracking-wide text-brand-muted">
                Local development sign-in
            </p>
            <div class="mt-3 space-y-2">
                <?php foreach ($devUsers as $user) : ?>
                    <form method="post" action="/auth/dev-login">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="user_id" value="<?= View::e((string) $user['id']) ?>">
                        <button type="submit"
                                class="w-full bg-brand-ink hover:bg-brand-red text-white rounded-lg py-2 text-sm">
                            Continue as <?= View::e((string) $user['name']) ?>
                        </button>
                    </form>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </main>
</body>
</html>
