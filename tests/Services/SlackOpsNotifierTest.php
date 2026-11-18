<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\SlackOpsNotifier;
use App\Support\Config;
use App\Support\SlackClient;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SlackOpsNotifierTest extends TestCase
{
    private function config(array $opsUsers): Config
    {
        return new Config(
            appEnv: 'test',
            appTimezone: new DateTimeZone('America/New_York'),
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
            slackBotToken: 'xoxb-test',
            slackOpsUsers: $opsUsers,
        );
    }

    public function testDmsEveryOpsUser(): void
    {
        $slack = new RecordingSlackClient($this->config(['U_COLIN', 'U_OTHER']));
        $notifier = new SlackOpsNotifier($this->config(['U_COLIN', 'U_OTHER']), $slack);

        $outcome = $notifier->notify(':rocket: Rolo deployed at abc1234: tidy things.');

        self::assertSame('ops notice: sent 2', $outcome);
        self::assertCount(2, $slack->messages);
        self::assertSame('U_COLIN', $slack->messages[0]['channel']);
        self::assertSame('U_OTHER', $slack->messages[1]['channel']);
        self::assertStringContainsString('deployed at abc1234', $slack->messages[0]['text']);
    }

    public function testUnconfiguredListIsANoOp(): void
    {
        $slack = new RecordingSlackClient($this->config([]));
        $outcome = (new SlackOpsNotifier($this->config([]), $slack))->notify('anything');

        self::assertStringContainsString('not configured', $outcome);
        self::assertCount(0, $slack->messages);
    }

    public function testASlackFailureIsSwallowedAndCounted(): void
    {
        $config = $this->config(['U_BROKEN', 'U_COLIN']);
        $slack = new class ($config) extends SlackClient {
            /** @var list<array{channel: string, text: string}> */
            public array $messages = [];

            public function postMessage(string $channel, string $text, array $blocks = []): void
            {
                if ($channel === 'U_BROKEN') {
                    throw new \RuntimeException('Slack error: channel_not_found');
                }

                $this->messages[] = ['channel' => $channel, 'text' => $text];
            }
        };

        $outcome = (new SlackOpsNotifier($config, $slack))->notify('notice');

        self::assertSame('ops notice: sent 1, failed 1', $outcome);
        self::assertCount(1, $slack->messages);
        self::assertSame('U_COLIN', $slack->messages[0]['channel']);
    }
}
