<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\SlackColdAlertService;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Database\DatabaseTestCase;

final class SlackColdAlertServiceTest extends DatabaseTestCase
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
            slackUserMap: ['U_DANA' => 2],
            slackDebriefUsers: ['U_DANA'],
        );
    }

    private function insertContact(string $name, ?string $next, ?string $last = null): int
    {
        $stmt = self::db()->pdo()->prepare(
            'INSERT INTO contacts (name, relationship_status, next_touch_date, last_touch_date,
                created_by, updated_by)
             VALUES (?, ?, ?, ?, 1, 1)'
        );
        $stmt->execute([$name, 'Conversation Started', $next, $last]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    public function testAlertsOnlyNewlyColdContactsAndDedupes(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        // Crossed the 30-day line within the last week: cold today, not a week ago.
        $this->insertContact('Newly Cold Nancy', $today->modify('-33 days')->format('Y-m-d'));
        // Cold for months: already cold a week ago, must not be re-announced.
        $this->insertContact('Long Cold Larry', $today->modify('-90 days')->format('Y-m-d'));
        // Not cold at all.
        $this->insertContact('Warm Wendy', $today->format('Y-m-d'));

        $slack = new RecordingSlackClient($this->config());
        $service = new SlackColdAlertService($this->config(), self::db(), $slack);

        $outcome = $service->run(force: true);

        self::assertStringContainsString('sent 1', $outcome);
        self::assertStringContainsString('newly cold 1', $outcome);
        self::assertCount(1, $slack->messages);

        $json = json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Newly Cold Nancy', $json);
        self::assertStringNotContainsString('Long Cold Larry', $json);
        self::assertStringNotContainsString('Warm Wendy', $json);
        self::assertStringContainsString('1 older ones are still cold', $json);

        // A forced run is a manual test: it must NOT claim the day's dedupe
        // slot, or testing would silence the real Monday send.
        self::assertSame(
            0,
            (int) self::db()->pdo()->query('SELECT COUNT(*) FROM slack_notice_log')->fetchColumn(),
        );

        // Claim the slot the way a scheduled send would, then rerun: the
        // Monday gate or the dedupe keeps it quiet whatever weekday the
        // suite runs on.
        self::db()->pdo()
            ->prepare('INSERT INTO slack_notice_log (kind, notice_date, slack_user) VALUES (?, ?, ?)')
            ->execute([SlackColdAlertService::KIND, $today->format('Y-m-d'), 'U_DANA']);
        $service->run();
        self::assertCount(1, $slack->messages);
    }

    public function testAlertsFollowTheOwnerLens(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        // Newly cold (31 days late), but owned by user 1 — not U_DANA's.
        $id = $this->insertContact('Colins Cold Carl', $today->modify('-31 days')->format('Y-m-d'));
        self::db()->pdo()->prepare('UPDATE contacts SET owner_id = 1 WHERE id = ?')->execute([$id]);

        $slack = new RecordingSlackClient($this->config());
        $outcome = (new SlackColdAlertService($this->config(), self::db(), $slack))->run(force: true);

        self::assertCount(0, $slack->messages, 'another person\'s cold contact is not my alert');
        self::assertStringContainsString('nothing newly cold (1 already cold)', $outcome);
    }

    public function testNothingNewlyColdSendsNothing(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        $this->insertContact('Long Cold Larry', $today->modify('-90 days')->format('Y-m-d'));

        $slack = new RecordingSlackClient($this->config());
        $outcome = (new SlackColdAlertService($this->config(), self::db(), $slack))->run(force: true);

        self::assertStringContainsString('nothing newly cold (1 already cold)', $outcome);
        self::assertCount(0, $slack->messages);
    }
}
