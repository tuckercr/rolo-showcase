<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\TaskModel;
use App\Support\Dates;
use App\Support\TaskLabels;
use DateTimeImmutable;
use DateTimeZone;

final class DashboardController extends Controller
{
    /** Days past the follow-up date before a contact counts as going cold. */
    private const COLD_AFTER_DAYS = 30;

    public function index(): string
    {
        $todayDate = $this->todayLocal();
        $today = $todayDate->format('Y-m-d');
        $contacts = new ContactModel($this->db);
        $scope = $this->followUpScope();

        $due = $contacts->dueBy($today, 6, $scope);
        $cold = $contacts->goingCold($today, self::COLD_AFTER_DAYS, $scope);
        $coldIds = array_map(static fn(array $c): int => (int) $c['id'], $cold);

        // Going-cold contacts leave the ordinary Overdue list; mildly late
        // and quietly dying are different problems.
        $overdue = array_values(array_filter(
            $due,
            static fn(array $c): bool => (string) $c['next_touch_date'] < $today
                && !in_array((int) $c['id'], $coldIds, true),
        ));
        $thisWeek = array_values(array_filter(
            $due,
            static fn(array $c): bool => (string) $c['next_touch_date'] >= $today,
        ));

        // Tasks under the same lens: everything overdue, due within the
        // same 7-day window as the follow-ups, or undated. Later-dated ones
        // are counted, not listed (they live on /tasks). Tasks ticked in the
        // last day stay visible, checked, so a mis-tick is one click to undo.
        $tasks = new TaskModel($this->db);
        $openTasks = $tasks->open($scope, $todayDate->modify('+6 days')->format('Y-m-d'));
        $doneTasks = $tasks->completed(
            $scope,
            (new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            5,
        );

        // Empty Mine view: say how many contacts the user owns rather than
        // showing three bare "nothing here" sections (Colin, 2026-11-19).
        $ownedCount = null;

        if ($scope !== null && $overdue === [] && $thisWeek === [] && $cold === [] && $openTasks === []) {
            $ownedCount = $contacts->countForOwner($scope);
        }

        $zone = $this->config->appTimezone;

        foreach ($overdue as $i => $c) {
            $overdue[$i]['days_overdue'] = Dates::daysBetween(
                (string) $c['next_touch_date'],
                $today,
                $zone,
            );
        }

        foreach ($cold as $i => $c) {
            $cold[$i]['days_since_contact'] = $c['last_touch_date'] === null
                ? null
                : Dates::daysBetween((string) $c['last_touch_date'], $today, $zone);
        }

        return $this->render('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'view' => $scope === null ? 'everyone' : 'mine',
            'ownedCount' => $ownedCount,
            'ownerId' => $scope,
            'today' => $today,
            'tomorrow' => $todayDate->modify('+1 day')->format('Y-m-d'),
            'overdue' => $overdue,
            'thisWeek' => $thisWeek,
            'goingCold' => $cold,
            'openTaskCount' => count($openTasks),
            'laterTaskCount' => $tasks->countOpen($scope) - count($openTasks),
            'tasks' => TaskLabels::decorate(
                array_merge($openTasks, $doneTasks),
                $today,
                $this->config->appTimezone,
            ),
            'contactOptions' => $contacts->options(),
            'taskError' => TaskController::flashedError(),
            'activityTypes' => ActivityModel::TYPES,
            'snoozeOptions' => ContactController::SNOOZE_OPTIONS,
        ]);
    }
}
