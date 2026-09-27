<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\OrganizationModel;
use App\Models\ProposalModel;
use App\Models\StageModel;
use App\Models\TagModel;
use App\Services\McpProtocol;
use App\Services\ProposalService;
use App\Support\ApiFormat;
use App\Support\ApiSerializer;
use InvalidArgumentException;

/**
 * MCP endpoint (POST /mcp): the agent API's tools spoken over the Model
 * Context Protocol, so MCP clients (Superhuman Go, Claude, etc.) can plug
 * in directly. Same bearer tokens, same audit table, same rule as REST:
 * every write queues a proposal for human review at /proposals.
 *
 * Transport is stateless streamable HTTP: one JSON-RPC message per POST,
 * plain JSON responses, no SSE and no session ids. Protocol handling
 * lives in McpProtocol; this class supplies auth, the tool catalog, and
 * the executor that maps tool calls onto models and ProposalService.
 */
final class McpController extends ApiController
{
    private const PAGING = [
        'limit' => ['type' => 'integer', 'description' => 'Page size, 1-100 (default 25).'],
        'cursor' => ['type' => 'string', 'description' => 'Opaque next_cursor from the previous page.'],
    ];

    private const IDEMPOTENCY = [
        'idempotency_key' => [
            'type' => 'string',
            'description' => 'Client-chosen key; re-sending it returns the existing proposal instead of'
                . ' queueing a duplicate.',
        ],
    ];

    private const CONTACT_FIELDS = [
        'stage' => ['type' => 'string', 'description' => 'Pipeline stage name, see list_stages.'],
        'title' => ['type' => 'string'],
        'relationship_type' => [
            'type' => 'string',
            'enum' => ContactModel::RELATIONSHIP_TYPES,
        ],
        'email' => ['type' => 'string'],
        'phone' => ['type' => 'string'],
        'linkedin_url' => ['type' => 'string'],
        'human_detail' => ['type' => 'string', 'description' => 'One memorable human line about them.'],
        'organization_id' => ['type' => ['integer', 'null']],
        'cadence_days_override' => [
            'type' => ['integer', 'null'],
            'description' => 'Per-contact follow-up cadence in days (1-365), null to clear.',
        ],
    ];

    private string $urlToken = '';

    /**
     * @param array<string, string> $vars route parameters (secret-URL form)
     */
    public function handle(array $vars = []): string
    {
        $this->urlToken = trim((string) ($vars['token'] ?? $_GET['token'] ?? ''));

        $this->requireToken('read');

        $message = json_decode((string) file_get_contents('php://input'), true);

        if ($message === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->respond([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Request body is not valid JSON.'],
            ], 400);
        }

        $protocol = new McpProtocol($this->tools(), $this->runTool(...));
        $response = $protocol->handle($message);

        if ($response === null) {
            $this->audit(202);
            http_response_code(202);

            return '';
        }

        return $this->respond($response);
    }

    /**
     * Run one tool call and wrap the outcome as an MCP CallToolResult.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function runTool(string $name, array $args): array
    {
        if (str_starts_with($name, 'propose_') && $this->tokenName !== 'write') {
            return $this->toolError(
                'This tool requires the read-write token; the presented token is read-only.',
            );
        }

        try {
            return $this->toolResult(match ($name) {
                'search_contacts' => $this->searchContacts($args),
                'get_contact' => $this->getContact($args),
                'list_organizations' => $this->listOrganizations($args),
                'get_organization' => $this->getOrganization($args),
                'list_stages' => $this->listStages(),
                'list_tags' => $this->listTags(),
                'list_activities' => $this->listActivities($args),
                'get_proposal' => $this->getProposal($args),
                default => $this->propose(substr($name, strlen('propose_')), $args),
            });
        } catch (InvalidArgumentException $e) {
            return $this->toolError($e->getMessage());
        }
    }

    // ---- read tools ----------------------------------------------------

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function searchContacts(array $args): array
    {
        $limit = ApiFormat::clampLimit($args['limit'] ?? null);

        $rows = (new ContactModel($this->db))->apiList(
            query: trim((string) ($args['query'] ?? '')),
            stage: trim((string) ($args['stage'] ?? '')),
            updatedSince: trim((string) ($args['updated_since'] ?? '')),
            afterId: ApiFormat::decodeCursor(isset($args['cursor']) ? (string) $args['cursor'] : null),
            limit: $limit,
            tag: trim((string) ($args['tag'] ?? '')),
        );

        $tagsByContact = (new TagModel($this->db))->namesForContacts(
            array_map(static fn(array $c): int => (int) $c['id'], $rows),
        );

        return [
            'data' => array_map(
                static fn(array $c): array => ApiSerializer::contact($c, $tagsByContact[(int) $c['id']] ?? []),
                $rows,
            ),
            'next_cursor' => count($rows) === $limit
                ? ApiFormat::encodeCursor((int) end($rows)['id'])
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function getContact(array $args): array
    {
        $contact = (new ContactModel($this->db))->find((int) ($args['id'] ?? 0));

        if ($contact === null) {
            throw new InvalidArgumentException('No contact with that id.');
        }

        $payload = ApiSerializer::contact(
            $contact,
            (new TagModel($this->db))->namesForContact((int) $contact['id']),
        );
        $payload['recent_activity'] = array_map(
            ApiSerializer::activity(...),
            array_slice((new ActivityModel($this->db))->forContact((int) $contact['id']), 0, 10),
        );

        return ['data' => $payload];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function listOrganizations(array $args): array
    {
        $limit = ApiFormat::clampLimit($args['limit'] ?? null);

        $rows = (new OrganizationModel($this->db))->apiList(
            query: trim((string) ($args['query'] ?? '')),
            orgType: trim((string) ($args['type'] ?? '')),
            afterId: ApiFormat::decodeCursor(isset($args['cursor']) ? (string) $args['cursor'] : null),
            limit: $limit,
        );

        return [
            'data' => array_map(ApiSerializer::organization(...), $rows),
            'next_cursor' => count($rows) === $limit
                ? ApiFormat::encodeCursor((int) end($rows)['id'])
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function getOrganization(array $args): array
    {
        $organization = (new OrganizationModel($this->db))->find((int) ($args['id'] ?? 0));

        if ($organization === null) {
            throw new InvalidArgumentException('No organization with that id.');
        }

        return ['data' => ApiSerializer::organization($organization)];
    }

    /**
     * @return array<string, mixed>
     */
    private function listStages(): array
    {
        return ['data' => array_map(
            static fn(array $s): array => [
                'id' => (int) $s['id'],
                'name' => (string) $s['name'],
                'sort_order' => (int) $s['sort_order'],
            ],
            (new StageModel($this->db))->allOrdered(),
        )];
    }

