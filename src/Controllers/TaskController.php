<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ContactModel;
use App\Models\OrganizationModel;
use App\Models\TaskModel;
use App\Services\TaskService;
use App\Support\TaskLabels;
use App\Support\View;

/**
 * Tasks on the web: the /tasks list, the edit form, and the small POST
 * actions (add, tick, untick, delete) that the dashboard, contact and
 * organization pages submit to. All rules live in TaskService.
 */
final class TaskController extends Controller
{
    public function index(): string
    {
        $scope = $this->followUpScope();
        $status = ($_GET['status'] ?? 'open') === 'done' ? 'done' : 'open';
        $today = $this->todayLocal()->format('Y-m-d');
        $tasks = new TaskModel($this->db);

        $rows = TaskLabels::decorate(
            $status === 'done' ? $tasks->completed($scope, limit: 100) : $tasks->open($scope),
            $today,
            $this->config->appTimezone,
        );

        return $this->render('tasks/index', [
            'pageTitle' => 'Tasks',
            'view' => $scope === null ? 'everyone' : 'mine',
            'status' => $status,
            'groups' => $status === 'done' ? ['Done' => $rows] : self::groupOpen($rows, $today),
            'taskCount' => count($rows),
            'contactOptions' => (new ContactModel($this->db))->options(),
            'owners' => (new TaskService($this->config, $this->db))->assignableOwners(),
            'taskError' => self::flashedError(),
        ]);
    }

    public function store(): string
    {
        $this->requireValidCsrf();

        $service = new TaskService($this->config, $this->db);
        $input = $this->postedFields();

        // The quick-add rows carry no owner picker: a task you add is yours
        // unless a form says otherwise.
        if (!array_key_exists('owner_id', $input)) {
            $input['owner_id'] = $this->auth->id();
        }

        [$clean, $errors] = $service->validate($input);
        $back = self::returnTo($_POST['return_to'] ?? null);

        if ($errors !== []) {
            return $this->redirect(self::withError($back, (string) reset($errors)));
        }

        $service->create($clean, $this->auth->id());

        return $this->redirect($back);
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function edit(array $vars): string
    {
        $task = (new TaskModel($this->db))->find((int) $vars['id']);

        if ($task === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        return $this->renderEdit($task, [], []);
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function update(array $vars): string
    {
        $this->requireValidCsrf();

        $task = (new TaskModel($this->db))->find((int) $vars['id']);

        if ($task === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        $service = new TaskService($this->config, $this->db);
        [$clean, $errors] = $service->validate($this->postedFields(), $task);

        if ($errors !== []) {
            return $this->renderEdit($task, $errors, $_POST);
        }

        $service->update((int) $task['id'], $clean, $this->auth->id());

        return $this->redirect(self::returnTo($_POST['return_to'] ?? null, '/tasks'));
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function complete(array $vars): string
    {
        $this->requireValidCsrf();

        (new TaskService($this->config, $this->db))->complete((int) $vars['id'], $this->auth->id());

        return $this->redirect(self::returnTo($_POST['return_to'] ?? null));
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function reopen(array $vars): string
    {
        $this->requireValidCsrf();

        (new TaskService($this->config, $this->db))->reopen((int) $vars['id'], $this->auth->id());

        return $this->redirect(self::returnTo($_POST['return_to'] ?? null));
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function delete(array $vars): string
    {
        $this->requireValidCsrf();

        (new TaskModel($this->db))->delete((int) $vars['id']);

        return $this->redirect(self::returnTo($_POST['return_to'] ?? null, '/tasks'));
    }

    /**
     * Where a task action returns to. Only this app's own pages that host
     * task controls are accepted, as bare paths, so a crafted return_to can
     * never bounce the browser anywhere else. Public for tests.
     */
    public static function returnTo(mixed $raw, string $default = '/tasks'): string
    {
        $path = is_string($raw) ? $raw : '';

        // \z, not $: $ also matches before a trailing newline, and PHP then
        // refuses to send the Location header at all (a blank page).
        return preg_match('#^/(?:tasks|contacts/\d+|organizations/\d+)?\z#', $path) === 1 ? $path : $default;
    }

    /**
     * Split decorated open tasks into the /tasks page's sections. Public
     * for tests.
     *
     * @param list<array<string, mixed>> $rows TaskLabels::decorate() output
     * @return array<string, list<array<string, mixed>>> heading => rows,
     *         empty sections omitted
     */
    public static function groupOpen(array $rows, string $today): array
    {
        $groups = ['Overdue' => [], 'Today' => [], 'Upcoming' => [], 'No date' => []];

        foreach ($rows as $row) {
            $due = $row['due_date'] === null ? '' : (string) $row['due_date'];

            $groups[match (true) {
                $due === '' => 'No date',
                $due < $today => 'Overdue',
                $due === $today => 'Today',
                default => 'Upcoming',
            }][] = $row;
        }

        return array_filter($groups, static fn(array $g): bool => $g !== []);
    }

    /**
     * The task fields present in the POST, untouched. A field the form did
     * not send stays absent so TaskService keeps (or defaults) it.
     *
     * @return array<string, mixed>
     */
    private function postedFields(): array
    {
        return array_intersect_key($_POST, array_flip(TaskService::FIELDS));
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, string> $errors
     * @param array<string, mixed> $old the rejected POST, when re-rendering
     */
    private function renderEdit(array $task, array $errors, array $old): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        [$decorated] = TaskLabels::decorate([$task], $today, $this->config->appTimezone);

        return $this->render('tasks/edit', [
            'pageTitle' => 'Edit task',
            'task' => $decorated,
            'errors' => $errors,
            'old' => $old,
            'contactOptions' => (new ContactModel($this->db))->options(),
            'organizations' => (new OrganizationModel($this->db))->allOrdered(),
            'owners' => (new TaskService($this->config, $this->db))->ownerOptions($task),
            'returnTo' => self::returnTo($_GET['return_to'] ?? $_POST['return_to'] ?? null),
        ]);
    }

    private static function withError(string $path, string $message): string
    {
        return $path . '?task_error=' . rawurlencode($message);
    }

    /**
     * The validation message a quick-add redirect carried back, if any.
     * Shared with the dashboard, contact and organization controllers.
     */
    public static function flashedError(): ?string
    {
        $message = trim((string) ($_GET['task_error'] ?? ''));

        return $message === '' ? null : mb_substr($message, 0, 200);
    }
}
