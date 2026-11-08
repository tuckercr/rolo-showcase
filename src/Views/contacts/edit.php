<?php

declare(strict_types=1);

use App\Support\View;

/**
 * @var array<string, mixed> $contact
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $stages
 * @var list<array<string, mixed>> $organizations
 * @var list<string> $relationshipTypes
 */

include __DIR__ . '/../layout/header.php';

$formAction = '/contacts/' . (int) $contact['id'];
$submitLabel = 'Save changes';
?>

<h1 class="font-serif text-3xl text-brand-ink mb-6">
    Edit <?= View::e((string) $contact['name']) ?>
</h1>

<?php include __DIR__ . '/_form.php'; ?>

<?php include __DIR__ . '/../layout/footer.php';
