<?php

declare(strict_types=1);

namespace App\Support;

final class Session
{
    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start([
            'cookie_httponly' => true,
            // Local dev runs over plain http; everywhere else requires TLS.
            'cookie_secure' => !$config->isLocal(),
            'cookie_samesite' => 'Lax',
        ]);
    }
}
