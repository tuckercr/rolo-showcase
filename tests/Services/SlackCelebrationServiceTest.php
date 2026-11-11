<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\SlackCelebrationService;
use App\Support\Config;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SlackCelebrationServiceTest extends TestCase
{
    private function config(): Config
    {
        return new Config(
            appEnv: 'test',
            appTimezone: new DateTimeZone('America/New_York'),
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
            appUrl: 'https://crm.example.com',
            slackBotToken: 'xoxb-test',
            slackUserMap: ['U_DANA' => 2, 'U_COLIN' => 1],
        );
    }

    public function testCelebratesReachingActiveClientWithEveryone(): void
    {
        $slack = new RecordingSlackClient($this->config());
        $service = new SlackCelebrationService($this->config(), $slack);

        $service->maybeCelebrate('Proposal / Pitch', 'Active client', 7, 'Sarah Chen', 'Harbor Diagnostics');

        self::assertCount(2, $slack->messages);
        self::assertStringContainsString(
            'Sarah Chen (Harbor Diagnostics) is now an Active client',
            $slack->messages[0]['text'],
        );
        self::assertStringContainsString(
            'https://crm.example.com/contacts/7',
            json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public function testStaysQuietForOtherTransitions(): void
    {
        $slack = new RecordingSlackClient($this->config());
        $service = new SlackCelebrationService($this->config(), $slack);

        // Not reaching the stage, already there, leaving it, unconfigured.
        $service->maybeCelebrate('Researching', 'Proposal / Pitch', 7, 'Sarah Chen', null);
        $service->maybeCelebrate('Active client', 'Active client', 7, 'Sarah Chen', null);
        $service->maybeCelebrate('Active client', 'Dormant', 7, 'Sarah Chen', null);

        self::assertCount(0, $slack->messages);

        $bare = new Config(
            appEnv: 'test',
            appTimezone: new DateTimeZone('America/New_York'),
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
        );
        $quiet = new RecordingSlackClient($bare);
        (new SlackCelebrationService($bare, $quiet))
            ->maybeCelebrate('Researching', 'Active client', 7, 'Sarah Chen', null);

        self::assertCount(0, $quiet->messages);
    }
}
