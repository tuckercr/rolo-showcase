<?php

/**
 * Signed public photo URLs, so Slack can render contact headshots.
 *
 * Slack's image proxy fetches URLs with no credentials, and the normal
 * photo route sits behind the login wall — so the bot hands out
 * /photo/{id}/{sig} links whose signature is an HMAC over the contact id
 * AND the stored filename, keyed by CRON_SECRET. Unguessable without the
 * key, each link exposes exactly one image, and because the stored name
 * changes on every upload the old link dies (and Slack's cache busts)
 * the moment the photo is replaced. No secret in the server's .env means
 * the whole feature quietly stays off.
 */

declare(strict_types=1);

namespace App\Support;

final class PhotoLink
{
    public static function sig(Config $config, int $contactId, string $photo): ?string
    {
        if ($config->cronSecret === '' || $photo === '') {
            return null;
        }

        return hash_hmac('sha256', sprintf('photo:%d:%s', $contactId, $photo), $config->cronSecret);
    }

    /**
     * @param array<string, mixed> $contact
     */
    public static function url(Config $config, array $contact): ?string
    {
        $sig = self::sig($config, (int) ($contact['id'] ?? 0), (string) ($contact['photo'] ?? ''));

        if ($sig === null || $config->appUrl === '') {
            return null;
        }

        return sprintf('%s/photo/%d/%s', $config->appUrl, (int) $contact['id'], $sig);
    }

    /**
     * A Slack section-accessory image block, or null when there is nothing
     * to show — callers can `?? $someOtherAccessory` their fallback.
     *
     * @param array<string, mixed> $contact
     * @return array<string, mixed>|null
     */
    public static function accessory(Config $config, array $contact): ?array
    {
        $url = self::url($config, $contact);

        if ($url === null) {
            return null;
        }

        return [
            'type' => 'image',
            'image_url' => $url,
            'alt_text' => (string) ($contact['name'] ?? 'contact photo'),
        ];
    }

    public static function verify(Config $config, int $contactId, string $photo, string $sig): bool
    {
        $expected = self::sig($config, $contactId, $photo);

        return $expected !== null && hash_equals($expected, $sig);
    }
}
