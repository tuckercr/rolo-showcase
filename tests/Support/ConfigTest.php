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

    public function testParsesSlackUserMapAndDebriefUsers(): void
    {
        $config = Config::fromEnv([
            'SLACK_USER_MAP' => ' U0123ABCD:1 , U0456EFGH:2 ',
            'SLACK_DEBRIEF_USERS' => 'U0456EFGH, ,U0123ABCD',
        ] + $this->validEnv());

        $this->assertSame(['U0123ABCD' => 1, 'U0456EFGH' => 2], $config->slackUserMap);
        $this->assertSame(['U0456EFGH', 'U0123ABCD'], $config->slackDebriefUsers);
    }

    public function testMalformedSlackUserMapEntriesAreDroppedNotFatal(): void
    {
        $config = Config::fromEnv([
            'SLACK_USER_MAP' => 'U0123ABCD:1,garbage,U0456EFGH:x,:3,U0789IJKL:2:extra',
        ] + $this->validEnv());

        $this->assertSame(['U0123ABCD' => 1], $config->slackUserMap);
    }

    public function testSlackConfigDefaultsToDisabled(): void
    {
        $config = Config::fromEnv($this->validEnv());

        $this->assertSame('', $config->slackBotToken);
        $this->assertSame('', $config->slackSigningSecret);
        $this->assertSame([], $config->slackUserMap);
        $this->assertSame([], $config->slackDebriefUsers);
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
