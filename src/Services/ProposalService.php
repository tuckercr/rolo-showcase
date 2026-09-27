<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\OrganizationModel;
use App\Models\ProposalModel;
use App\Models\ReminderRuleModel;
use App\Models\StageModel;
use App\Models\TagModel;
use App\Support\Config;
use App\Support\Database;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * The propose/review/commit pipeline for agent writes (integration spec):
 * validate() runs when an agent submits a proposal, apply() runs when a
 * human approves it at /proposals. Applied changes are attributed to the
 * approving user; the proposing token stays on the proposal row.
 */
final class ProposalService
{
    public const ACTIONS = [
        'create_contact',
        'update_contact',
        'change_tags',
        'log_activity',
        'create_organization',
    ];

    private const UPDATABLE_FIELDS = [
        'name',
        'title',
        'stage',
        'relationship_type',
        'organization_id',
        'email',
        'phone',
        'linkedin_url',
        'human_detail',
        'cadence_days_override',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
    ) {
    }

    /**
     * Validate and normalize an agent payload. Returns the payload to store
     * plus a human-readable summary for the review screen.
     *
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: string}
     * @throws InvalidArgumentException with an agent-presentable message
     */
    public function validate(string $action, array $payload): array
    {
        return match ($action) {
            'create_contact' => $this->validateCreateContact($payload),
            'update_contact' => $this->validateUpdateContact($payload),
            'change_tags' => $this->validateChangeTags($payload),
            'log_activity' => $this->validateLogActivity($payload),
            'create_organization' => $this->validateCreateOrganization($payload),
            default => throw new InvalidArgumentException('Unknown action.'),
        };
    }

    /**
     * Queue a proposal from an agent payload (shared by the REST write
     * endpoints and the MCP tools). Handles idempotency-key dedupe and
     * validation; returns the proposal row plus whether it already existed.
     *
     * @param array<string, mixed> $payload may contain idempotency_key
     * @return array{0: array<string, mixed>, 1: bool} [proposal row, isNew]
     * @throws InvalidArgumentException with an agent-presentable message
     */
    public function queue(
        string $tokenName,
        string $action,
        array $payload,
        ?string $fallbackIdempotencyKey = null,
    ): array {
        $proposals = new ProposalModel($this->db);

        $key = trim((string) ($payload['idempotency_key'] ?? $fallbackIdempotencyKey ?? ''));
        $key = $key === '' ? null : mb_substr($key, 0, 100);
        unset($payload['idempotency_key']);

        if ($key !== null) {
            $existing = $proposals->findByIdempotencyKey($key);

            if ($existing !== null) {
                return [$existing, false];
            }
        }

        [$clean, $summary] = $this->validate($action, $payload);

        $id = $proposals->create($tokenName, $action, $clean, $summary, $key);

        return [(array) $proposals->find($id), true];
    }

    /**
     * Apply an approved proposal. Throws on failure; the caller records the
     * outcome on the proposal row.
     *
     * @param array<string, mixed> $proposal api_proposals row
     */
    public function apply(array $proposal, int $reviewerId): void
    {
        $payload = json_decode((string) $proposal['payload'], true);

        if (!is_array($payload)) {
            throw new RuntimeException('Stored payload is not valid JSON.');
        }

        match ((string) $proposal['action']) {
            'create_contact' => $this->applyCreateContact($payload, $reviewerId),
            'update_contact' => $this->applyUpdateContact($payload, $reviewerId),
            'change_tags' => $this->applyChangeTags($payload),
            'log_activity' => $this->applyLogActivity($payload, $reviewerId),
            'create_organization' => $this->applyCreateOrganization($payload),
            default => throw new RuntimeException('Unknown action.'),
        };
    }

