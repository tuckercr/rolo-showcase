<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Support\Dates;

final class DashboardController extends Controller
{
    /** Days past the follow-up date before a contact counts as going cold. */
    private const COLD_AFTER_DAYS = 30;

    public function index(): string
    {
        $todayDate = $this->todayLocal();
        $today = $todayDate->format('Y-m-d');
        $contacts = new ContactModel($this->db);

        $due = $contacts->dueBy($today);
        $cold = $contacts->goingCold($today, self::COLD_AFTER_DAYS);
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
            'today' => $today,
            'tomorrow' => $todayDate->modify('+1 day')->format('Y-m-d'),
            'overdue' => $overdue,
            'thisWeek' => $thisWeek,
            'goingCold' => $cold,
            'activityTypes' => ActivityModel::TYPES,
            'snoozeOptions' => ContactController::SNOOZE_OPTIONS,
        ]);
    }
}
