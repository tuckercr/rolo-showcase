<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\ProposalService;
use App\Services\SlackApprovalNotifier;
use App\Support\Config;
use App\Support\SlackClient;
use DateTimeZone;
use RuntimeException;
use Tests\Database\DatabaseTestCase;

final class SlackApprovalNotifierTest extends DatabaseTestCase
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
            slackUserMap: ['U_COLIN' => 1, 'U_DANA' => 2],
        );
    }

    /**
     * A Slack client whose DMs to the given channels always fail — a
     * deactivated account or a bad member id in SLACK_USER_MAP.
     */
    private function slackFailingFor(string ...$channels): RecordingSlackClient
    {
        return new class ($this->config(), $channels) extends RecordingSlackClient {
            /** @param list<string> $failing */
            public function __construct(Config $config, private readonly array $failing)
            {
                parent::__construct($config);
            }

            public function postMessage(string $channel, string $text, array $blocks = []): void
            {
                if (in_array($channel, $this->failing, true)) {
                    throw new RuntimeException('msg_blocked');
                }

                parent::postMessage($channel, $text, $blocks);
            }
        };
    }

    public function testAnnouncesEachPendingProposalToEveryMappedUserOnce(): void
    {
        $service = new ProposalService($this->config(), self::db());
        $service->queue('mcp-test', 'create_organization', ['name' => 'Acme Co']);
        $service->queue('mcp-test', 'create_organization', ['name' => 'Globex']);

        $slack = new RecordingSlackClient($this->config());
        $outcome = (new SlackApprovalNotifier($this->config(), self::db(), $slack))->run();

        self::assertStringContainsString('announced 2', $outcome);
        self::assertCount(4, $slack->messages); // 2 proposals x 2 users

        // The second tick re-announces nothing.
        $second = (new SlackApprovalNotifier($this->config(), self::db(), $slack))->run();
        self::assertStringContainsString('announced 0', $second);
        self::assertCount(4, $slack->messages);
    }

    public function testOneBrokenDmDoesNotBlockOrRespamTheOthers(): void
    {
        $service = new ProposalService($this->config(), self::db());
        $service->queue('mcp-test', 'create_organization', ['name' => 'Acme Co']);
        $service->queue('mcp-test', 'create_organization', ['name' => 'Globex']);

        $slack = $this->slackFailingFor('U_DANA');
        $outcome = (new SlackApprovalNotifier($this->config(), self::db(), $slack))->run();

        // Both proposals still reached the working account, the outcome
        // names the failures, and the stamp is set so the next tick does
        // NOT re-DM the working account forever.
        self::assertStringContainsString('announced 2', $outcome);
        self::assertStringContainsString('2 DM(s) failed', $outcome);
        self::assertCount(2, $slack->messages);
        self::assertSame(['U_COLIN', 'U_COLIN'], array_column($slack->messages, 'channel'));

        $second = (new SlackApprovalNotifier($this->config(), self::db(), $slack))->run();
        self::assertStringContainsString('announced 0', $second);
        self::assertCount(2, $slack->messages);
    }

    public function testAFullOutageLeavesTheProposalForTheNextTick(): void
    {
        $service = new ProposalService($this->config(), self::db());
        $service->queue('mcp-test', 'create_organization', ['name' => 'Acme Co']);

        $down = $this->slackFailingFor('U_COLIN', 'U_DANA');
        $outcome = (new SlackApprovalNotifier($this->config(), self::db(), $down))->run();

        self::assertStringContainsString('announced 0', $outcome);

        // Slack is back: the proposal is still unstamped and announces now.
        $slack = new RecordingSlackClient($this->config());
        $retry = (new SlackApprovalNotifier($this->config(), self::db(), $slack))->run();

        self::assertStringContainsString('announced 1', $retry);
        self::assertCount(2, $slack->messages);
    }

    public function testProposalTextIsMrkdwnEscaped(): void
    {
        $service = new ProposalService($this->config(), self::db());
        $service->queue('mcp-test', 'create_organization', [
            'name' => '<https://evil.example|Evil> & Sons',
        ]);

        $slack = new RecordingSlackClient($this->config());
        (new SlackApprovalNotifier($this->config(), self::db(), $slack))->run();

        $body = (string) json_encode(
            $slack->messages[0]['blocks'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        self::assertStringContainsString('&lt;https://evil.example|Evil&gt; &amp; Sons', $body);
        self::assertStringNotContainsString('<https://evil.example', $body);
    }
}
