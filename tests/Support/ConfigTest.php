<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function validEnv(): array
    {
        return [
            'APP_ENV' => 'local',
            'APP_TIMEZONE' => 'America/New_York',
            'DB_HOST' => 'db.example.com',
            'DB_NAME' => 'rolo',
            'DB_USER' => 'rolo_user',
            'DB_PASSWORD' => 'secret',
        ];
    }

    public function testBuildsFromValidEnvironment(): void
    {
        $config = Config::fromEnv($this->validEnv());

        $this->assertSame('local', $config->appEnv);
        $this->assertSame('America/New_York', $config->appTimezone->getName());
        $this->assertSame('db.example.com', $config->dbHost);
        $this->assertTrue($config->isLocal());
    }

    public function testIsLocalIsFalseInProduction(): void
    {
        $config = Config::fromEnv(['APP_ENV' => 'production'] + $this->validEnv());

        $this->assertFalse($config->isLocal());
    }

    public function testDbCredentialsMayBeEmptyButMustExist(): void
    {
        $config = Config::fromEnv(['DB_PASSWORD' => ''] + $this->validEnv());

        $this->assertSame('', $config->dbPassword);
    }

    public function testRejectsMissingRequiredKey(): void
    {
        $env = $this->validEnv();
        unset($env['APP_ENV']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_ENV');

        Config::fromEnv($env);
    }

    public function testRejectsEmptyAppEnv(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::fromEnv(['APP_ENV' => ''] + $this->validEnv());
    }

    public function testRejectsInvalidTimezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_TIMEZONE');

        Config::fromEnv(['APP_TIMEZONE' => 'Not/AZone'] + $this->validEnv());
    }
}
