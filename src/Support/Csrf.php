<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSRF token helper — every state-changing form includes Csrf::field() and
 * the handling controller verifies before acting (CLAUDE.md security rules).
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        return sprintf('<input type="hidden" name="_token" value="%s">', View::e(self::token()));
    }

    public static function verify(mixed $token): bool
    {
        return is_string($token)
            && isset($_SESSION[self::SESSION_KEY])
            && is_string($_SESSION[self::SESSION_KEY])
            && hash_equals($_SESSION[self::SESSION_KEY], $token);
    }
}
