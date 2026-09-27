<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Secure file storage for activity attachments.
 *
 * Security model:
 * - Files live OUTSIDE the web root (storage/attachments) so Apache can
 *   never serve them directly; every download goes through the auth-gated
 *   controller.
 * - On-disk names are random hex — the user-supplied filename is display
 *   metadata only, never a path. That kills traversal and filename tricks.
 * - Extension AND real content type (finfo on the bytes) must both be on
 *   the allowlist; no HTML/SVG/scripts, nothing executable.
 */
final class AttachmentService
{
    public const MAX_BYTES = 10_485_760; // 10 MB
    public const MAX_PER_ACTIVITY = 5;

    /** extension => acceptable finfo MIME types for that extension */
    private const ALLOWED = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'doc' => ['application/msword'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'xls' => ['application/vnd.ms-excel'],
        'csv' => ['text/csv', 'text/plain'],
        'txt' => ['text/plain'],
    ];

    public function __construct(private readonly string $storageDir)
    {
    }

    public static function forApp(): self
    {
        return new self(dirname(__DIR__, 2) . '/storage/attachments');
    }

    public function dir(): string
    {
        return $this->storageDir;
    }

    public static function allowedExtensionsLabel(): string
    {
        return implode(', ', array_keys(self::ALLOWED));
    }

    /**
     * Flatten PHP's multi-file input shape into one array per file,
     * skipping empty slots (an empty <input type=file> posts UPLOAD_ERR_NO_FILE).
     *
     * @param array<string, mixed> $files one $_FILES entry for a name="...[]" input
     * @return list<array{name: string, tmp_name: string, size: int, error: int}>
     */
    public static function normalizeMultiUpload(array $files): array
    {
        $out = [];
        $names = (array) ($files['name'] ?? []);

        foreach (array_keys($names) as $i) {
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $out[] = [
                'name' => (string) ($files['name'][$i] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'size' => (int) ($files['size'][$i] ?? 0),
                'error' => $error,
            ];
        }

        return $out;
    }

    /**
     * @throws InvalidArgumentException with a user-presentable message
     */
    public function validate(string $originalName, string $tmpPath, int $size, int $errorCode): void
    {
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(
                sprintf('"%s" failed to upload (code %d) — try again.', $originalName, $errorCode)
            );
        }

        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException(
                sprintf('"%s" is too large — the limit is 10 MB per file.', $originalName)
            );
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!array_key_exists($extension, self::ALLOWED)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" isn\'t an allowed file type (allowed: %s).',
                $originalName,
                self::allowedExtensionsLabel(),
            ));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $actualMime = (string) $finfo->file($tmpPath);

        if (!in_array($actualMime, self::ALLOWED[$extension], true)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" doesn\'t look like a real .%s file — upload refused.',
                $originalName,
                $extension,
            ));
        }
    }

    /**
     * Move a validated upload into storage under a random name.
     *
     * @return array{stored_name: string, original_name: string, mime_type: string, size_bytes: int}
     */
    public function store(string $originalName, string $tmpPath, int $size): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
            throw new RuntimeException('Could not create the attachment storage directory.');
        }

        $target = $this->storageDir . '/' . $storedName;

        // move_uploaded_file for real requests; rename() fallback keeps the
        // service unit-testable with plain temp files.
        $moved = is_uploaded_file($tmpPath)
            ? move_uploaded_file($tmpPath, $target)
            : rename($tmpPath, $target);

        if (!$moved) {
            throw new RuntimeException('Could not store the uploaded file.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return [
            'stored_name' => $storedName,
            'original_name' => $originalName,
            'mime_type' => (string) $finfo->file($target),
            'size_bytes' => $size,
        ];
    }

    /**
     * Absolute path for a stored file. basename() guard: stored names come
     * from our own DB, but never let a separator through regardless.
     */
    public function path(string $storedName): string
    {
        return $this->storageDir . '/' . basename($storedName);
    }

    public function delete(string $storedName): void
    {
        $path = $this->path($storedName);

        if (is_file($path)) {
            unlink($path);
        }
    }
}
