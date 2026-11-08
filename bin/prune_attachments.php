<?php

/**
 * Prune orphaned attachment files: php bin/prune_attachments.php [--dry-run]
 *
 * An orphan is a file in storage/attachments that no activity_attachments
 * row references — possible only if PHP died between storing a file and
 * inserting its row. Files younger than an hour are never touched (an
 * upload could be mid-flight). Also reports the reverse problem: DB rows
 * whose file is missing from disk (downloads for those 404).
 */

declare(strict_types=1);

use App\Models\AttachmentModel;
use App\Services\AttachmentService;
use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$config = require dirname(__DIR__) . '/config/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);

$database = new Database($config);
$service = AttachmentService::forApp();

$known = array_flip((new AttachmentModel($database))->allStoredNames());
$dir = $service->dir();

$files = is_dir($dir) ? (glob($dir . '/*') ?: []) : [];
$removed = 0;
$removedBytes = 0;
$skippedYoung = 0;

foreach ($files as $path) {
    $name = basename($path);

    if (isset($known[$name])) {
        continue;
    }

    if (filemtime($path) > time() - 3600) {
        $skippedYoung++;
        continue;
    }

    $removedBytes += (int) filesize($path);
    $removed++;

    echo ($dryRun ? '[dry-run] would remove: ' : 'removed: ') . $name . "\n";

    if (!$dryRun) {
        unlink($path);
    }
}

$missing = 0;

foreach (array_keys($known) as $storedName) {
    if (!is_file($service->path((string) $storedName))) {
        echo "WARNING: DB row exists but file is missing: {$storedName}\n";
        $missing++;
    }
}

printf(
    "%d orphan(s) %s (%.1f KB), %d recent file(s) skipped, %d row(s) missing their file.\n",
    $removed,
    $dryRun ? 'found' : 'removed',
    $removedBytes / 1024,
    $skippedYoung,
    $missing,
);
