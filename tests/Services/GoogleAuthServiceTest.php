<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\GoogleAuthService;
use App\Support\Config;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GoogleAuthServiceTest extends TestCase
{
    private const CLIENT_ID = 'test-client.apps.googleusercontent.com';

    /**
     * @return array<string, mixed>
     */
    private function validClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'exp' => 2_000_000_000,
            'sub' => '110169484474386276334',
            'email' => 'colin@example.com',
            'email_verified' => true,
            'name' => 'Colin Tucker',
        ];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function jwtWithPayload(array $claims): string
    {
        $encode = static fn(array $part): string => rtrim(
            strtr(base64_encode((string) json_encode($part)), '+/', '-_'),
            '=',
        );

        return $encode(['alg' => 'RS256']) . '.' . $encode($claims) . '.fakesignature';
    }

    public function testDecodesClaimsFromIdToken(): void
    {
        $claims = GoogleAuthService::decodeIdTokenClaims($this->jwtWithPayload($this->validClaims()));

        $this->assertSame('colin@example.com', $claims['email']);
        $this->assertSame('110169484474386276334', $claims['sub']);
    }

    public function testRejectsMalformedToken(): void
    {
        $this->expectException(RuntimeException::class);

        GoogleAuthService::decodeIdTokenClaims('not-a-jwt');
    }

    public function testValidClaimsPass(): void
    {
        GoogleAuthService::validateClaims($this->validClaims(), self::CLIENT_ID, 1_900_000_000);

        $this->addToAssertionCount(1);
    }

    public function testRejectsWrongIssuer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('issuer');

        $claims = ['iss' => 'https://evil.example.com'] + $this->validClaims();
        GoogleAuthService::validateClaims($claims, self::CLIENT_ID, 1_900_000_000);
    }

    public function testRejectsWrongAudience(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audience');

        $claims = ['aud' => 'someone-else.apps.googleusercontent.com'] + $this->validClaims();
        GoogleAuthService::validateClaims($claims, self::CLIENT_ID, 1_900_000_000);
    }

    public function testRejectsExpiredToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');

        GoogleAuthService::validateClaims($this->validClaims(), self::CLIENT_ID, 2_000_000_001);
    }

    public function testRejectsUnverifiedEmail(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not verified');

        $claims = ['email_verified' => false] + $this->validClaims();
        GoogleAuthService::validateClaims($claims, self::CLIENT_ID, 1_900_000_000);
    }

    public function testAllowlistIsCaseInsensitiveAndExact(): void
    {
        $config = new Config(
            appEnv: 'production',
            appTimezone: new DateTimeZone('America/New_York'),
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
            allowedEmails: ['colin@example.com', 'jessica@example.com'],
        );
        $service = new GoogleAuthService($config);

        $this->assertTrue($service->isAllowedEmail('Colin@Example.com'));
        $this->assertFalse($service->isAllowedEmail('attacker@gmail.com'));
        $this->assertFalse($service->isAllowedEmail('colin@example.com.evil.com'));
    }

    public function testEmailListParsedFromEnv(): void
    {
        $config = Config::fromEnv([
            'APP_ENV' => 'production',
            'APP_TIMEZONE' => 'America/New_York',
            'DB_HOST' => '',
            'DB_NAME' => '',
            'DB_USER' => '',
            'DB_PASSWORD' => '',
            'OAUTH_ALLOWED_EMAILS' => 'A@example.com, b@example.com ,, c@example.com',
        ]);

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $config->allowedEmails);
    }
}
