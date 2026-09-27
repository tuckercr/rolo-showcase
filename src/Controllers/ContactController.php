<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityModel;
use App\Models\AttachmentModel;
use App\Models\ContactModel;
use App\Models\OrganizationModel;
use App\Models\ReminderRuleModel;
use App\Models\StageModel;
use App\Models\TagModel;
use App\Services\AttachmentService;
use App\Services\ReminderService;
use App\Support\View;
use DateTimeImmutable;

final class ContactController extends Controller
{
    public function index(): string
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $tag = trim((string) ($_GET['tag'] ?? ''));

        $sort = (string) ($_GET['sort'] ?? 'name');
        if (!array_key_exists($sort, ContactModel::SORTS)) {
            $sort = 'name';
        }
        $dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $contacts = new ContactModel($this->db);

        return $this->render('contacts/index', [
            'pageTitle' => 'Contacts',
            'contacts' => $contacts->search($query, $status, null, $sort, $dir, $tag),
            'stages' => (new StageModel($this->db))->allOrdered(),
            'allTags' => (new TagModel($this->db))->allWithCounts(),
            'query' => $query,
            'status' => $status,
            'tag' => $tag,
            'sort' => $sort,
            'dir' => $dir,
            'today' => $this->todayLocal()->format('Y-m-d'),
            'trashedCount' => $contacts->trashedCount(),
        ]);
    }

    public function trashIndex(): string
    {
        $rows = (new ContactModel($this->db))->trashed();

        foreach ($rows as &$row) {
            $deletedAt = new \DateTimeImmutable((string) $row['deleted_at'], new \DateTimeZone('UTC'));
            $row['deleted_at_local'] = $deletedAt
                ->setTimezone($this->config->appTimezone)
                ->format('M j, Y g:i A');
        }
        unset($row);

        return $this->render('contacts/trash', [
            'pageTitle' => 'Trash',
            'contacts' => $rows,
        ]);
    }

    /** Whitelisted snooze lengths (days) offered by the contact page dropdown. */
    public const SNOOZE_OPTIONS = [1 => '1 day', 7 => '1 week', 30 => '1 month'];

    /**
     * Snooze the follow-up: next touch becomes today + N days, regardless of
     * how overdue it was — the quick "not now" action.
     *
     * @param array<string, string> $vars route parameters
     */
    public function snooze(array $vars): string
    {
        $this->requireValidCsrf();

        $contacts = new ContactModel($this->db);
        $contact = $contacts->find((int) $vars['id']);

        if ($contact === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        $days = (int) ($_POST['days'] ?? 7);

        if (!array_key_exists($days, self::SNOOZE_OPTIONS)) {
            $days = 7;
        }

        $oldDate = (string) ($contact['next_touch_date'] ?? '');
        $newDate = $this->todayLocal()->modify(sprintf('+%d days', $days))->format('Y-m-d');

        $contacts->snooze((int) $contact['id'], $newDate, $this->auth->id());

        // Leave a trace in the contact's history. Doesn't touch last_touch —
        // ActivityModel::create only inserts the row.
        (new ActivityModel($this->db))->create(
            contactId: (int) $contact['id'],
            organizationId: $contact['organization_id'] === null ? null : (int) $contact['organization_id'],
            activityType: 'snoozed',
            activityDate: $this->todayLocal()->format('Y-m-d'),
            summary: sprintf(
                'Modified follow up date from: %s, to: %s',
                $oldDate === '' ? 'none' : $oldDate,
                $newDate,
            ),
            followUpNeeded: false,
            followUpDate: null,
            userId: $this->auth->id(),
        );

        return $this->redirect('/contacts/' . (int) $contact['id']);
    }

    public function trash(array $vars): string
    {
        $this->requireValidCsrf();

        $contacts = new ContactModel($this->db);

        if ($contacts->find((int) $vars['id']) === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        $contacts->trash((int) $vars['id'], $this->auth->id());

        return $this->redirect('/contacts');
    }

    public function restore(array $vars): string
    {
        $this->requireValidCsrf();

        (new ContactModel($this->db))->restore((int) $vars['id']);

        return $this->redirect('/contacts/trash');
    }

    public function show(array $vars): string
    {
        $contacts = new ContactModel($this->db);
        $contact = $contacts->find((int) $vars['id']);

        if ($contact === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        // The follow-up queue = the dashboard list (overdue + due this week,
        // soonest first). When this contact is in it, offer "next in queue"
        // so Jessica can work through follow-ups without bouncing back.
        $queue = $contacts->dueBy($this->todayLocal()->format('Y-m-d'));
        $queueIds = array_map(static fn(array $c): int => (int) $c['id'], $queue);
        $queueIndex = array_search((int) $contact['id'], $queueIds, true);

        $queueNext = null;
        if ($queueIndex !== false && $queueIndex + 1 < count($queue)) {
            $queueNext = $queue[$queueIndex + 1];
        }

        return $this->render('contacts/show', [
            'queueIndex' => $queueIndex === false ? null : $queueIndex,
            'queueTotal' => count($queue),
            'queueNext' => $queueNext,
            'pageTitle' => (string) $contact['name'],
            'contact' => $contact,
            'contactTags' => (new TagModel($this->db))->namesForContact((int) $contact['id']),
            'activities' => (new ActivityModel($this->db))->forContact((int) $contact['id']),
            'activityTypes' => ActivityModel::TYPES,
            'historyTypes' => ActivityModel::DISPLAY_TYPES,
            'today' => $this->todayLocal()->format('Y-m-d'),
            'activityError' => ($_GET['error'] ?? '') === 'activity',
            'attachmentError' => ($_GET['error'] ?? '') === 'attachment',
            'storageError' => ($_GET['error'] ?? '') === 'storage',
            'attachmentsByActivity' => (new AttachmentModel($this->db))
                ->forContactByActivity((int) $contact['id']),
            'allowedTypesLabel' => AttachmentService::allowedExtensionsLabel(),
            'snoozeOptions' => self::SNOOZE_OPTIONS,
        ]);
    }

    public function create(): string
    {
        return $this->render('contacts/create', [
            'pageTitle' => 'Add contact',
            'stages' => (new StageModel($this->db))->allOrdered(),
            'organizations' => (new OrganizationModel($this->db))->allOrdered(),
            'relationshipTypes' => ContactModel::RELATIONSHIP_TYPES,
            'errors' => [],
            'old' => [],
        ]);
    }

    public function store(): string
    {
        $this->requireValidCsrf();

        [$data, $errors] = $this->validated($_POST);

        if ($errors !== []) {
            return $this->render('contacts/create', [
                'pageTitle' => 'Add contact',
                'stages' => (new StageModel($this->db))->allOrdered(),
                'organizations' => (new OrganizationModel($this->db))->allOrdered(),
                'relationshipTypes' => ContactModel::RELATIONSHIP_TYPES,
                'errors' => $errors,
                'old' => $_POST,
            ]);
        }

        // New contact: next touch comes from the chosen status's rule,
        // counted from today (there is no touch history yet).
        $data['next_touch_date'] = $this->computeNextTouch(
            status: $data['relationship_status'],
            reference: $this->todayLocal(),
            lastTouch: null,
            override: $data['cadence_days_override'],
        );

        $id = (new ContactModel($this->db))->create($data, $this->auth->id());
        (new TagModel($this->db))->syncForContact($id, $data['tags']);

        return $this->redirect('/contacts/' . $id);
    }

    public function edit(array $vars): string
    {
        $contact = (new ContactModel($this->db))->find((int) $vars['id']);

        if ($contact === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        return $this->render('contacts/edit', [
            'pageTitle' => 'Edit ' . (string) $contact['name'],
            'contact' => $contact,
            'stages' => (new StageModel($this->db))->allOrdered(),
            'organizations' => (new OrganizationModel($this->db))->allOrdered(),
            'relationshipTypes' => ContactModel::RELATIONSHIP_TYPES,
            'errors' => [],
            'old' => $contact + [
                'tags' => implode(', ', (new TagModel($this->db))->namesForContact((int) $contact['id'])),
            ],
        ]);
    }

    public function update(array $vars): string
    {
        $this->requireValidCsrf();

        $contacts = new ContactModel($this->db);
        $contact = $contacts->find((int) $vars['id']);

        if ($contact === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        [$data, $errors] = $this->validated($_POST);

        if ($errors !== []) {
            return $this->render('contacts/edit', [
                'pageTitle' => 'Edit ' . (string) $contact['name'],
                'contact' => $contact,
                'stages' => (new StageModel($this->db))->allOrdered(),
                'organizations' => (new OrganizationModel($this->db))->allOrdered(),
                'relationshipTypes' => ContactModel::RELATIONSHIP_TYPES,
                'errors' => $errors,
                'old' => $_POST,
            ]);
        }

        $statusChanged = $data['relationship_status'] !== (string) $contact['relationship_status'];
        $overrideChanged = $data['cadence_days_override']
            !== ($contact['cadence_days_override'] === null ? null : (int) $contact['cadence_days_override']);

        if ($statusChanged || $overrideChanged) {
            // Status-triggered defaults (docs/SCHEMA.md): recalculate from the
            // NEW status's rule immediately, counting from today.
            $lastTouch = $contact['last_touch_date'] === null
                ? null
                : new DateTimeImmutable((string) $contact['last_touch_date']);

            $data['next_touch_date'] = $this->computeNextTouch(
                status: $data['relationship_status'],
                reference: $this->todayLocal(),
                lastTouch: $lastTouch,
                override: $data['cadence_days_override'],
            );
        } else {
            $data['next_touch_date'] = $contact['next_touch_date'];
        }

        $contacts->update((int) $contact['id'], $data, $this->auth->id());
        (new TagModel($this->db))->syncForContact((int) $contact['id'], $data['tags']);

        return $this->redirect('/contacts/' . (int) $contact['id']);
    }

    private function computeNextTouch(
        string $status,
        DateTimeImmutable $reference,
        ?DateTimeImmutable $lastTouch,
        ?int $override,
    ): ?string {
        $rules = (new ReminderRuleModel($this->db))->activeByStatus();
        $next = (new ReminderService($rules))->nextTouchDate($status, $reference, $lastTouch, $override);

        return $next?->format('Y-m-d');
    }

    /**
     * Server-side validation per CLAUDE.md — only name is truly required
     * (the SOP's "under 30 seconds" capture rule).
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validated(array $input): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        $status = trim((string) ($input['relationship_status'] ?? ''));
        if ($status === '' || !(new StageModel($this->db))->exists($status)) {
            $errors['relationship_status'] = 'Pick a valid stage.';
        }

        $organizationId = null;
        $orgInput = (string) ($input['organization_id'] ?? '');
        if ($orgInput !== '') {
            $organizationId = (int) $orgInput;
            if ((new OrganizationModel($this->db))->find($organizationId) === null) {
                $errors['organization_id'] = 'Unknown organization.';
            }
        }

        $relationshipType = trim((string) ($input['relationship_type'] ?? ''));
        if ($relationshipType !== '' && !in_array($relationshipType, ContactModel::RELATIONSHIP_TYPES, true)) {
            $errors['relationship_type'] = 'Unknown relationship type.';
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'That email address doesn\'t look valid.';
        }

        $phone = trim((string) ($input['phone'] ?? ''));
        if ($phone !== '' && preg_match('/^[0-9+\-().\s]{3,50}$/', $phone) !== 1) {
            $errors['phone'] = 'That phone number doesn\'t look valid.';
        }

        $linkedin = trim((string) ($input['linkedin_url'] ?? ''));
        if ($linkedin !== '' && filter_var($linkedin, FILTER_VALIDATE_URL) === false) {
            $errors['linkedin_url'] = 'LinkedIn URL must be a full URL (https://…).';
        }

        $override = null;
        $overrideInput = trim((string) ($input['cadence_days_override'] ?? ''));
        if ($overrideInput !== '') {
            $override = filter_var($overrideInput, FILTER_VALIDATE_INT);
            if ($override === false || $override < 1) {
                $errors['cadence_days_override'] = 'Cadence override must be a positive number of days.';
                $override = null;
            }
        }

        $tags = TagModel::parseList((string) ($input['tags'] ?? ''));

        foreach ($tags as $tagName) {
            if (mb_strlen($tagName) > TagModel::MAX_LENGTH) {
                $errors['tags'] = sprintf('Tags can be at most %d characters each.', TagModel::MAX_LENGTH);
                break;
            }
        }

        $data = [
            'name' => $name,
            'tags' => $tags,
            'organization_id' => $organizationId,
            'title' => trim((string) ($input['title'] ?? '')) ?: null,
            'relationship_status' => $status,
            'relationship_type' => $relationshipType === '' ? null : $relationshipType,
            'email' => $email === '' ? null : $email,
            'phone' => $phone === '' ? null : $phone,
            'linkedin_url' => $linkedin === '' ? null : $linkedin,
            'cadence_days_override' => $override,
            'human_detail' => trim((string) ($input['human_detail'] ?? '')) ?: null,
        ];

        return [$data, $errors];
    }
}
