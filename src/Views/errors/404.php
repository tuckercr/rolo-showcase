<?php

declare(strict_types=1);

$headTitle = 'Not Found — Rolo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../layout/head.php'; ?>
</head>
<body class="bg-brand-cream min-h-screen flex items-center justify-center font-sans">
    <main class="text-center">
        <h1 class="font-serif text-6xl text-brand-sand">404</h1>
        <p class="mt-3 text-brand-muted">That page doesn&rsquo;t exist.</p>
        <a href="/" class="mt-6 inline-block text-sm text-brand-red hover:underline">
            Back to home
        </a>
    </main>
</body>
</html>
