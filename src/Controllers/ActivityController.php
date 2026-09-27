<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityModel;
use App\Models\AttachmentModel;
use App\Models\ContactModel;
use App\Models\ReminderRuleModel;
use App\Services\AttachmentService;
use App\Services\ReminderService;
use App\Support\View;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

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
        $followUpNote = $this->followUpNote($followUpNeeded);

        if (
            !array_key_exists($activityType, ActivityModel::TYPES)
            || !$this->isValidDate($activityDate)
            || ($followUpDate !== '' && !$this->isValidDate($followUpDate))
        ) {
            return $this->redirect('/contacts/' . $contactId . '?error=activity');
        }

        // Validate every attachment BEFORE creating the activity, so a bad
        // file doesn't leave a half-logged interaction behind.
        $uploads = AttachmentService::normalizeMultiUpload($_FILES['attachments'] ?? []);
        $storage = AttachmentService::forApp();

        if (count($uploads) > AttachmentService::MAX_PER_ACTIVITY) {
            return $this->redirect('/contacts/' . $contactId . '?error=attachment');
        }

        try {
            foreach ($uploads as $upload) {
                $storage->validate($upload['name'], $upload['tmp_name'], $upload['size'], $upload['error']);
            }
        } catch (InvalidArgumentException) {
            return $this->redirect('/contacts/' . $contactId . '?error=attachment');
        }

        $activityId = (new ActivityModel($this->db))->create(
            contactId: $contactId,
            organizationId: $contact['organization_id'] === null ? null : (int) $contact['organization_id'],
            activityType: $activityType,
            activityDate: $activityDate,
            summary: $summary,
            followUpNeeded: $followUpNeeded,
            followUpDate: $followUpDate === '' ? null : $followUpDate,
            userId: $this->auth->id(),
            followUpNote: $followUpNote,
        );

        $attachments = new AttachmentModel($this->db);

        try {
            foreach ($uploads as $upload) {
                $meta = $storage->store($upload['name'], $upload['tmp_name'], $upload['size']);
                $attachments->create($activityId, $meta, $this->auth->id());
            }
        } catch (RuntimeException) {
            // Disk trouble (full/permissions): the interaction is logged; the
            // file isn't. Tell the user instead of a blank 500.
            return $this->redirect('/contacts/' . $contactId . '?error=storage');
        }

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
            'attachments' => (new AttachmentModel($this->db))->forActivity((int) $activity['id']),
            'attachmentError' => ($_GET['error'] ?? '') === 'attachment',
            'storageError' => ($_GET['error'] ?? '') === 'storage',
            'allowedTypesLabel' => AttachmentService::allowedExtensionsLabel(),
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
        $activityDate = (string) ($_POST['activity_date'] ?? '');
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $followUpNeeded = ($_POST['follow_up_needed'] ?? '') === '1';
        $followUpDate = trim((string) ($_POST['follow_up_date'] ?? ''));
        $followUpNote = $this->followUpNote($followUpNeeded);

        if (
            !array_key_exists($activityType, ActivityModel::TYPES)
            || !$this->isValidDate($activityDate)
            || ($followUpDate !== '' && !$this->isValidDate($followUpDate))
        ) {
            return $this->redirect('/activities/' . (int) $activity['id'] . '/edit');
        }

        // New attachments (existing ones are managed with per-file Remove
        // buttons). Validate before writing anything.
        $uploads = AttachmentService::normalizeMultiUpload($_FILES['attachments'] ?? []);
        $storage = AttachmentService::forApp();
        $attachments = new AttachmentModel($this->db);

        $existingCount = $attachments->countForActivity((int) $activity['id']);

        if ($existingCount + count($uploads) > AttachmentService::MAX_PER_ACTIVITY) {
            return $this->redirect('/activities/' . (int) $activity['id'] . '/edit?error=attachment');
        }

        try {
            foreach ($uploads as $upload) {
                $storage->validate($upload['name'], $upload['tmp_name'], $upload['size'], $upload['error']);
            }
        } catch (InvalidArgumentException) {
            return $this->redirect('/activities/' . (int) $activity['id'] . '/edit?error=attachment');
        }

        $activities->update(
            id: (int) $activity['id'],
            activityType: $activityType,
            activityDate: $activityDate,
            summary: $summary,
            followUpNeeded: $followUpNeeded,
            followUpDate: $followUpDate === '' ? null : $followUpDate,
            userId: $this->auth->id(),
            followUpNote: $followUpNote,
        );

        try {
            foreach ($uploads as $upload) {
                $meta = $storage->store($upload['name'], $upload['tmp_name'], $upload['size']);
                $attachments->create((int) $activity['id'], $meta, $this->auth->id());
            }
        } catch (RuntimeException) {
            $this->recomputeContactSchedule($contactId);

            return $this->redirect('/activities/' . (int) $activity['id'] . '/edit?error=storage');
        }

        // Dates are editable, so "which entry is latest" may have changed —
        // rebuild the schedule from what's actually in the history now.
        $this->recomputeContactSchedule($contactId);

        return $this->redirect('/contacts/' . $contactId);
    }

    /**
     * Delete a logged interaction (typo cleanup — Jessica's request), then
     * rebuild the contact's touch dates from the remaining history.
     *
     * @param array<string, string> $vars route parameters
     */
    public function delete(array $vars): string
    {
        $this->requireValidCsrf();

        $activities = new ActivityModel($this->db);
        $activity = $activities->find((int) $vars['id']);

        if ($activity === null || (string) $activity['activity_type'] === 'snoozed') {
            http_response_code(404);
            return View::render('errors/404');
        }

        $contactId = (int) $activity['contact_id'];

        // Unlink stored files first — the DB rows go with the activity via
        // ON DELETE CASCADE, but disk cleanup is ours to do.
        $storage = AttachmentService::forApp();

        foreach ((new AttachmentModel($this->db))->forActivity((int) $activity['id']) as $attachment) {
            $storage->delete((string) $attachment['stored_name']);
        }

        $activities->delete((int) $activity['id']);
        $this->recomputeContactSchedule($contactId);

        return $this->redirect('/contacts/' . $contactId);
    }

    /**
     * Rebuild last_touch/next_touch from the contact's current history:
     * last touch = most recent non-snooze activity; next touch = that
     * activity's explicit follow-up date if set, else the cadence rule.
     * With no real activities left, last touch clears and the scheduled
     * next touch is left alone (it may have come from a snooze or the
     * contact's creation).
     */
    private function recomputeContactSchedule(int $contactId): void
    {
        $contacts = new ContactModel($this->db);
        $contact = $contacts->find($contactId);

        if ($contact === null) {
            return;
        }

        $latest = (new ActivityModel($this->db))->latestTouchFor($contactId);

        if ($latest === null) {
            $contacts->recordTouch(
                $contactId,
                null,
                $contact['next_touch_date'] === null ? null : (string) $contact['next_touch_date'],
                $this->auth->id(),
            );

            return;
        }

        $lastTouch = (string) $latest['activity_date'];
        $followUpDate = $latest['follow_up_date'] === null ? null : (string) $latest['follow_up_date'];

        $nextTouchDate = $this->nextTouchAfterActivity(
            $contact,
            $lastTouch,
            $lastTouch,
            (int) $latest['follow_up_needed'] === 1,
            $followUpDate,
        );

        $contacts->recordTouch($contactId, $lastTouch, $nextTouchDate, $this->auth->id());
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

    /**
     * The optional "what needs doing" note. Only kept when the follow-up
     * box is checked; the note describes that follow-up action.
     */
    private function followUpNote(bool $followUpNeeded): ?string
    {
        $note = trim((string) ($_POST['follow_up_note'] ?? ''));

        if (!$followUpNeeded || $note === '') {
            return null;
        }

        return mb_substr($note, 0, 255);
    }

    private function isValidDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
