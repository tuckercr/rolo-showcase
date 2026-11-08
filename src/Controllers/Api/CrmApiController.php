<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\OrganizationModel;
use App\Models\StageModel;
use App\Models\TagModel;
use App\Support\ApiFormat;
use App\Support\ApiSerializer;

/**
 * Read-only CRM endpoints for marketing agents (docs/API.md). Piece 1 of
 * the integration spec: reads only — write endpoints come later, behind
 * the approval-gate design.
 */
final class CrmApiController extends ApiController
{
    public function contactsIndex(): string
    {
        $this->requireToken('read');

        $limit = ApiFormat::clampLimit($_GET['limit'] ?? null);

        $rows = (new ContactModel($this->db))->apiList(
            query: trim((string) ($_GET['query'] ?? '')),
            stage: trim((string) ($_GET['stage'] ?? '')),
            updatedSince: trim((string) ($_GET['updated_since'] ?? '')),
            afterId: ApiFormat::decodeCursor(isset($_GET['cursor']) ? (string) $_GET['cursor'] : null),
            limit: $limit,
            tag: trim((string) ($_GET['tag'] ?? '')),
        );

        $tagsByContact = (new TagModel($this->db))->namesForContacts(
            array_map(static fn(array $c): int => (int) $c['id'], $rows),
        );

        return $this->respond([
            'data' => array_map(
                static fn(array $c): array => ApiSerializer::contact($c, $tagsByContact[(int) $c['id']] ?? []),
                $rows,
            ),
            'next_cursor' => count($rows) === $limit
                ? ApiFormat::encodeCursor((int) end($rows)['id'])
                : null,
        ]);
    }

    public function tagsIndex(): string
    {
        $this->requireToken('read');

        $tags = array_map(
            static fn(array $t): array => [
                'id' => (int) $t['id'],
                'name' => (string) $t['name'],
                'contact_count' => (int) $t['contact_count'],
            ],
            (new TagModel($this->db))->allWithCounts(),
        );

        return $this->respond(['data' => $tags]);
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function contactsShow(array $vars): string
    {
        $this->requireToken('read');

        $contact = (new ContactModel($this->db))->find((int) $vars['id']);

        if ($contact === null) {
            return $this->respondError(404, 'not_found', 'No contact with that id.');
        }

        $payload = ApiSerializer::contact(
            $contact,
            (new TagModel($this->db))->namesForContact((int) $contact['id']),
        );
        $payload['recent_activity'] = array_map(
            ApiSerializer::activity(...),
            array_slice((new ActivityModel($this->db))->forContact((int) $contact['id']), 0, 10),
        );

        return $this->respond(['data' => $payload]);
    }

    public function organizationsIndex(): string
    {
        $this->requireToken('read');

        $limit = ApiFormat::clampLimit($_GET['limit'] ?? null);

        $rows = (new OrganizationModel($this->db))->apiList(
            query: trim((string) ($_GET['query'] ?? '')),
            orgType: trim((string) ($_GET['type'] ?? '')),
            afterId: ApiFormat::decodeCursor(isset($_GET['cursor']) ? (string) $_GET['cursor'] : null),
            limit: $limit,
        );

        return $this->respond([
            'data' => array_map(ApiSerializer::organization(...), $rows),
            'next_cursor' => count($rows) === $limit
                ? ApiFormat::encodeCursor((int) end($rows)['id'])
                : null,
        ]);
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function organizationsShow(array $vars): string
    {
        $this->requireToken('read');

        $organization = (new OrganizationModel($this->db))->find((int) $vars['id']);

        if ($organization === null) {
            return $this->respondError(404, 'not_found', 'No organization with that id.');
        }

        return $this->respond(['data' => ApiSerializer::organization($organization)]);
    }

    public function stagesIndex(): string
    {
        $this->requireToken('read');

        $stages = array_map(
            static fn(array $s): array => [
                'id' => (int) $s['id'],
                'name' => (string) $s['name'],
                'sort_order' => (int) $s['sort_order'],
            ],
            (new StageModel($this->db))->allOrdered(),
        );

        return $this->respond(['data' => $stages]);
    }

    public function activitiesIndex(): string
    {
        $this->requireToken('read');

        $limit = ApiFormat::clampLimit($_GET['limit'] ?? null);

        $rows = (new ActivityModel($this->db))->apiList(
            contactId: (int) ($_GET['contact_id'] ?? 0),
            since: trim((string) ($_GET['since'] ?? '')),
            afterId: ApiFormat::decodeCursor(isset($_GET['cursor']) ? (string) $_GET['cursor'] : null),
            limit: $limit,
        );

        return $this->respond([
            'data' => array_map(ApiSerializer::activity(...), $rows),
            'next_cursor' => count($rows) === $limit
                ? ApiFormat::encodeCursor((int) end($rows)['id'])
                : null,
        ]);
    }
}
