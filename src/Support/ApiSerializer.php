<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared object shapes for everything agent-facing: the REST API
 * (docs/API.md) and the MCP endpoint serve identical JSON for the same
 * entities, so the mapping lives once, here.
 */
final class ApiSerializer
{
    /**
     * @param array<string, mixed> $c row from ContactModel (BASE_SELECT shape)
     * @param list<string> $tags
     * @return array<string, mixed>
     */
    public static function contact(array $c, array $tags = []): array
    {
        return [
            'id' => (int) $c['id'],
            'name' => (string) $c['name'],
            'title' => $c['title'],
            'organization' => $c['organization_id'] === null ? null : [
                'id' => (int) $c['organization_id'],
                'name' => $c['organization_name'],
            ],
            'stage' => (string) $c['relationship_status'],
            'relationship_type' => $c['relationship_type'],
            'email' => $c['email'],
            'phone' => $c['phone'],
            'linkedin_url' => $c['linkedin_url'],
            'human_detail' => $c['human_detail'],
            'last_touch_date' => $c['last_touch_date'],
            'next_touch_date' => $c['next_touch_date'],
            'follow_up_note' => $c['follow_up_note'] ?? null,
            'cadence_days_override' => $c['cadence_days_override'] === null
                ? null
                : (int) $c['cadence_days_override'],
            'tags' => $tags,
            'created_by' => $c['created_by_name'] ?? null,
            'updated_by' => $c['updated_by_name'] ?? null,
            'created_at' => ApiFormat::isoDateTime($c['created_at'] ?? null),
            'updated_at' => ApiFormat::isoDateTime($c['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    public static function organization(array $o): array
    {
        return [
            'id' => (int) $o['id'],
            'name' => (string) $o['name'],
            'type' => (string) $o['org_type'],
            'website' => $o['website'],
            'linkedin_url' => $o['linkedin_url'],
            'notes' => $o['notes'],
            'contact_count' => isset($o['contact_count']) ? (int) $o['contact_count'] : null,
            'created_at' => ApiFormat::isoDateTime($o['created_at'] ?? null),
            'updated_at' => ApiFormat::isoDateTime($o['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    public static function activity(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'contact_id' => (int) $a['contact_id'],
            'contact_name' => $a['contact_name'] ?? null,
            'type' => (string) $a['activity_type'],
            'date' => (string) $a['activity_date'],
            'summary' => $a['summary'],
            'follow_up_needed' => (int) ($a['follow_up_needed'] ?? 0) === 1,
            'follow_up_date' => $a['follow_up_date'],
            'follow_up_note' => $a['follow_up_note'] ?? null,
            'attachment_count' => isset($a['attachment_count']) ? (int) $a['attachment_count'] : null,
            'logged_by' => $a['created_by_name'] ?? null,
            'created_at' => ApiFormat::isoDateTime($a['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $p api_proposals row
     * @return array<string, mixed>
     */
    public static function proposal(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'action' => (string) $p['action'],
            'status' => (string) $p['status'],
            'summary' => (string) $p['summary'],
            'error' => $p['error'],
            'created_at' => ApiFormat::isoDateTime($p['created_at'] ?? null),
            'reviewed_at' => ApiFormat::isoDateTime($p['reviewed_at'] ?? null),
        ];
    }
}
