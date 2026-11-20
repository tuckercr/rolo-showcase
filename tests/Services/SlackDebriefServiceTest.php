<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\CalendarSuggestionService;
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
        self::assertSame('You have 1 overdue follow-up and 1 due today.', $slack->messages[0]['text']);

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

    public function testDebriefFollowsEachPersonsOwnerLens(): void
    {
        $today = (new DateTimeImmutable('today', new DateTimeZone('America/New_York')))->format('Y-m-d');
        $set = self::db()->pdo()->prepare('UPDATE contacts SET owner_id = ? WHERE id = ?');
        $set->execute([2, $this->insertContact('Dana Owned', $today)]);
        $set->execute([1, $this->insertContact('Colin Owned', $today)]);
        $this->insertContact('Shared Sam', $today);

        $slack = new RecordingSlackClient($this->config());
        (new SlackDebriefService($this->config(), self::db(), $slack))->run(force: true);

        // U_DANA maps to user 2: her debrief is her contacts plus shared.
        $json = json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Dana Owned', $json);
        self::assertStringContainsString('Shared Sam', $json);
        self::assertStringNotContainsString('Colin Owned', $json);
        self::assertSame('You have 2 follow-ups due today.', $slack->messages[0]['text']);
    }

    public function testDebriefClosesWithTheTasksDueUnderTheSameLens(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        $this->insertContact('Maria Santos', $today->format('Y-m-d'));
        $this->insertTask('Dana overdue task', $today->modify('-2 days')->format('Y-m-d'), 2);
        $this->insertTask('Shared task today', $today->format('Y-m-d'), null);
        $this->insertTask('Colin task today', $today->format('Y-m-d'), 1);
        $this->insertTask('Dana task next week', $today->modify('+7 days')->format('Y-m-d'), 2);
        $this->insertTask('Dana undated task', null, 2);

        $slack = new RecordingSlackClient($this->config());
        (new SlackDebriefService($this->config(), self::db(), $slack))->run(force: true);

        // U_DANA maps to user 2.
        $json = stripslashes(json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('2 tasks due', $json);
        self::assertStringContainsString('Dana overdue task', $json);
        self::assertStringContainsString('Shared task today', $json);
        self::assertStringNotContainsString('Colin task today', $json, 'someone else\'s task');
        self::assertStringNotContainsString('next week', $json, 'not an end-of-day question');
        self::assertStringNotContainsString('undated', $json);
        self::assertStringContainsString('"a":"task_done"', $json);

        // The contact prompts still lead, and the headline still counts
        // follow-ups only.
        self::assertSame('You have 1 follow-up due today.', $slack->messages[0]['text']);
        self::assertLessThan(strpos($json, 'tasks due'), strpos($json, 'Maria Santos'));
    }

    public function testTasksAloneReplaceTheNothingDueCheer(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        $this->insertTask('Shared task today', $today->format('Y-m-d'), null);

        $slack = new RecordingSlackClient($this->config());
        (new SlackDebriefService($this->config(), self::db(), $slack))->run(force: true);

        self::assertSame(
            'No follow-ups were due today, and you have 1 task due.',
            $slack->messages[0]['text'],
        );
        $json = json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Shared task today', $json);
        self::assertStringNotContainsString(':tada:', $json, 'not a clean slate while a task is due');
    }

    public function testMoreThanFiveTasksAreSummarized(): void
    {
        $today = (new DateTimeImmutable('today', new DateTimeZone('America/New_York')))->format('Y-m-d');

        for ($i = 1; $i <= 7; $i++) {
            $this->insertTask('Task number ' . $i, $today, 2);
        }

        $slack = new RecordingSlackClient($this->config());
        (new SlackDebriefService($this->config(), self::db(), $slack))->run(force: true);

        $json = json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('7 tasks due', $json);
        self::assertStringContainsString('Task number 5', $json);
        self::assertStringNotContainsString('Task number 6', $json);
        self::assertStringContainsString('And 2 more tasks', $json);
    }

    private function insertTask(string $title, ?string $due, ?int $owner): void
    {
        self::db()->pdo()->prepare(
            'INSERT INTO tasks (title, due_date, owner_id, created_by, updated_by) VALUES (?, ?, ?, 1, 1)'
        )->execute([$title, $due, $owner]);
    }

    public function testGoingColdContactsLeaveTheDebrief(): void
    {
        // The dashboard and the morning email both move 30+-days-late
        // contacts from Overdue to Going cold; the debrief must quote the
        // same number (it said 31 where the dashboard said 28, 2026-11-12).
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        $this->insertContact('Mildly Late', $today->modify('-3 days')->format('Y-m-d'));
        $this->insertContact('Quietly Dying', $today->modify('-45 days')->format('Y-m-d'));

        $slack = new RecordingSlackClient($this->config());
        (new SlackDebriefService($this->config(), self::db(), $slack))->run(force: true);

        self::assertSame('You have 1 overdue follow-up.', $slack->messages[0]['text']);

        $json = json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Mildly Late', $json);
        self::assertStringNotContainsString('Quietly Dying', $json, 'going cold is the Monday alert, not the debrief');
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

    public function testCalendarSuggestionsGetPrefilledLogButtons(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        $this->insertContact('Maria Santos', $today->format('Y-m-d'));

        $stub = new class ($this->config(), self::db()) extends CalendarSuggestionService {
            /** @var list<int|null> */
            public array $userIds = [];

            public function suggestionsFor(?int $userId, string $day, bool $skipAlreadyLogged = true): array
            {
                $this->userIds[] = $userId;

                return [[
                    'contact' => ['id' => 7, 'name' => 'Pat Doyle', 'organization_name' => 'Larkspur'],
                    'title' => 'Larkspur brief review',
                    'time' => '2:00 PM',
                ]];
            }
        };

        $slack = new RecordingSlackClient($this->config());
        (new SlackDebriefService($this->config(), self::db(), $slack, $stub))->run(force: true);

        self::assertSame([2], $stub->userIds, 'U_DANA maps to rolo user 2');

        $json = stripslashes(json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('From your calendar today', $json);
        self::assertStringContainsString('You met *Pat Doyle* (Larkspur) at 2:00 PM: _Larkspur brief review_', $json);
        self::assertStringContainsString('"v":"cal"', $json);
        self::assertStringContainsString('"s":"Larkspur brief review"', $json, 'the modal prefill rides the button');
        self::assertStringContainsString('cal_log_7', $json, 'calendar ids never collide with prompt ids');
    }

    public function testQuietDayStillShowsCalendarSuggestions(): void
    {
        $stub = new class ($this->config(), self::db()) extends CalendarSuggestionService {
            public function suggestionsFor(?int $userId, string $day, bool $skipAlreadyLogged = true): array
            {
                return [[
                    'contact' => ['id' => 7, 'name' => 'Pat Doyle', 'organization_name' => ''],
                    'title' => '',
                    'time' => null,
                ]];
            }
        };

        $slack = new RecordingSlackClient($this->config());
        (new SlackDebriefService($this->config(), self::db(), $slack, $stub))->run(force: true);

        $json = stripslashes(json_encode($slack->messages[0]['blocks'], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('Nothing was due today', $json);
        self::assertStringContainsString('You met *Pat Doyle* today: _a meeting_', $json);
    }

    public function testCalendarFailureNeverStopsTheDebrief(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        $this->insertContact('Maria Santos', $today->format('Y-m-d'));

        $stub = new class ($this->config(), self::db()) extends CalendarSuggestionService {
            public function suggestionsFor(?int $userId, string $day, bool $skipAlreadyLogged = true): array
            {
                throw new \RuntimeException('Google is down');
            }
        };

        $slack = new RecordingSlackClient($this->config());
        $outcome = (new SlackDebriefService($this->config(), self::db(), $slack, $stub))->run(force: true);

        self::assertStringContainsString('sent 1', $outcome);
        self::assertStringContainsString('Maria Santos', json_encode($slack->messages[0]['blocks']));
    }

    /**
     * calendarBlocks is public because /rolo meetings renders the same rows;
     * this pins the shape both surfaces share: header, four capped rows with
     * prefilled Log buttons, and the "plus N more" footer.
     */
    public function testCalendarBlocksCapAtFourWithAMoreFooter(): void
    {
        $service = new SlackDebriefService(
            $this->config(),
            self::db(),
            new RecordingSlackClient($this->config()),
        );

        $suggestions = [];

        for ($i = 1; $i <= 6; $i++) {
            $suggestions[] = [
                'contact' => ['id' => $i, 'name' => "Person $i", 'organization_name' => null],
                'title' => "Meeting $i",
                'time' => '9:00 AM',
            ];
        }

        $blocks = $service->calendarBlocks($suggestions);

        // Header + four rows of (section + actions) + the footer.
        self::assertCount(10, $blocks);

        $json = json_encode($blocks, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('From your calendar today', $json);
        self::assertStringContainsString('You met *Person 1* at 9:00 AM: _Meeting 1_', $json);
        self::assertStringContainsString('cal_log_4', $json);
        self::assertStringNotContainsString('Meeting 5', $json);
        self::assertStringContainsString('Plus 2 more meetings that matched contacts today.', $json);
    }

    public function testCalendarBlocksYesterdayCarriesTheLogDate(): void
    {
        $service = new SlackDebriefService(
            $this->config(),
            self::db(),
            new RecordingSlackClient($this->config()),
        );

        $blocks = $service->calendarBlocks(
            [[
                'contact' => ['id' => 7, 'name' => 'Pat Doyle', 'organization_name' => 'Larkspur'],
                'title' => 'Larkspur brief review',
                'time' => '2:00 PM',
            ]],
            'yesterday',
            '2026-11-17',
        );

        $json = json_encode($blocks, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('From your calendar yesterday', $json);
        self::assertStringContainsString('You met *Pat Doyle* (Larkspur) at 2:00 PM: _Larkspur brief review_', $json);
        self::assertStringContainsString('\"d\":\"2026-11-17\"', $json);
        self::assertStringContainsString('cal_log_7', $json);
    }

    public function testCalendarBlocksTomorrowIsAPrepViewWithoutLogButtons(): void
    {
        $service = new SlackDebriefService(
            $this->config(),
            self::db(),
            new RecordingSlackClient($this->config()),
        );

        $blocks = $service->calendarBlocks(
            [[
                'contact' => [
                    'id' => 7,
                    'name' => 'Pat Doyle',
                    'organization_name' => 'Larkspur',
                    'last_touch_date' => '2026-11-01',
                    'human_detail' => 'Prefers a call to a thread',
                ],
                'title' => 'Larkspur brief review',
                'time' => '2:00 PM',
            ]],
            'tomorrow',
        );

        $json = json_encode($blocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('On your calendar tomorrow', $json);
        self::assertStringContainsString(
            "You're meeting *Pat Doyle* (Larkspur) at 2:00 PM: _Larkspur brief review_",
            $json,
        );
        self::assertStringContainsString('Last touch 2026-11-01 · Prefers a call to a thread', $json);
        self::assertStringContainsString('cal_open_7', $json);
        self::assertStringNotContainsString('cal_log_7', $json);
        self::assertStringNotContainsString('Log it', $json);
    }

    public function testTodayTenseIsPerMeetingAgainstNow(): void
    {
        $service = new SlackDebriefService(
            $this->config(),
            self::db(),
            new RecordingSlackClient($this->config()),
        );

        $noonEastern = new DateTimeImmutable('2026-11-18 12:00', new DateTimeZone('America/New_York'));
        $blocks = $service->calendarBlocks(
            [
                [
                    'contact' => ['id' => 7, 'name' => 'Pat Doyle', 'organization_name' => null],
                    'title' => 'Morning stand-up',
                    'time' => '9:00 AM',
                    'start' => '2026-11-18T09:00:00-05:00',
                ],
                [
                    'contact' => ['id' => 8, 'name' => 'Steve Marsh', 'organization_name' => 'Northfield Labs'],
                    'title' => 'Intro call',
                    'time' => '4:00 PM',
                    'start' => '2026-11-18T16:00:00-05:00',
                ],
            ],
            now: $noonEastern,
        );

        $json = json_encode($blocks, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('You met *Pat Doyle* at 9:00 AM: _Morning stand-up_', $json);
        self::assertStringContainsString(
            "You're meeting *Steve Marsh* (Northfield Labs) at 4:00 PM: _Intro call_",
            $json,
        );
        // Future-today rows keep the Log button (you log it right after);
        // only tomorrow's prep view drops it.
        self::assertStringContainsString('cal_log_8', $json);
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
