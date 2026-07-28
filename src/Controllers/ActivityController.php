<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\ReminderRuleModel;
use App\Services\ReminderService;
use App\Support\View;
use DateTimeImmutable;

final class ActivityController extends Controller
{
    /**
     * Log an interaction from the contact page, then advance the contact's
     * last_touch_date and recalculate next_touch_date per the rules engine.
     *
     * @param array<string, string> $vars route parameters
     */
    public function store(array $vars): string
    {
        $this->requireValidCsrf();

        $contactId = (int) $vars['id'];
        $contacts = new ContactModel($this->db);
        $contact = $contacts->find($contactId);

        if ($contact === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        $activityType = (string) ($_POST['activity_type'] ?? '');
        $activityDate = (string) ($_POST['activity_date'] ?? '');
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $followUpNeeded = ($_POST['follow_up_needed'] ?? '') === '1';
        $followUpDate = trim((string) ($_POST['follow_up_date'] ?? ''));

        if (
            !array_key_exists($activityType, ActivityModel::TYPES)
            || !$this->isValidDate($activityDate)
            || ($followUpDate !== '' && !$this->isValidDate($followUpDate))
        ) {
            return $this->redirect('/contacts/' . $contactId . '?error=activity');
        }

        (new ActivityModel($this->db))->create(
            contactId: $contactId,
            organizationId: $contact['organization_id'] === null ? null : (int) $contact['organization_id'],
            activityType: $activityType,
            activityDate: $activityDate,
            summary: $summary,
            followUpNeeded: $followUpNeeded,
            followUpDate: $followUpDate === '' ? null : $followUpDate,
            userId: $this->auth->id(),
        );

        // Backdated activities must not pull last_touch_date backwards.
        $currentLastTouch = (string) ($contact['last_touch_date'] ?? '');
        $newLastTouch = max($currentLastTouch, $activityDate);

        $nextTouchDate = $this->nextTouchAfterActivity(
            $contact,
            $activityDate,
            $newLastTouch,
            $followUpNeeded,
            $followUpDate === '' ? null : $followUpDate,
        );

        $contacts->recordTouch($contactId, $newLastTouch, $nextTouchDate, $this->auth->id());

        return $this->redirect('/contacts/' . $contactId);
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function edit(array $vars): string
    {
        $activity = (new ActivityModel($this->db))->find((int) $vars['id']);

        // Snooze entries are system-generated and not editable.
        if ($activity === null || (string) $activity['activity_type'] === 'snoozed') {
            http_response_code(404);
            return View::render('errors/404');
        }

        $contact = (new ContactModel($this->db))->find((int) $activity['contact_id']);

        return $this->render('activities/edit', [
            'pageTitle' => 'Edit interaction',
            'activity' => $activity,
            'contact' => $contact,
            'activityTypes' => ActivityModel::TYPES,
        ]);
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function update(array $vars): string
    {
        $this->requireValidCsrf();

        $activities = new ActivityModel($this->db);
        $activity = $activities->find((int) $vars['id']);

        if ($activity === null || (string) $activity['activity_type'] === 'snoozed') {
            http_response_code(404);
            return View::render('errors/404');
        }

        $contactId = (int) $activity['contact_id'];

        $activityType = (string) ($_POST['activity_type'] ?? '');
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $followUpNeeded = ($_POST['follow_up_needed'] ?? '') === '1';
        $followUpDate = trim((string) ($_POST['follow_up_date'] ?? ''));

        if (
            !array_key_exists($activityType, ActivityModel::TYPES)
            || ($followUpDate !== '' && !$this->isValidDate($followUpDate))
        ) {
            return $this->redirect('/activities/' . (int) $activity['id'] . '/edit');
        }

        $activities->update(
            id: (int) $activity['id'],
            activityType: $activityType,
            summary: $summary,
            followUpNeeded: $followUpNeeded,
            followUpDate: $followUpDate === '' ? null : $followUpDate,
            userId: $this->auth->id(),
        );

        // Editing the follow-up on the contact's most recent entry can move
        // the next touch; older entries never reschedule anything.
        if ($activities->isLatestForContact((int) $activity['id'], $contactId)) {
            $contacts = new ContactModel($this->db);
            $contact = $contacts->find($contactId);

            if ($contact !== null) {
                $nextTouchDate = $this->nextTouchAfterActivity(
                    $contact,
                    (string) $activity['activity_date'],
                    (string) ($contact['last_touch_date'] ?? $activity['activity_date']),
                    $followUpNeeded,
                    $followUpDate === '' ? null : $followUpDate,
                );

                $contacts->recordTouch(
                    $contactId,
                    (string) ($contact['last_touch_date'] ?? $activity['activity_date']),
                    $nextTouchDate,
                    $this->auth->id(),
                );
            }
        }

        return $this->redirect('/contacts/' . $contactId);
    }

    /**
     * Shared scheduling rule: an explicit "follow up by" date is a human
     * decision and beats the stage cadence; otherwise the rules engine
     * computes from the activity date.
     *
     * @param array<string, mixed> $contact
     */
    private function nextTouchAfterActivity(
        array $contact,
        string $activityDate,
        string $lastTouchDate,
        bool $followUpNeeded,
        ?string $followUpDate,
    ): ?string {
        if ($followUpNeeded && $followUpDate !== null) {
            return $followUpDate;
        }

        $rules = (new ReminderRuleModel($this->db))->activeByStatus();
        $next = (new ReminderService($rules))->nextTouchDate(
            relationshipStatus: (string) $contact['relationship_status'],
            referenceDate: new DateTimeImmutable($activityDate),
            lastTouchDate: new DateTimeImmutable($lastTouchDate),
            cadenceDaysOverride: $contact['cadence_days_override'] === null
                ? null
                : (int) $contact['cadence_days_override'],
        );

        return $next?->format('Y-m-d');
    }

    private function isValidDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
