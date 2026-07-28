<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var array<string, string> $orgTypes
 */

include __DIR__ . '/../layout/header.php';

$formAction = '/organizations';
$submitLabel = 'Add organization';
?>

<h1 class="font-serif text-3xl text-brand-ink mb-6">Add organization</h1>

<?php include __DIR__ . '/_form.php'; ?>

<?php include __DIR__ . '/../layout/footer.php';
