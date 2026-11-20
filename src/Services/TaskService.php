<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContactModel;
use App\Models\OrganizationModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Support\Config;
use App\Support\Database;
use DateTimeImmutable;

/**
 * The rules for tasks, in one place. Every way a task gets written (the
 * web forms, the Slack bot, an approved agent proposal) validates through
 * here, so the surfaces cannot drift apart on what a valid task is.
 *
 * Tasks never touch the follow-up engine: nothing here reads or moves a
 * contact's touch dates, by design (docs/SCHEMA.md, tasks).
 */
final class TaskService
{
    public const MAX_TITLE = 255;
    public const MAX_NOTES = 5000;

    /** The fields a caller may set. */
    public const FIELDS = ['title', 'notes', 'due_date', 'owner_id', 'contact_id', 'organization_id'];

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
    ) {
    }

    /**
     * Validate and normalize task input into the full field set TaskModel
     * stores. On create ($existing null) a missing field is empty; on edit
     * a missing field keeps its current value, so callers can send only
     * what changed.
     *
     * owner_id is already a user id or null (shared) by the time it gets
     * here; the words 'me' / 'shared' / an email are OwnerResolver's job.
     *
     * A task links to at most one record. Setting a contact on a task that
     * was about an organization (or the reverse) replaces the old link;
     * asking for both at once is an error.
     *
     * @param array<string, mixed> $input any of self::FIELDS
     * @param array<string, mixed>|null $existing the task row being edited
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     *         [clean values for every field, errors keyed by field]
     */
    public function validate(array $input, ?array $existing = null): array
    {
        $errors = [];
        $clean = [];

        foreach (self::FIELDS as $field) {
            $clean[$field] = array_key_exists($field, $input)
                ? $input[$field]
                : ($existing[$field] ?? null);
        }

        $clean['title'] = trim((string) preg_replace('/\s+/u', ' ', (string) ($clean['title'] ?? '')));

        if ($clean['title'] === '') {
            $errors['title'] = 'A task needs a title.';
        } elseif (mb_strlen($clean['title']) > self::MAX_TITLE) {
            $errors['title'] = sprintf('Titles can be at most %d characters.', self::MAX_TITLE);
        }

        $notes = trim((string) ($clean['notes'] ?? ''));
        $clean['notes'] = $notes === '' ? null : $notes;

        if ($notes !== '' && mb_strlen($notes) > self::MAX_NOTES) {
            $errors['notes'] = sprintf('Notes can be at most %d characters.', self::MAX_NOTES);
        }

        $due = trim((string) ($clean['due_date'] ?? ''));
        $clean['due_date'] = $due === '' ? null : $due;

        if ($due !== '' && !self::isValidDate($due)) {
            $errors['due_date'] = 'The due date must be a real date (YYYY-MM-DD).';
        }

        $clean['owner_id'] = self::nullableId($clean['owner_id']);

        if ($clean['owner_id'] !== null && !in_array($clean['owner_id'], $this->assignableOwnerIds(), true)) {
            $errors['owner_id'] = 'Pick a valid owner.';
        }

        $clean['contact_id'] = self::nullableId($clean['contact_id']);
        $clean['organization_id'] = self::nullableId($clean['organization_id']);

        if ($clean['contact_id'] !== null && $clean['organization_id'] !== null) {
            $asksForContact = self::nullableId($input['contact_id'] ?? null) !== null;
            $asksForOrganization = self::nullableId($input['organization_id'] ?? null) !== null;

            if ($asksForContact && $asksForOrganization) {
                $errors['contact_id'] = 'Link a task to a contact or an organization, not both.'
                    . ' A contact already brings its organization along.';
            } elseif ($asksForContact) {
                $clean['organization_id'] = null;
            } else {
                $clean['contact_id'] = null;
            }
        }

        // Existence is checked only for a link being set or kept visible:
        // ContactModel::find() hides trashed contacts, which is exactly the
        // rule wanted here (no new task about someone in the trash).
        $contactChanged = $clean['contact_id'] !== self::nullableId($existing['contact_id'] ?? null);

        if (
            $clean['contact_id'] !== null
            && $contactChanged
            && (new ContactModel($this->db))->find($clean['contact_id']) === null
        ) {
            $errors['contact_id'] = 'That contact does not exist (or is in the trash).';
        }

        $organizationChanged = $clean['organization_id'] !== self::nullableId($existing['organization_id'] ?? null);

        if (
            $clean['organization_id'] !== null
            && $organizationChanged
            && (new OrganizationModel($this->db))->find($clean['organization_id']) === null
        ) {
            $errors['organization_id'] = 'That organization does not exist.';
        }

        return [$clean, $errors];
    }

    /**
     * @param array<string, mixed> $clean validate() output
     */
    public function create(array $clean, int $actorId): int
    {
        return (new TaskModel($this->db))->create($clean, $actorId);
    }

    /**
     * @param array<string, mixed> $clean validate() output
     */
    public function update(int $taskId, array $clean, int $actorId): void
    {
        (new TaskModel($this->db))->update($taskId, $clean, $actorId);
    }

    /**
     * Tick a task. Returns whether this call changed it (false when it was
     * already done, or does not exist).
     */
    public function complete(int $taskId, int $actorId): bool
    {
        return (new TaskModel($this->db))->setCompleted($taskId, true, $actorId);
    }

    public function reopen(int $taskId, int $actorId): bool
    {
        return (new TaskModel($this->db))->setCompleted($taskId, false, $actorId);
    }

    /**
     * Who a task may be assigned to: the same people a contact may be
     * (those who can sign in), so retired identities are never offered.
     *
     * @return list<array<string, mixed>>
     */
    public function assignableOwners(): array
    {
        return (new UserModel($this->db))->assignable($this->config->allowedEmails);
    }

    /**
     * @return list<int>
     */
    private function assignableOwnerIds(): array
    {
        return array_map(static fn(array $u): int => (int) $u['id'], $this->assignableOwners());
    }

    private static function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : -1;
    }

    private static function isValidDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