    // ---- create_contact ----------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function validateCreateContact(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('name is required (max 255 characters).');
        }

        $stage = trim((string) ($payload['stage'] ?? 'New/Captured'));

        if (!(new StageModel($this->db))->exists($stage)) {
            throw new InvalidArgumentException(sprintf('Unknown stage "%s" (see /api/v1/stages).', $stage));
        }

        $clean = ['name' => $name, 'stage' => $stage]
            + $this->validateOptionalContactFields($payload)
            + ['tags' => $this->validateTagList($payload['tags'] ?? [])];

        $summary = sprintf('Create contact "%s" (stage: %s)', $name, $stage);

        if ($clean['tags'] !== []) {
            $summary .= ', tags: ' . implode(', ', $clean['tags']);
        }

        return [$clean, $summary];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCreateContact(array $payload, int $reviewerId): void
    {
        $stage = (string) $payload['stage'];

        $rules = (new ReminderRuleModel($this->db))->activeByStatus();
        $next = (new ReminderService($rules))->nextTouchDate(
            $stage,
            new DateTimeImmutable('today', $this->config->appTimezone),
        );

        $contactId = (new ContactModel($this->db))->create([
            'name' => $payload['name'],
            'organization_id' => $payload['organization_id'] ?? null,
            'title' => $payload['title'] ?? null,
            'relationship_status' => $stage,
            'relationship_type' => $payload['relationship_type'] ?? null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'linkedin_url' => $payload['linkedin_url'] ?? null,
            'cadence_days_override' => $payload['cadence_days_override'] ?? null,
            'human_detail' => $payload['human_detail'] ?? null,
            'next_touch_date' => $next?->format('Y-m-d'),
        ], $reviewerId);

        if (($payload['tags'] ?? []) !== []) {
            (new TagModel($this->db))->addForContact($contactId, $payload['tags']);
        }
    }

    // ---- update_contact ----------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function validateUpdateContact(array $payload): array
    {
        $contact = $this->requireContact($payload);

        $changes = array_intersect_key($payload, array_flip(self::UPDATABLE_FIELDS));

        if ($changes === []) {
            throw new InvalidArgumentException(
                'No updatable fields given (' . implode(', ', self::UPDATABLE_FIELDS) . ').'
            );
        }

        if (array_key_exists('name', $changes)) {
            $changes['name'] = trim((string) $changes['name']);

            if ($changes['name'] === '' || mb_strlen($changes['name']) > 255) {
                throw new InvalidArgumentException('name must be 1-255 characters.');
            }
        }

        if (array_key_exists('stage', $changes)) {
            $changes['stage'] = trim((string) $changes['stage']);

            if (!(new StageModel($this->db))->exists($changes['stage'])) {
                throw new InvalidArgumentException(sprintf('Unknown stage "%s".', $changes['stage']));
            }
        }

        $changes = $this->validateOptionalContactFields($changes) + $changes;

        $clean = ['contact_id' => (int) $contact['id']] + $changes;

        return [$clean, sprintf(
            'Update %s: %s',
            (string) $contact['name'],
            implode(', ', array_keys($changes)),
        )];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyUpdateContact(array $payload, int $reviewerId): void
    {
        $contacts = new ContactModel($this->db);
        $contact = $contacts->find((int) $payload['contact_id']);

        if ($contact === null) {
            throw new RuntimeException('Contact no longer exists (trashed?).');
        }

        $merged = [
            'name' => $payload['name'] ?? $contact['name'],
            'organization_id' => array_key_exists('organization_id', $payload)
                ? $payload['organization_id']
                : $contact['organization_id'],
            'title' => $payload['title'] ?? $contact['title'],
            'relationship_status' => $payload['stage'] ?? $contact['relationship_status'],
            'relationship_type' => $payload['relationship_type'] ?? $contact['relationship_type'],
            'email' => $payload['email'] ?? $contact['email'],
            'phone' => $payload['phone'] ?? $contact['phone'],
            'linkedin_url' => $payload['linkedin_url'] ?? $contact['linkedin_url'],
            'cadence_days_override' => array_key_exists('cadence_days_override', $payload)
                ? $payload['cadence_days_override']
                : $contact['cadence_days_override'],
            'human_detail' => $payload['human_detail'] ?? $contact['human_detail'],
            'next_touch_date' => $contact['next_touch_date'],
        ];

        // Status-triggered defaults, same as the UI edit flow: a stage or
        // cadence-override change recomputes the next touch from today.
        $stageChanged = $merged['relationship_status'] !== $contact['relationship_status'];
        $overrideChanged = $merged['cadence_days_override'] !== $contact['cadence_days_override'];

        if ($stageChanged || $overrideChanged) {
            $rules = (new ReminderRuleModel($this->db))->activeByStatus();
            $lastTouch = $contact['last_touch_date'] === null
                ? null
                : new DateTimeImmutable((string) $contact['last_touch_date']);

            $next = (new ReminderService($rules))->nextTouchDate(
                (string) $merged['relationship_status'],
                new DateTimeImmutable('today', $this->config->appTimezone),
                $lastTouch,
                $merged['cadence_days_override'] === null
                    ? null
                    : (int) $merged['cadence_days_override'],
            );
            $merged['next_touch_date'] = $next?->format('Y-m-d');
        }

        $contacts->update((int) $contact['id'], $merged, $reviewerId);
    }

    // ---- change_tags -------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function validateChangeTags(array $payload): array
    {
        $contact = $this->requireContact($payload);

        $add = $this->validateTagList($payload['add'] ?? []);
        $remove = $this->validateTagList($payload['remove'] ?? []);

        if ($add === [] && $remove === []) {
            throw new InvalidArgumentException('Provide add[] and/or remove[] tag lists.');
        }

        $parts = array_merge(
            array_map(static fn(string $t): string => '+' . $t, $add),
            array_map(static fn(string $t): string => '-' . $t, $remove),
        );

        return [
            ['contact_id' => (int) $contact['id'], 'add' => $add, 'remove' => $remove],
            sprintf('Tags for %s: %s', (string) $contact['name'], implode(', ', $parts)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyChangeTags(array $payload): void
    {
        $tags = new TagModel($this->db);
        $contactId = (int) $payload['contact_id'];

        $tags->addForContact($contactId, $payload['add'] ?? []);
        $tags->removeForContact($contactId, $payload['remove'] ?? []);
    }

    // ---- log_activity ------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function validateLogActivity(array $payload): array
    {
        $contact = $this->requireContact($payload);

        $type = trim((string) ($payload['type'] ?? ''));

        if (!in_array($type, ActivityModel::API_LOGGABLE_TYPES, true)) {
            throw new InvalidArgumentException(
                'type must be one of: ' . implode(', ', ActivityModel::API_LOGGABLE_TYPES) . '.'
            );
        }

        $date = trim((string) ($payload['date'] ?? ''));

        if (!$this->isValidDate($date)) {
            throw new InvalidArgumentException('date must be YYYY-MM-DD.');
        }

        $summary = trim((string) ($payload['summary'] ?? ''));

        if ($summary === '') {
            throw new InvalidArgumentException('summary is required.');
        }

        $countsAsTouch = (bool) ($payload['counts_as_touch'] ?? false);

        $followUpDate = trim((string) ($payload['follow_up_date'] ?? ''));

        if ($followUpDate !== '' && !$this->isValidDate($followUpDate)) {
            throw new InvalidArgumentException('follow_up_date must be YYYY-MM-DD.');
        }

        $followUpNote = trim((string) ($payload['follow_up_note'] ?? ''));
        $followUpNote = $followUpNote === '' ? null : mb_substr($followUpNote, 0, 255);

        $text = sprintf(
            'Log %s for %s on %s%s',
            $type,
            (string) $contact['name'],
            $date,
            $countsAsTouch ? ' (advances follow-up)' : '',
        );

        if ($followUpNote !== null) {
            $text .= sprintf(', follow-up: "%s"', $followUpNote);
        }

        return [
            [
                'contact_id' => (int) $contact['id'],
                'type' => $type,
                'date' => $date,
                'summary' => $summary,
                'counts_as_touch' => $countsAsTouch,
                'follow_up_date' => $followUpDate === '' ? null : $followUpDate,
                'follow_up_note' => $followUpNote,
            ],
            $text,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyLogActivity(array $payload, int $reviewerId): void
    {
        $contacts = new ContactModel($this->db);
        $contact = $contacts->find((int) $payload['contact_id']);

        if ($contact === null) {
            throw new RuntimeException('Contact no longer exists (trashed?).');
        }

        $followUpDate = isset($payload['follow_up_date']) ? (string) $payload['follow_up_date'] : null;
        $followUpNote = isset($payload['follow_up_note']) ? (string) $payload['follow_up_note'] : null;

        (new ActivityModel($this->db))->create(
            contactId: (int) $contact['id'],
            organizationId: $contact['organization_id'] === null ? null : (int) $contact['organization_id'],
            activityType: (string) $payload['type'],
            activityDate: (string) $payload['date'],
            summary: (string) $payload['summary'],
            followUpNeeded: $followUpDate !== null || $followUpNote !== null,
            followUpDate: $followUpDate,
            userId: $reviewerId,
            followUpNote: $followUpNote,
        );

        if (($payload['counts_as_touch'] ?? false) !== true) {
            return;
        }

        $currentLastTouch = (string) ($contact['last_touch_date'] ?? '');

        // A backdated touch approved after a newer one must not reschedule:
        // fixed_days/business_days rules compute from the activity date, so
        // recomputing here would drag the follow-up backward (possibly into
        // the past). The newer touch's schedule stands; only the history
        // row above is added.
        if (!self::advancesLastTouch($currentLastTouch, (string) $payload['date'])) {
            return;
        }

        // Same scheduling rule as the UI log flow: an explicit follow-up
        // date is a human decision and beats the cadence; otherwise the
        // rules engine computes from the new last touch.
        $newLastTouch = max($currentLastTouch, (string) $payload['date']);

        if ($followUpDate !== null) {
            $contacts->recordTouch((int) $contact['id'], $newLastTouch, $followUpDate, $reviewerId);

            return;
        }

        $rules = (new ReminderRuleModel($this->db))->activeByStatus();
        $next = (new ReminderService($rules))->nextTouchDate(
            (string) $contact['relationship_status'],
            new DateTimeImmutable((string) $payload['date']),
            new DateTimeImmutable($newLastTouch),
            $contact['cadence_days_override'] === null
                ? null
                : (int) $contact['cadence_days_override'],
        );

        $contacts->recordTouch((int) $contact['id'], $newLastTouch, $next?->format('Y-m-d'), $reviewerId);
    }

    /**
     * Whether an activity on $activityDate moves the contact's last touch
     * forward (and so should reschedule the next one). Pure so it is
     * unit-testable; see McpProtocolTest's sibling ProposalServiceTest.
     */
    public static function advancesLastTouch(?string $currentLastTouch, string $activityDate): bool
    {
        return $currentLastTouch === null
            || $currentLastTouch === ''
            || $activityDate >= $currentLastTouch;
    }

    // ---- create_organization -------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function validateCreateOrganization(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('name is required (max 255 characters).');
        }

        $existing = (new OrganizationModel($this->db))->findByName($name);

        if ($existing !== null) {
            throw new InvalidArgumentException(sprintf(
                'An organization named "%s" already exists (id %d).',
                $name,
                (int) $existing['id'],
            ));
        }

        $type = trim((string) ($payload['type'] ?? 'other'));

        if (!array_key_exists($type, OrganizationModel::ORG_TYPES)) {
            throw new InvalidArgumentException(
                'type must be one of: ' . implode(', ', array_keys(OrganizationModel::ORG_TYPES)) . '.'
            );
        }

        $clean = ['name' => $name, 'type' => $type];

        foreach (['website', 'linkedin_url'] as $field) {
            if (array_key_exists($field, $payload)) {
                $url = trim((string) $payload[$field]);

                if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
                    throw new InvalidArgumentException($field . ' must be a full URL.');
                }

                $clean[$field] = $url === '' ? null : $url;
            }
        }

        if (array_key_exists('notes', $payload)) {
            $notes = trim((string) $payload['notes']);
            $clean['notes'] = $notes === '' ? null : mb_substr($notes, 0, 2000);
        }

        return [$clean, sprintf(
            'Create organization "%s" (%s)',
            $name,
            OrganizationModel::ORG_TYPES[$type],
        )];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCreateOrganization(array $payload): void
    {
        $organizations = new OrganizationModel($this->db);

        // Re-check at apply time: a duplicate may have appeared since the
        // proposal was queued (or a twin proposal was approved first).
        if ($organizations->findByName((string) $payload['name']) !== null) {
            throw new RuntimeException(sprintf(
                'An organization named "%s" already exists.',
                (string) $payload['name'],
            ));
        }

        $organizations->create([
            'name' => $payload['name'],
            'org_type' => $payload['type'],
            'website' => $payload['website'] ?? null,
            'linkedin_url' => $payload['linkedin_url'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);
    }

    // ---- human-readable review cards (approvals page) ----------------------

    private const FIELD_LABELS = [
        'name' => 'Name',
        'stage' => 'Pipeline stage',
        'title' => 'Title',
        'relationship_type' => 'Relationship type',
        'email' => 'Email',
        'phone' => 'Phone',
        'linkedin_url' => 'LinkedIn',
        'human_detail' => 'Human detail',
        'organization_id' => 'Organization',
        'cadence_days_override' => 'Cadence override',
        'type' => 'Type',
        'website' => 'Website',
        'notes' => 'Notes',
    ];

    private const CONTACT_COLUMNS = ['stage' => 'relationship_status'];

    /**
     * Build the view model for one pending proposal: what changes, shown
     * against the LIVE current values (so a review after the world moved
     * shows what would really be overwritten), plus a plain-language
     * consequence line for the follow-up schedule. Never throws; a broken
     * payload degrades to kind "unknown" and the stored summary.
     *
     * @param array<string, mixed> $proposal api_proposals row
     * @return array<string, mixed>
     */
    public function describe(array $proposal): array
    {
        try {
            $payload = json_decode((string) $proposal['payload'], true);

            if (!is_array($payload)) {
                return ['kind' => 'unknown'];
            }

            return match ((string) $proposal['action']) {
                'create_contact' => $this->describeCreateContact($payload),
                'update_contact' => $this->describeUpdateContact($payload),
                'change_tags' => $this->describeChangeTags($payload),
                'log_activity' => $this->describeLogActivity($payload),
                'create_organization' => $this->describeCreateOrganization($payload),
                default => ['kind' => 'unknown'],
            };
        } catch (\Throwable) {
            return ['kind' => 'unknown'];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function describeCreateContact(array $payload): array
    {
        $rows = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            if (array_key_exists($field, $payload) && $field !== 'name' && $payload[$field] !== null) {
                $rows[] = [
                    'label' => $label,
                    'before' => null,
                    'after' => $this->formatValue($field, $payload[$field]),
                ];
            }
        }

        return [
            'kind' => 'create_contact',
            'title' => 'New contact: ' . (string) $payload['name'],
            'contact' => null,
            'rows' => $rows,
            'tags_add' => $payload['tags'] ?? [],
            'consequence' => $this->firstFollowUpLine(
                (string) $payload['stage'],
                isset($payload['cadence_days_override']) ? (int) $payload['cadence_days_override'] : null,
            ),
            'consequence_level' => 'info',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function describeUpdateContact(array $payload): array
    {
        $contact = (new ContactModel($this->db))->find((int) $payload['contact_id']);

        if ($contact === null) {
            return [
                'kind' => 'missing_target',
                'title' => 'Update contact',
                'consequence' => 'This contact has since been deleted;'
                    . ' approving will fail and the proposal will be marked rejected.',
                'consequence_level' => 'warning',
            ];
        }

        $rows = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            if (!array_key_exists($field, $payload) || in_array($field, ['type', 'website', 'notes'], true)) {
                continue;
            }

            $column = self::CONTACT_COLUMNS[$field] ?? $field;
            $before = $this->formatValue($field, $contact[$column] ?? null);
            $after = $this->formatValue($field, $payload[$field]);
            $rows[] = [
                'label' => $label,
                'before' => $before,
                'after' => $after,
                'changed' => $before !== $after,
            ];
        }

        $consequence = null;

        if (array_key_exists('stage', $payload) || array_key_exists('cadence_days_override', $payload)) {
            $stage = (string) ($payload['stage'] ?? $contact['relationship_status']);
            $override = array_key_exists('cadence_days_override', $payload)
                ? ($payload['cadence_days_override'] === null ? null : (int) $payload['cadence_days_override'])
                : ($contact['cadence_days_override'] === null ? null : (int) $contact['cadence_days_override']);

            $stageChanged = $stage !== (string) $contact['relationship_status'];
            $overrideChanged = array_key_exists('cadence_days_override', $payload)
                && $payload['cadence_days_override'] !== $contact['cadence_days_override'];

            if ($stageChanged || $overrideChanged) {
                $next = $this->previewNextTouch(
                    $stage,
                    $override,
                    $contact['last_touch_date'] === null ? null : (string) $contact['last_touch_date'],
                );
                $consequence = $next === null
                    ? 'This change removes the follow-up reminder (the new stage has no reminder rule).'
                    : sprintf('This change reschedules the next follow-up to %s.', $next);
            }
        }

        return [
            'kind' => 'update_contact',
            'title' => 'Update contact',
            'contact' => ['id' => (int) $contact['id'], 'name' => (string) $contact['name']],
            'rows' => $rows,
            'consequence' => $consequence,
            'consequence_level' => 'warning',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function describeChangeTags(array $payload): array
    {
        $contact = (new ContactModel($this->db))->find((int) $payload['contact_id']);

        if ($contact === null) {
            return [
                'kind' => 'missing_target',
                'title' => 'Change tags',
                'consequence' => 'This contact has since been deleted;'
                    . ' approving will fail and the proposal will be marked rejected.',
                'consequence_level' => 'warning',
            ];
        }

        return [
            'kind' => 'change_tags',
            'title' => 'Change tags',
            'contact' => ['id' => (int) $contact['id'], 'name' => (string) $contact['name']],
            'tags_add' => $payload['add'] ?? [],
            'tags_remove' => $payload['remove'] ?? [],
            'tags_current' => (new TagModel($this->db))->namesForContact((int) $contact['id']),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function describeLogActivity(array $payload): array
    {
        $contact = (new ContactModel($this->db))->find((int) $payload['contact_id']);

        if ($contact === null) {
            return [
                'kind' => 'missing_target',
                'title' => 'Log an interaction',
                'consequence' => 'This contact has since been deleted;'
                    . ' approving will fail and the proposal will be marked rejected.',
                'consequence_level' => 'warning',
            ];
        }

        $date = (string) $payload['date'];
        $countsAsTouch = ($payload['counts_as_touch'] ?? false) === true;
        $currentLastTouch = $contact['last_touch_date'] === null ? '' : (string) $contact['last_touch_date'];
        $explicitFollowUp = isset($payload['follow_up_date']) ? (string) $payload['follow_up_date'] : null;

        if (!$countsAsTouch) {
            $consequence = 'Recorded in the history only; the follow-up schedule is not touched.';
            $level = 'info';
        } elseif (!self::advancesLastTouch($currentLastTouch, $date)) {
            $consequence = sprintf(
                'Older than the last recorded touch (%s), so it goes into the history without changing the schedule.',
                $currentLastTouch,
            );
            $level = 'info';
        } elseif ($explicitFollowUp !== null) {
            $consequence = sprintf(
                'Sets the last touch to %s and the next follow-up to %s (the explicit date wins over the cadence).',
                $date,
                $explicitFollowUp,
            );
            $level = 'warning';
        } else {
            $next = $this->previewNextTouch(
                (string) $contact['relationship_status'],
                $contact['cadence_days_override'] === null ? null : (int) $contact['cadence_days_override'],
                $date,
                $date,
            );
            $consequence = $next === null
                ? sprintf(
                    'Sets the last touch to %s; this stage has no reminder rule, so no follow-up is scheduled.',
                    $date,
                )
                : sprintf('Sets the last touch to %s and the next follow-up to %s.', $date, $next);
            $level = 'warning';
        }

        return [
            'kind' => 'log_activity',
            'title' => 'Log an interaction',
            'contact' => ['id' => (int) $contact['id'], 'name' => (string) $contact['name']],
            'activity' => [
                'type_label' => ActivityModel::DISPLAY_TYPES[(string) $payload['type']] ?? (string) $payload['type'],
                'date' => $date,
                'summary' => (string) $payload['summary'],
                'follow_up_date' => $explicitFollowUp,
                'follow_up_note' => isset($payload['follow_up_note']) ? (string) $payload['follow_up_note'] : null,
            ],
            'consequence' => $consequence,
            'consequence_level' => $level,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function describeCreateOrganization(array $payload): array
    {
        $rows = [];

        foreach (['type', 'website', 'linkedin_url', 'notes'] as $field) {
            if (($payload[$field] ?? null) !== null) {
                $rows[] = [
                    'label' => self::FIELD_LABELS[$field],
                    'before' => null,
                    'after' => $this->formatValue($field, $payload[$field]),
                ];
            }
        }

        return [
            'kind' => 'create_organization',
            'title' => 'New organization: ' . (string) $payload['name'],
            'contact' => null,
            'rows' => $rows,
        ];
    }

    private function formatValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }

        if ($field === 'organization_id') {
            $org = (new OrganizationModel($this->db))->find((int) $value);

            return $org === null ? sprintf('organization #%d', (int) $value) : (string) $org['name'];
        }

        if ($field === 'cadence_days_override') {
            return sprintf('every %d days', (int) $value);
        }

        if ($field === 'type') {
            return OrganizationModel::ORG_TYPES[(string) $value] ?? (string) $value;
        }

        if ($field === 'relationship_type') {
            return ucfirst(str_replace('_', ' / ', (string) $value));
        }

        return (string) $value;
    }

    private function firstFollowUpLine(string $stage, ?int $override): string
    {
        $next = $this->previewNextTouch($stage, $override, null);

        return $next === null
            ? sprintf('The %s stage has no reminder rule, so no follow-up will be scheduled.', $stage)
            : sprintf('The first follow-up will be scheduled for %s.', $next);
    }

    /**
     * The same computation apply() will run, done in advance for the review
     * card. $referenceDate defaults to today in the app timezone.
     */
    private function previewNextTouch(
        string $stage,
        ?int $override,
        ?string $lastTouch,
        ?string $referenceDate = null,
    ): ?string {
        $rules = (new ReminderRuleModel($this->db))->activeByStatus();
        $reference = $referenceDate === null
            ? new DateTimeImmutable('today', $this->config->appTimezone)
            : new DateTimeImmutable($referenceDate);

        $next = (new ReminderService($rules))->nextTouchDate(
            $stage,
            $reference,
            $lastTouch === null ? null : new DateTimeImmutable($lastTouch),
            $override,
        );

        return $next?->format('Y-m-d');
    }

    // ---- shared helpers ----------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the contact row
     */
    private function requireContact(array $payload): array
    {
        $contact = (new ContactModel($this->db))->find((int) ($payload['contact_id'] ?? 0));

        if ($contact === null) {
            throw new InvalidArgumentException('Unknown contact_id.');
        }

        return $contact;
    }

    /**
     * Shared optional-field validation for create/update payloads.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> only the keys that were present
     */
    private function validateOptionalContactFields(array $payload): array
    {
        $out = [];

        foreach (['title', 'human_detail'] as $field) {
            if (array_key_exists($field, $payload)) {
                $value = trim((string) $payload[$field]);
                $out[$field] = $value === '' ? null : mb_substr($value, 0, $field === 'title' ? 255 : 500);
            }
        }

        if (array_key_exists('email', $payload)) {
            $email = trim((string) $payload['email']);

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('email is not a valid address.');
            }

            $out['email'] = $email === '' ? null : $email;
        }

        if (array_key_exists('phone', $payload)) {
            $phone = trim((string) $payload['phone']);

            if ($phone !== '' && preg_match('/^[0-9+\-().\s]{3,50}$/', $phone) !== 1) {
                throw new InvalidArgumentException('phone does not look like a phone number.');
            }

            $out['phone'] = $phone === '' ? null : $phone;
        }

        if (array_key_exists('linkedin_url', $payload)) {
            $url = trim((string) $payload['linkedin_url']);

            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('linkedin_url must be a full URL.');
            }

            $out['linkedin_url'] = $url === '' ? null : $url;
        }

        if (array_key_exists('relationship_type', $payload)) {
            $type = trim((string) $payload['relationship_type']);

            if ($type !== '' && !in_array($type, ContactModel::RELATIONSHIP_TYPES, true)) {
                throw new InvalidArgumentException(
                    'relationship_type must be one of: ' . implode(', ', ContactModel::RELATIONSHIP_TYPES) . '.'
                );
            }

            $out['relationship_type'] = $type === '' ? null : $type;
        }

        if (array_key_exists('organization_id', $payload)) {
            $orgId = $payload['organization_id'];

            if ($orgId !== null) {
                $orgId = (int) $orgId;

                if ((new OrganizationModel($this->db))->find($orgId) === null) {
                    throw new InvalidArgumentException('Unknown organization_id.');
                }
            }

            $out['organization_id'] = $orgId;
        }

        if (array_key_exists('cadence_days_override', $payload)) {
            $override = $payload['cadence_days_override'];

            if ($override !== null) {
                $override = (int) $override;

                if ($override < 1 || $override > 365) {
                    throw new InvalidArgumentException('cadence_days_override must be 1-365 or null.');
                }
            }

            $out['cadence_days_override'] = $override;
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function validateTagList(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Tags must be an array of strings.');
        }

        if (count($raw) > 10) {
            throw new InvalidArgumentException('At most 10 tags per call.');
        }

        $clean = [];

        foreach ($raw as $tag) {
            $name = trim((string) preg_replace('/\s+/', ' ', (string) $tag));

            if ($name === '') {
                continue;
            }

            if (mb_strlen($name) > TagModel::MAX_LENGTH) {
                throw new InvalidArgumentException(
                    sprintf('Tag "%s" is too long (max %d).', mb_substr($name, 0, 40), TagModel::MAX_LENGTH)
                );
            }

            $clean[mb_strtolower($name)] = $name;
        }

        return array_values($clean);
    }

    private function isValidDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
