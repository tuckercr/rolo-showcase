<?php

declare(strict_types=1);

namespace App\Support;

final class Url
{
    /**
     * True when the value is a well-formed absolute http(s) URL.
     *
     * The scheme allowlist is the point: FILTER_VALIDATE_URL alone accepts
     * javascript: and data: URLs (e.g. "javascript://host/%0aalert(1)"),
     * and the contact/organization views render these fields as clickable
     * hrefs — accepting any other scheme is stored XSS waiting for a click.
     */
    public static function isHttp(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }
}