    /**
     * @return array<string, mixed>
     */
    private function listTags(): array
    {
        return ['data' => array_map(
            static fn(array $t): array => [
                'id' => (int) $t['id'],
                'name' => (string) $t['name'],
                'contact_count' => (int) $t['contact_count'],
            ],
            (new TagModel($this->db))->allWithCounts(),
        )];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function listActivities(array $args): array
    {
        $limit = ApiFormat::clampLimit($args['limit'] ?? null);

        $rows = (new ActivityModel($this->db))->apiList(
            contactId: (int) ($args['contact_id'] ?? 0),
            since: trim((string) ($args['since'] ?? '')),
            afterId: ApiFormat::decodeCursor(isset($args['cursor']) ? (string) $args['cursor'] : null),
            limit: $limit,
        );

        return [
            'data' => array_map(ApiSerializer::activity(...), $rows),
            'next_cursor' => count($rows) === $limit
                ? ApiFormat::encodeCursor((int) end($rows)['id'])
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function getProposal(array $args): array
    {
        $proposal = (new ProposalModel($this->db))->find((int) ($args['id'] ?? 0));

        if ($proposal === null) {
            throw new InvalidArgumentException('No proposal with that id.');
        }

        return ['data' => ApiSerializer::proposal($proposal)];
    }

    // ---- write tools (all queue proposals) -----------------------------

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function propose(string $action, array $args): array
    {
        [$proposal, $isNew] = (new ProposalService($this->config, $this->db))
            ->queue((string) $this->tokenName, $action, $args);

        return [
            'data' => ApiSerializer::proposal($proposal),
            'note' => $isNew
                ? 'Queued for human review in Rolo. Nothing changes until it is approved;'
                    . ' check the outcome with get_proposal.'
                : 'A proposal with this idempotency_key already exists; returning it.',
        ];
    }

    // ---- plumbing -------------------------------------------------------

    /**
     * MCP clients that cannot send an Authorization header (claude.ai
     * custom connectors) present the token in the URL instead:
     * POST /mcp/{token} or /mcp?token=... The header wins when both are
     * given. The audit trail redacts URL tokens (see ApiController).
     */
    protected function presentedToken(): string
    {
        $header = $this->bearerToken();

        return $header !== '' ? $header : $this->urlToken;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function toolResult(array $data): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => (string) json_encode(
                    $data,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                ),
            ]],
            'isError' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolError(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    /**
     * The tool catalog. Shapes match docs/API.md; read tools carry
     * readOnlyHint so clients can skip write confirmations for them.
     *
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            $this->readTool(
                'search_contacts',
                'Search CRM contacts by name or organization, filterable by pipeline stage, tag,'
                    . ' or last-update date. Returns full contact records including tags and touch dates.',
                self::PAGING + [
                    'query' => ['type' => 'string', 'description' => 'Name or organization substring.'],
                    'stage' => ['type' => 'string', 'description' => 'Exact stage name, see list_stages.'],
                    'tag' => ['type' => 'string', 'description' => 'Exact tag name, see list_tags.'],
                    'updated_since' => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601.'],
                ],
            ),
            $this->readTool(
                'get_contact',
                'Fetch one contact by id, including tags and the 10 most recent activities.',
                ['id' => ['type' => 'integer']],
                ['id'],
            ),
            $this->readTool(
                'list_organizations',
                'List organizations, filterable by name substring and type'
                    . ' (startup, cro, university, investor, media, other).',
                self::PAGING + [
                    'query' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                ],
            ),
            $this->readTool(
                'get_organization',
                'Fetch one organization by id.',
                ['id' => ['type' => 'integer']],
                ['id'],
            ),
            $this->readTool(
                'list_stages',
                'The relationship pipeline stages in order. Stage names are the values accepted'
                    . ' by search_contacts and the propose_* tools.',
                [],
            ),
            $this->readTool(
                'list_tags',
                'All tags with contact counts. A segment is a tag or a pipeline stage.',
                [],
            ),
            $this->readTool(
                'list_activities',
                'The interaction feed (meetings, emails, calls, campaigns), newest data first,'
                    . ' filterable by contact and start date.',
                self::PAGING + [
                    'contact_id' => ['type' => 'integer'],
                    'since' => ['type' => 'string', 'description' => 'YYYY-MM-DD, activity_date >= since.'],
                ],
            ),
            $this->readTool(
                'get_proposal',
                'Check a queued proposal: status is pending, approved, or rejected'
                    . ' (error is set when applying failed).',
                ['id' => ['type' => 'integer']],
                ['id'],
            ),
            $this->writeTool(
                'propose_create_contact',
                'Propose creating a contact. A human reviews and approves in Rolo before anything'
                    . ' is created; on approval the follow-up schedule starts from the stage cadence.',
                self::IDEMPOTENCY + self::CONTACT_FIELDS + [
                    'name' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 10],
                ],
                ['name'],
            ),
            $this->writeTool(
                'propose_update_contact',
                'Propose updating fields on an existing contact. Approval applies the change; a stage'
                    . ' or cadence change reschedules the next touch, same as the Rolo UI.',
                self::IDEMPOTENCY + self::CONTACT_FIELDS + [
                    'contact_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
                ['contact_id'],
            ),
            $this->writeTool(
                'propose_change_tags',
                'Propose adding and/or removing tags on a contact. Unknown tags are created on'
                    . ' approval; tags left with no contacts are pruned.',
                self::IDEMPOTENCY + [
                    'contact_id' => ['type' => 'integer'],
                    'add' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 10],
                    'remove' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 10],
                ],
                ['contact_id'],
            ),
            $this->writeTool(
                'propose_create_organization',
                'Propose creating an organization. Rejected immediately if one with the same name'
                    . ' already exists; otherwise a human approves it in Rolo before it is created.',
                self::IDEMPOTENCY + [
                    'name' => ['type' => 'string'],
                    'type' => [
                        'type' => 'string',
                        'enum' => array_keys(OrganizationModel::ORG_TYPES),
                        'default' => 'other',
                    ],
                    'website' => ['type' => 'string'],
                    'linkedin_url' => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                ],
                ['name'],
            ),
            $this->writeTool(
                'propose_log_activity',
                'Propose logging an interaction against a contact. counts_as_touch defaults to FALSE:'
                    . ' the entry is recorded without moving the human follow-up schedule. Set it true'
                    . ' only for genuine 1:1 touches (a real meeting, call, or direct email).',
                self::IDEMPOTENCY + [
                    'contact_id' => ['type' => 'integer'],
                    'type' => ['type' => 'string', 'enum' => ActivityModel::API_LOGGABLE_TYPES],
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                    'summary' => ['type' => 'string'],
                    'counts_as_touch' => ['type' => 'boolean', 'default' => false],
                    'follow_up_date' => [
                        'type' => 'string',
                        'description' => 'YYYY-MM-DD. With counts_as_touch true, this becomes the'
                            . ' next follow-up on approval (an explicit date wins over the cadence).',
                    ],
                    'follow_up_note' => [
                        'type' => 'string',
                        'description' => 'Short reminder of WHAT the follow-up action is, e.g.'
                            . ' "Send the revised pilot pricing PDF". Shown on the Rolo dashboard'
                            . ' and in the daily email while this is the latest interaction.',
                    ],
                ],
                ['contact_id', 'type', 'date', 'summary'],
            ),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function readTool(string $name, string $description, array $properties, array $required = []): array
    {
        return $this->tool($name, $description, $properties, $required)
            + ['annotations' => ['readOnlyHint' => true]];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function writeTool(string $name, string $description, array $properties, array $required): array
    {
        return $this->tool($name, $description, $properties, $required)
            + ['annotations' => ['readOnlyHint' => false, 'destructiveHint' => false]];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => (object) $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return ['name' => $name, 'description' => $description, 'inputSchema' => $schema];
    }
}
