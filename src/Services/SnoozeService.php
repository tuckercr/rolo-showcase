<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Support\Database;

/**
 * Moving a contact's follow-up date — the one place every reschedule goes
 * through: the web snooze dropdown, the manual date editor, /rolo snooze,
 * the debrief's Snooze button and its Undo. The "Modified follow up date
 * from: X, to: Y" trace is load-bearing (it is what the history timeline
 * shows), so its format must not drift between surfaces.
 */
final class SnoozeService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Set (or clear, with null) the next follow-up and leave the history
     * trace. last_touch is deliberately untouched — a snooze is not a touch.
     *
     * @param array<string, mixed> $contact the contact row being moved
     * @param string $today Y-m-d in APP_TIMEZONE (the caller's todayLocal())
     * @param string $note appended to the trace in parentheses, e.g.
     *        "via /rolo snooze"; empty for the web UI
     */
    public function reschedule(
        array $contact,
        ?string $newDate,
        int $actorId,
        string $today,
        string $note = '',
    ): void {
        $contactId = (int) $contact['id'];
        $oldDate = (string) ($contact['next_touch_date'] ?? '');

        (new ContactModel($this->db))->snooze($contactId, $newDate, $actorId);

        (new ActivityModel($this->db))->create(
            contactId: $contactId,
            organizationId: $contact['organization_id'] === null ? null : (int) $contact['organization_id'],
            activityType: 'snoozed',
            activityDate: $today,
            summary: sprintf(
                'Modified follow up date from: %s, to: %s%s',
                $oldDate === '' ? 'none' : $oldDate,
                $newDate ?? 'none',
                $note !== '' ? ' (' . $note . ')' : '',
            ),
            followUpNeeded: false,
            followUpDate: null,
            userId: $actorId,
        );
    }
}
