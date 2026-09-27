<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable application configuration, built once in config/bootstrap.php
 * from the environment ($_ENV) and passed to whatever needs it — no
 * scattered getenv() calls anywhere else (see CLAUDE.md).
 */
final readonly class Config
{
    /**
     * @param list<string> $allowedEmails lowercase allowlist — Google
     *        authenticates, this list authorizes (docs/SCHEMA.md)
     * @param list<string> $summaryRecipients daily summary email recipients
     */
    public function __construct(
        public string $appEnv,
        public DateTimeZone $appTimezone,
        public string $dbHost,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public string $googleClientId = '',
        public string $googleClientSecret = '',
        public string $googleRedirectUri = '',
        public array $allowedEmails = [],
        public string $migrateToken = '',
        public string $appUrl = '',
        public string $cronSecret = '',
        public string $smtpHost = '',
        public int $smtpPort = 587,
        public string $smtpUser = '',
        public string $smtpPassword = '',
        public string $mailFrom = '',
        public array $summaryRecipients = [],
        public string $apiTokenRead = '',
        public string $apiTokenWrite = '',
    ) {
    }

    /**
     * @param array<string, mixed> $env typically $_ENV, already populated by phpdotenv
     */
    public static function fromEnv(array $env): self
    {
        $timezoneName = self::requireString($env, 'APP_TIMEZONE');

        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (\Exception) {
            throw new InvalidArgumentException(
                sprintf('APP_TIMEZONE "%s" is not a valid timezone identifier.', $timezoneName)
            );
        }

        return new self(
            appEnv: self::requireString($env, 'APP_ENV'),
            appTimezone: $timezone,
            dbHost: self::requireString($env, 'DB_HOST', allowEmpty: true),
            dbName: self::requireString($env, 'DB_NAME', allowEmpty: true),
            dbUser: self::requireString($env, 'DB_USER', allowEmpty: true),
            dbPassword: self::requireString($env, 'DB_PASSWORD', allowEmpty: true),
            googleClientId: self::optionalString($env, 'GOOGLE_CLIENT_ID'),
            googleClientSecret: self::optionalString($env, 'GOOGLE_CLIENT_SECRET'),
            googleRedirectUri: self::optionalString($env, 'GOOGLE_REDIRECT_URI'),
            allowedEmails: self::parseEmailList(self::optionalString($env, 'OAUTH_ALLOWED_EMAILS')),
            migrateToken: self::optionalString($env, 'MIGRATE_TOKEN'),
            appUrl: rtrim(self::optionalString($env, 'APP_URL'), '/'),
            cronSecret: self::optionalString($env, 'CRON_SECRET'),
            smtpHost: self::optionalString($env, 'SMTP_HOST'),
            smtpPort: (int) (self::optionalString($env, 'SMTP_PORT') ?: 587),
            smtpUser: self::optionalString($env, 'SMTP_USER'),
            smtpPassword: self::optionalString($env, 'SMTP_PASSWORD'),
            mailFrom: self::optionalString($env, 'MAIL_FROM'),
            summaryRecipients: self::parseEmailList(self::optionalString($env, 'SUMMARY_RECIPIENTS')),
            apiTokenRead: self::optionalString($env, 'API_TOKEN_READ'),
            apiTokenWrite: self::optionalString($env, 'API_TOKEN_WRITE'),
        );
    }

    public function isLocal(): bool
    {
        return $this->appEnv === 'local';
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function optionalString(array $env, string $key): string
    {
        $value = $env[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private static function parseEmailList(string $raw): array
    {
        $emails = array_map(
            static fn(string $email): string => strtolower(trim($email)),
            explode(',', $raw),
        );

        return array_values(array_filter($emails, static fn(string $e): bool => $e !== ''));
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function requireString(array $env, string $key, bool $allowEmpty = false): string
    {
        $value = $env[$key] ?? null;

        if (!is_string($value) || (!$allowEmpty && $value === '')) {
            throw new InvalidArgumentException(
                sprintf('Missing required environment variable %s — see .env.example.', $key)
            );
        }

        return $value;
    }
}
