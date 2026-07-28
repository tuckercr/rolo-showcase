<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use RuntimeException;

/**
 * Google Sign-In (OAuth 2.0 authorization-code flow with OpenID Connect).
 *
 * The ID token is obtained directly from Google's token endpoint over TLS,
 * so per Google's docs signature verification is not required — but the
 * claims (issuer, audience, expiry, verified email) still are, and that
 * validation lives in pure static methods so it's unit-tested.
 */
final class GoogleAuthService
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const VALID_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(private readonly Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config->googleClientId !== ''
            && $this->config->googleClientSecret !== ''
            && $this->config->googleRedirectUri !== '';
    }

    public function authUrl(string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->config->googleClientId,
            'redirect_uri' => $this->config->googleRedirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Exchange the authorization code and return the verified identity.
     *
     * @return array{sub: string, email: string, name: string}
     */
    public function authenticate(string $code): array
    {
        $claims = self::decodeIdTokenClaims($this->fetchIdToken($code));

        self::validateClaims($claims, $this->config->googleClientId, time());

        return [
            'sub' => (string) $claims['sub'],
            'email' => strtolower((string) $claims['email']),
            'name' => (string) ($claims['name'] ?? $claims['email']),
        ];
    }

    public function isAllowedEmail(string $email): bool
    {
        return in_array(strtolower($email), $this->config->allowedEmails, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeIdTokenClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed ID token.');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($payload === false) {
            throw new RuntimeException('ID token payload is not valid base64url.');
        }

        $claims = json_decode($payload, true);

        if (!is_array($claims)) {
            throw new RuntimeException('ID token payload is not valid JSON.');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function validateClaims(array $claims, string $clientId, int $now): void
    {
        if (!in_array($claims['iss'] ?? '', self::VALID_ISSUERS, true)) {
            throw new RuntimeException('ID token issuer is not Google.');
        }

        if (($claims['aud'] ?? '') !== $clientId) {
            throw new RuntimeException('ID token audience does not match our client ID.');
        }

        if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= $now) {
            throw new RuntimeException('ID token is expired.');
        }

        if (($claims['email_verified'] ?? false) !== true) {
            throw new RuntimeException('Google account email is not verified.');
        }

        if (!is_string($claims['sub'] ?? null) || ($claims['sub'] ?? '') === '') {
            throw new RuntimeException('ID token is missing the subject claim.');
        }

        if (!is_string($claims['email'] ?? null) || ($claims['email'] ?? '') === '') {
            throw new RuntimeException('ID token is missing the email claim.');
        }
    }

    private function fetchIdToken(string $code): string
    {
        $curl = curl_init(self::TOKEN_ENDPOINT);

        if ($curl === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => $this->config->googleClientId,
                'client_secret' => $this->config->googleClientSecret,
                'redirect_uri' => $this->config->googleRedirectUri,
                'grant_type' => 'authorization_code',
            ]),
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($response) || $status !== 200) {
            throw new RuntimeException(sprintf('Google token exchange failed (HTTP %d).', $status));
        }

        $body = json_decode($response, true);

        if (!is_array($body) || !is_string($body['id_token'] ?? null)) {
            throw new RuntimeException('Google token response did not include an ID token.');
        }

        return $body['id_token'];
    }
}
