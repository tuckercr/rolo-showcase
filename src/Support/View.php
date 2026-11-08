<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal template renderer. Templates live in src/Views and are plain PHP
 * files that only display data — no business logic (see CLAUDE.md).
 */
final class View
{
    /**
     * @param array<string, mixed> $data variables made available to the template
     */
    public static function render(string $template, array $data = []): string
    {
        $path = dirname(__DIR__) . '/Views/' . $template . '.php';

        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('View template not found: %s', $template));
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $path;

        return (string) ob_get_clean();
    }

    /**
     * Escape dynamic output for HTML — every dynamic value in a template goes
     * through this (XSS rule in CLAUDE.md).
     */
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
