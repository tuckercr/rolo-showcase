<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ContactModel;

final class DashboardController extends Controller
{
    public function index(): string
    {
        $today = $this->todayLocal()->format('Y-m-d');
        $due = (new ContactModel($this->db))->dueBy($today);

        $overdue = array_values(array_filter(
            $due,
            static fn(array $c): bool => (string) $c['next_touch_date'] < $today,
        ));
        $thisWeek = array_values(array_filter(
            $due,
            static fn(array $c): bool => (string) $c['next_touch_date'] >= $today,
        ));

        return $this->render('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'today' => $today,
            'overdue' => $overdue,
            'thisWeek' => $thisWeek,
        ]);
    }
}
