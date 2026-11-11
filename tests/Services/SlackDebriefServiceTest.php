<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\SlackDebriefService;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Database\DatabaseTestCase;

final class SlackDebriefServiceTest extends DatabaseTestCase
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
            slackSigningSecret: 'secret',
            slackUserMap: ['U_DANA' => 2, 'U_COLIN' => 1],
            slackDebriefUsers: ['U_DANA'],
        );
    }

    private function insertContact(string $name, ?string $next, string $stage = 'Conversation Started'): int
    {
        $stmt = self::db()->pdo()->prepare(
            'INSERT INTO contacts (name, relationship_status, next_touch_date, created_by, updated_by)
             VALUES (?, ?, ?, 1, 1)'
        );
        $stmt->execute([$name, $stage, $next]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    public function testSendsOnePromptPerDueContactAndDedupes(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        $this->insertContact('Maria Santos', $today->modify('-3 days')->format('Y-m-d'));
        $this->insertContact('Robert Ellis', $today->format('Y-m-d'));
        $this->insertContact('Future Fred', $today->modify('+5 days')->format('Y-m-d'));

        $slack = new RecordingSlackClient($this->config());
        $service = new SlackDebriefService($this->config(), self::db(), $slack);

        $outcome = $service->run(force: true);

        self::assertStringContainsString('sent 1', $outcome);
        self::assertCount(1, $slack->messages);
        self::assertSame('U_DANA', $slack->messages[0]['channel']);
        self::assertSame('You had 2 follow-ups due today.', $slack->messages[0]['text']);

        $json = json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Maria Santos', $json);
        self::assertStringContainsString('Robert Ellis', $json);
        self::assertStringNotContainsString('Future Fred', $json, 'due this week is not due today');
        self::assertStringContainsString('"a":"log"', stripslashes($json));
        self::assertStringContainsString('"a":"snooze"', stripslashes($json));

        // Forced runs are manual tests: they must not claim the day's slot
        // (bitten 2026-11-10, a morning test silenced the evening send)...
        $logged = self::db()->pdo()->query('SELECT COUNT(*) FROM slack_debrief_log')->fetchColumn();
        self::assertSame(0, (int) $logged, 'a forced run must not record the dedupe row');

        // ...and always send again when asked again.
        $service->run(force: true);
        self::assertCount(2, $slack->messages);

        // The scheduled path dedupes: with today's row present, a non-forced
        // run sends nothing (whether stopped by the dedupe row or, outside
        // the send window, by the time gate).
        self::db()->pdo()
            ->prepare('INSERT INTO slack_debrief_log (debrief_date, slack_user) VALUES (?, ?)')
            ->execute([$today->format('Y-m-d'), 'U_DANA']);
        $second = $service->run(force: false);
        $okOffHours = str_contains($second, 'already sent 1') || str_contains($second, 'not a weekday after');
        self::assertTrue($okOffHours, 'second run must dedupe or be outside the send window: ' . $second);
        self::assertCount(2, $slack->messages);
    }

    public function testRunForUserAlwaysSendsAndNeverDedupes(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        $this->insertContact('Maria Santos', $today->format('Y-m-d'));

        $slack = new RecordingSlackClient($this->config());
        $service = new SlackDebriefService($this->config(), self::db(), $slack);

        $service->runForUser('U_COLIN');
        $service->runForUser('U_COLIN');

        self::assertCount(2, $slack->messages, 'an explicit ask always answers');
        self::assertSame('U_COLIN', $slack->messages[0]['channel']);

        $logged = self::db()->pdo()
            ->query('SELECT COUNT(*) FROM slack_debrief_log')
            ->fetchColumn();
        self::assertSame(0, (int) $logged, 'on-demand sends must not silence the evening debrief');
    }

    public function testQuietDayStillChecksIn(): void
    {
        $slack = new RecordingSlackClient($this->config());
        $service = new SlackDebriefService($this->config(), self::db(), $slack);

        $service->run(force: true);

        self::assertCount(1, $slack->messages);
        self::assertStringContainsString('Nothing was due today', $slack->messages[0]['text']);
    }

    public function testSkipsEntirelyWhenUnconfigured(): void
    {
        $bare = new Config(
            appEnv: 'test',
            appTimezone: new DateTimeZone('America/New_York'),
            dbHost: '',
            dbName: '',
            dbUser: '',
            dbPassword: '',
        );

        $slack = new RecordingSlackClient($bare);
        $outcome = (new SlackDebriefService($bare, self::db(), $slack))->run(force: true);

        self::assertStringContainsString('not configured', $outcome);
        self::assertCount(0, $slack->messages);
    }

    public function testIsSendTimeGatesOnWeekdayEvenings(): void
    {
        $tz = new DateTimeZone('America/New_York');

        self::assertTrue(SlackDebriefService::isSendTime(new DateTimeImmutable('2026-11-06 17:00', $tz)));
        self::assertFalse(SlackDebriefService::isSendTime(new DateTimeImmutable('2026-11-06 16:59', $tz)));
        self::assertFalse(SlackDebriefService::isSendTime(new DateTimeImmutable('2026-11-07 18:00', $tz)), 'Sat');
        self::assertFalse(SlackDebriefService::isSendTime(new DateTimeImmutable('2026-11-08 18:00', $tz)), 'Sun');
    }
}
