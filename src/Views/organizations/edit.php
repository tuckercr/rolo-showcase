<?php

declare(strict_types=1);

use App\Support\View;

/**
 * @var array<string, mixed> $organization
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var array<string, string> $orgTypes
 */

include __DIR__ . '/../layout/header.php';

$formAction = '/organizations/' . (int) $organization['id'];
$submitLabel = 'Save changes';
?>

<h1 class="font-serif text-3xl text-brand-ink mb-6">
    Edit <?= View::e((string) $organization['name']) ?>
</h1>

<?php include __DIR__ . '/_form.php'; ?>

<?php include __DIR__ . '/../layout/footer.php';
