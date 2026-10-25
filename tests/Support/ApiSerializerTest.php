<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\ApiSerializer;
use PHPUnit\Framework\TestCase;

/**
 * The serializer defines the JSON contract shared by the REST API and the
 * MCP endpoint (docs/API.md) — agents parse these shapes, so casts and
 * nullability are behavior, not cosmetics.
 */
final class ApiSerializerTest extends TestCase
{
    public function testContactWithOrganizationPhotoAndOverride(): void
    {
        $row = $this->contactRow([
            'organization_id' => '7',
            'organization_name' => 'Harbor Diagnostics',
            'photo' => 'ab12.jpg',
            'cadence_days_override' => '14',
            'follow_up_note' => 'Nudge about the pilot',
        ]);

        $out = ApiSerializer::contact($row, ['vip', 'conference']);

        self::assertSame(42, $out['id']);
        self::assertSame(['id' => 7, 'name' => 'Harbor Diagnostics'], $out['organization']);
        self::assertTrue($out['has_photo']);
        self::assertSame(14, $out['cadence_days_override']);
        self::assertSame('Nudge about the pilot', $out['follow_up_note']);
        self::assertSame(['vip', 'conference'], $out['tags']);
        self::assertSame('Conversation Started', $out['stage']);
        self::assertSame('Jessica', $out['created_by']);
    }

    public function testContactWithoutOrganizationOrPhoto(): void
    {
        $out = ApiSerializer::contact($this->contactRow());

        self::assertNull($out['organization']);
        self::assertFalse($out['has_photo']);
        self::assertNull($out['cadence_days_override']);
        self::assertNull($out['follow_up_note']);
        self::assertSame([], $out['tags']);
    }

    public function testActivityCastsFollowUpNeededToBool(): void
    {
        $base = [
            'id' => '3',
            'contact_id' => '42',
            'activity_type' => 'call',
            'activity_date' => '2026-10-20',
            'summary' => 'Quick check-in',
            'follow_up_date' => null,
        ];

        $needed = ApiSerializer::activity($base + ['follow_up_needed' => '1']);
        $notNeeded = ApiSerializer::activity($base + ['follow_up_needed' => '0']);

        self::assertTrue($needed['follow_up_needed']);
        self::assertFalse($notNeeded['follow_up_needed']);
        self::assertNull($needed['attachment_count'], 'absent count stays null, not 0');
        self::assertSame('call', $needed['type']);
    }

    public function testOrganizationContactCountIsNullWhenAbsent(): void
    {
        $row = [
            'id' => '7',
            'name' => 'Harbor Diagnostics',
            'org_type' => 'prospect',
            'website' => null,
            'linkedin_url' => null,
            'notes' => null,
        ];

        self::assertNull(ApiSerializer::organization($row)['contact_count']);
        self::assertSame(3, ApiSerializer::organization($row + ['contact_count' => '3'])['contact_count']);
    }

    public function testProposalShape(): void
    {
        $out = ApiSerializer::proposal([
            'id' => '9',
            'action' => 'log_activity',
            'status' => 'pending',
            'summary' => 'Log a call with Maria',
            'error' => null,
            'created_at' => '2026-10-20 14:00:00',
            'reviewed_at' => null,
        ]);

        self::assertSame(9, $out['id']);
        self::assertSame('log_activity', $out['action']);
        self::assertSame('pending', $out['status']);
        self::assertNull($out['error']);
        self::assertNull($out['reviewed_at']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function contactRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => '42',
            'name' => 'Maria Santos',
            'title' => null,
            'organization_id' => null,
            'organization_name' => null,
            'relationship_status' => 'Conversation Started',
            'relationship_type' => null,
            'email' => null,
            'phone' => null,
            'linkedin_url' => null,
            'human_detail' => null,
            'last_touch_date' => null,
            'next_touch_date' => '2026-10-24',
            'cadence_days_override' => null,
            'photo' => null,
            'created_by_name' => 'Jessica',
            'updated_by_name' => 'Jessica',
            'created_at' => '2026-08-01 12:00:00',
            'updated_at' => '2026-10-01 12:00:00',
        ];
    }
}
