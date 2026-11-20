<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\SlackController;
use App\Models\UserModel;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Database;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The controller's pure helpers: slash-command parsing and the debrief
 * message surgery that Skip performs. These run without Slack or a
 * database (Database connects lazily, so a dummy Config is enough to
 * construct the controller).
 */
final class SlackControllerTest extends TestCase
{
    private function controller(string $appUrl = 'https://crm.example.com'): SlackController
    {
        $config = new Config(
            appEnv: 'test',
            appTimezone: new DateTimeZone('America/New_York'),
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
            appUrl: $appUrl,
        );
        $db = new Database($config);

        return new SlackController($config, $db, new Auth(new UserModel($db)));
    }

    public function testSnoozeArgsPlainNameDefaultsToThreeDays(): void
    {
        self::assertSame(
            ['query' => 'acme', 'days' => 3],
            SlackController::parseSnoozeArgs('acme'),
        );
    }

    public function testSnoozeArgsTrailingNumberIsTheDayCount(): void
    {
        self::assertSame(
            ['query' => 'acme', 'days' => 14],
            SlackController::parseSnoozeArgs('acme 14'),
        );
        // Documented behavior for unquoted names ending in digits: the
        // number is read as days. Quoting is the escape hatch.
        self::assertSame(
            ['query' => 'studio', 'days' => 54],
            SlackController::parseSnoozeArgs('studio 54'),
        );
    }

    public function testSnoozeArgsQuotedNameIsTakenVerbatim(): void
    {
        self::assertSame(
            ['query' => 'studio 54', 'days' => 3],
            SlackController::parseSnoozeArgs('"studio 54"'),
        );
        self::assertSame(
            ['query' => 'studio 54', 'days' => 7],
            SlackController::parseSnoozeArgs('"studio 54" 7'),
        );
    }

    public function testSnoozeArgsRejectEmptyInput(): void
    {
        self::assertNull(SlackController::parseSnoozeArgs(''));
        self::assertNull(SlackController::parseSnoozeArgs('   '));
        self::assertNull(SlackController::parseSnoozeArgs('""'));
    }

    public function testSeenContactIdsHarvestsTapCarriedAndOnScreenIds(): void
    {
        // The tap: contact 5, with 9 skipped earlier (carried as x).
        $action = ['a' => 'skip', 'c' => 5, 'x' => [9]];

        // On screen: contact 7's action row (whose Skip also carries x:[9],
        // which must not duplicate) and a non-actions block to ignore.
        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Did you reach *Pat*?']],
            ['type' => 'actions', 'elements' => [
                ['action_id' => 'log_7', 'value' => '{"a":"log","c":7}'],
                ['action_id' => 'skip_7', 'value' => '{"a":"skip","c":7,"x":[9]}'],
                ['action_id' => 'broken', 'value' => 'not json'],
            ]],
        ];

        self::assertSame([5, 9, 7], SlackController::seenContactIds($action, $blocks));
    }

    public function testUpdateMoreFooterDecrementsTheCount(): void
    {
        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'hello']],
            ['type' => 'context', 'elements' => [[
                'type' => 'mrkdwn',
                'text' => 'Plus 4 more on the <https://crm.example.com|dashboard>.',
            ]]],
        ];

        $updated = $this->controller()->updateMoreFooter($blocks, 3);

        self::assertSame(
            'Plus 3 more on the <https://crm.example.com|dashboard>.',
            $updated[1]['elements'][0]['text'],
        );
    }

    public function testUpdateMoreFooterDisappearsAtZero(): void
    {
        $blocks = [
            ['type' => 'context', 'elements' => [[
                'type' => 'mrkdwn',
                'text' => 'Plus 1 more on the <https://crm.example.com|dashboard>.',
            ]]],
            ['type' => 'context', 'elements' => [[
                'type' => 'mrkdwn',
                'text' => 'Log opens a short form.',
            ]]],
        ];

        $updated = $this->controller()->updateMoreFooter($blocks, 0);

        // The counter line is gone; the unrelated context block survives.
        self::assertCount(1, $updated);
        self::assertSame('Log opens a short form.', $updated[0]['elements'][0]['text']);
    }

    public function testUpdateMoreFooterLeavesMessagesWithoutACounterAlone(): void
    {
        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'hello']],
        ];

        self::assertSame($blocks, $this->controller()->updateMoreFooter($blocks, 2));
    }
}
