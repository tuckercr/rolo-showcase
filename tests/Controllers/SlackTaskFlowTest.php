<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\SlackController;
use App\Models\ProposalModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Services\ProposalService;
use App\Support\Auth;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Database\DatabaseTestCase;

/**
 * The Slack task commands and buttons, end to end: a signed request goes
 * into the real controller and the test reads what came back and what was
 * written. U_DANA maps to user 2 (Dana), U_COLIN to user 1 (migration
 * 0002's seeded users).
 */
final class SlackTaskFlowTest extends DatabaseTestCase
{
    private const SECRET = 'test-signing-secret';

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
            slackSigningSecret: self::SECRET,
            slackUserMap: ['U_DANA' => 2, 'U_COLIN' => 1],
        );
    }

    public function testTaskCommandSavesTheTitleAsTypedForTheAsker(): void
    {
        $reply = $this->command('U_DANA', 'TASK Send the Q1 proposal to Northfield Labs tomorrow');

        $tasks = (new TaskModel(self::db()))->open();
        self::assertCount(1, $tasks);
        self::assertSame('Send the Q1 proposal to Northfield Labs', $tasks[0]['title'], 'casing is kept');
        self::assertSame($this->today()->modify('+1 day')->format('Y-m-d'), $tasks[0]['due_date']);
        self::assertSame(2, (int) $tasks[0]['owner_id']);
        self::assertSame(2, (int) $tasks[0]['created_by']);
        self::assertNull($tasks[0]['contact_id']);

        self::assertSame('ephemeral', $reply['response_type']);
        $json = stripslashes((string) json_encode($reply['blocks']));
        self::assertStringContainsString('Added to your tasks', $json);
        self::assertStringContainsString('due tomorrow', $json, 'the reply shows the date it understood');
        self::assertStringContainsString('"a":"task_done"', $json);
    }

    public function testSlackEscapingIsUndoneBeforeTheTaskIsSaved(): void
    {
        // What Slack sends when someone types:
        //   task Send Q&A doc to <the board> via example.com by friday
        $reply = $this->command(
            'U_DANA',
            'task Send Q&amp;A doc to &lt;the board&gt; via <http://example.com|example.com> by friday',
        );

        $tasks = (new TaskModel(self::db()))->open();
        self::assertCount(1, $tasks);
        self::assertSame('Send Q&A doc to <the board> via example.com', $tasks[0]['title']);
        self::assertNotNull($tasks[0]['due_date'], 'the date is still read off the end');

        // Going back out to Slack it is escaped exactly once.
        self::assertSame(
            'Added to your tasks: Send Q&amp;A doc to &lt;the board&gt; via example.com',
            $reply['text'],
        );
        self::assertStringNotContainsString('&amp;amp;', (string) json_encode($reply['blocks']));
    }

    public function testAnAmpersandInAContactNameStillFindsTheContact(): void
    {
        $contact = $this->insertContact('Marsh & Vale Imports');

        $this->command('U_DANA', 'task for marsh &amp; vale: Send the deck');

        $tasks = (new TaskModel(self::db()))->open();
        self::assertCount(1, $tasks);
        self::assertSame($contact, (int) $tasks[0]['contact_id']);

        $card = $this->command('U_DANA', 'who marsh &amp; vale');
        self::assertSame('Marsh & Vale Imports', $card['text']);
    }

    public function testForNameLinksExactlyOneContactOrAddsNothing(): void
    {
        $maria = $this->insertContact('Maria Santos');
        $this->insertContact('Steve Marsh');
        $this->insertContact('Steve Rogers');

        $this->command('U_DANA', 'task for maria: Send the deck');
        $tasks = (new TaskModel(self::db()))->open();
        self::assertCount(1, $tasks);
        self::assertSame($maria, (int) $tasks[0]['contact_id']);
        self::assertSame('Send the deck', $tasks[0]['title']);

        $ambiguous = $this->command('U_DANA', 'task for steve: Send the write-up');
        self::assertStringContainsString('2 contacts match', $ambiguous['text']);

        $nobody = $this->command('U_DANA', 'task for zelda: Send the write-up');
        self::assertStringContainsString('nothing was added', $nobody['text']);

        self::assertCount(1, (new TaskModel(self::db()))->open(), 'neither miss saved a task');
    }

    public function testBareTaskCommandExplainsItself(): void
    {
        $reply = $this->command('U_DANA', 'task');

        self::assertStringContainsString('/rolo task <what> [when]', $reply['text']);
        self::assertSame([], (new TaskModel(self::db()))->open());
    }

    public function testTasksCommandListsMineAndSharedOnly(): void
    {
        $this->insertTask('Dana task', 2);
        $this->insertTask('Shared task', null);
        $this->insertTask('Colin task', 1);

        $reply = $this->command('U_DANA', 'tasks');
        $json = (string) json_encode($reply['blocks']);

        self::assertSame('*2 open tasks*, soonest first:', $reply['text']);
        self::assertStringContainsString('Dana task', $json);
        self::assertStringContainsString('Shared task', $json);
        self::assertStringNotContainsString('Colin task', $json);

        self::db()->pdo()->exec('DELETE FROM tasks');
        self::assertStringContainsString('No open tasks', $this->command('U_DANA', 'tasks')['text']);
    }

    public function testDoneButtonTicksOnceAndUndoReopens(): void
    {
        $id = $this->insertTask('Shared task', null);

        self::assertSame(['ok' => true], $this->tap('U_DANA', ['a' => 'task_done', 't' => $id]));
        $task = (new TaskModel(self::db()))->find($id);
        self::assertNotNull($task['completed_at']);
        self::assertSame(2, (int) $task['completed_by']);

        // The other person's copy of the same shared task: nothing changes.
        $this->tap('U_COLIN', ['a' => 'task_done', 't' => $id]);
        self::assertSame(2, (int) (new TaskModel(self::db()))->find($id)['completed_by']);

        $this->tap('U_DANA', ['a' => 'task_reopen', 't' => $id]);
        self::assertNull((new TaskModel(self::db()))->find($id)['completed_at']);
    }

    public function testApproveButtonAppliesAProposalExactlyOnce(): void
    {
        $service = new ProposalService($this->config(), self::db());
        [$proposal] = $service->queue('user:1', 'create_task', ['title' => 'From an agent'], null, 1);
        $id = (int) $proposal['id'];

        self::assertSame(['ok' => true], $this->tap('U_DANA', ['a' => 'approve', 'p' => $id]));
        // The same DM goes to both people; the second tap must change nothing.
        $this->tap('U_COLIN', ['a' => 'approve', 'p' => $id]);

        $tasks = (new TaskModel(self::db()))->open();
        self::assertCount(1, $tasks);
        self::assertSame(2, (int) $tasks[0]['created_by'], 'created by the first approver');

        $row = (new ProposalModel(self::db()))->find($id);
        self::assertSame('approved', $row['status']);
        self::assertSame(2, (int) $row['reviewed_by']);
    }

    public function testAnUnmappedSlackAccountCannotWriteTasks(): void
    {
        $reply = $this->command('U_STRANGER', 'task Sneak one in');

        self::assertStringContainsString('not connected to Rolo', $reply['text']);
        self::assertSame([], (new TaskModel(self::db()))->open());
    }

    public function testABadSignatureIsRefused(): void
    {
        $reply = $this->post('command', http_build_query(['user_id' => 'U_DANA', 'text' => 'task X']), 'wrong');

        self::assertSame(['error' => 'bad signature'], $reply);
        self::assertSame([], (new TaskModel(self::db()))->open());
    }

    /**
     * @return array<string, mixed>
     */
    private function command(string $slackUser, string $text): array
    {
        return $this->post('command', http_build_query(['user_id' => $slackUser, 'text' => $text]));
    }

    /**
     * A button tap. No response_url, so the controller performs the action
     * without trying to call Slack back.
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function tap(string $slackUser, array $action): array
    {
        return $this->post('interact', http_build_query(['payload' => json_encode([
            'type' => 'block_actions',
            'user' => ['id' => $slackUser],
            'actions' => [['action_id' => 'x', 'value' => json_encode($action)]],
        ])]));
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $method, string $body, string $secret = self::SECRET): array
    {
        $timestamp = (string) time();
        $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] = $timestamp;
        $_SERVER['HTTP_X_SLACK_SIGNATURE'] = 'v0=' . hash_hmac('sha256', "v0:$timestamp:$body", $secret);

        $controller = new SlackController($this->config(), self::db(), new Auth(new UserModel(self::db())));

        PhpInputStub::$body = $body;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', PhpInputStub::class);

        try {
            $response = $controller->{$method}();
        } finally {
            stream_wrapper_restore('php');
            unset($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'], $_SERVER['HTTP_X_SLACK_SIGNATURE']);
        }

        return (array) json_decode($response, true);
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
    }

    private function insertTask(string $title, ?int $owner): int
    {
        self::db()->pdo()->prepare(
            'INSERT INTO tasks (title, owner_id, created_by, updated_by) VALUES (?, ?, 1, 1)'
        )->execute([$title, $owner]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    private function insertContact(string $name): int
    {
        self::db()->pdo()->prepare(
            "INSERT INTO contacts (name, relationship_status, created_by, updated_by)
             VALUES (?, 'Conversation Started', 1, 1)"
        )->execute([$name]);

        return (int) self::db()->pdo()->lastInsertId();
    }
}
