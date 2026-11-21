<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\TaskModel;
use App\Models\UserModel;
use App\Services\OwnerResolver;
use App\Support\ApiFormat;
use App\Support\ApiSerializer;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Database;
use App\Support\Dates;
use InvalidArgumentException;

/**
 * Base for the agent-facing JSON API (docs/API.md).
 *
 * Auth is bearer-token, not session, compared in constant time. Two kinds
 * of token live in the server .env:
 *  - API_USER_TOKENS: one per person (email:token). Authenticates the
 *    agent AS that user — read-write (writes still only queue proposals),
 *    with audit rows and proposals attributed to them.
 *  - API_TOKEN_READ / API_TOKEN_WRITE: the legacy shared, anonymous
 *    tokens. Kept working for the transition; the write token grants read.
 * Every request is recorded in api_audit, which doubles as the per-token
 * rate limit window.
 */
abstract class ApiController
{
    private const RATE_LIMIT_PER_MINUTE = 120;

    protected ?string $tokenName = null;

    /** The person behind a per-user token; null for the legacy shared ones. */
    protected ?int $actorUserId = null;

    protected bool $canWrite = false;

    public function __construct(
        protected readonly Config $config,
        protected readonly Database $db,
        protected readonly Auth $auth,
    ) {
    }

    /**
     * Authenticate the request for the given scope, or emit a JSON error
     * and exit. Call first in every endpoint.
     */
    protected function requireToken(string $scope = 'read'): void
    {
        if (
            $this->config->apiTokenRead === ''
            && $this->config->apiTokenWrite === ''
            && $this->config->apiUserTokens === []
        ) {
            $this->emitError(503, 'not_configured', 'The API is not enabled on this server.');
        }

        $presented = $this->presentedToken();

        if ($presented === '') {
            $this->emitError(401, 'missing_token', 'Send an Authorization: Bearer <token> header.');
        }

        $name = null;

        foreach ($this->config->apiUserTokens as $email => $token) {
            if (!hash_equals($token, $presented)) {
                continue;
            }

            $user = (new UserModel($this->db))->findByEmail($email);

            if ($user === null) {
                $this->emitError(
                    503,
                    'misconfigured',
                    'API_USER_TOKENS names a user this server does not know.',
                );
            }

            $this->actorUserId = (int) $user['id'];
            $this->canWrite = true;
            $name = 'user:' . $this->actorUserId;
            break;
        }

        if ($name === null) {
            if ($this->config->apiTokenRead !== '' && hash_equals($this->config->apiTokenRead, $presented)) {
                $name = 'read';
            } elseif (
                $this->config->apiTokenWrite !== ''
                && hash_equals($this->config->apiTokenWrite, $presented)
            ) {
                $name = 'write';
                $this->canWrite = true;
            }
        }

        if ($name === null) {
            $this->emitError(401, 'invalid_token', 'That token is not recognized.');
        }

        if ($scope === 'write' && !$this->canWrite) {
            $this->tokenName = $name;
            $this->emitError(403, 'read_only_token', 'This action requires a write-capable token.');
        }

        $this->tokenName = $name;

        if ($this->requestsInLastMinute($name) >= self::RATE_LIMIT_PER_MINUTE) {
            $this->emitError(429, 'rate_limited', 'Rate limit exceeded: 120 requests per minute per token.');
        }
    }

    /**
     * Success response: audits the request, returns the JSON body string.
     *
     * @param array<string, mixed> $payload
     */
    protected function respond(array $payload, int $status = 200): string
    {
        $this->audit($status);

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Error response helper for handlers that can continue normally.
     *
     * @return string JSON body
     */
    protected function respondError(int $status, string $code, string $message): string
    {
        $this->audit($status);

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        return (string) json_encode(
            ['error' => ['code' => $code, 'message' => $message]],
            JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Emit an error and stop — used before/during auth where the normal
     * return-a-string flow hasn't started.
     */
    private function emitError(int $status, string $code, string $message): never
    {
        echo $this->respondError($status, $code, $message);
        exit;
    }

    /**
     * How the caller presents its token. The REST API accepts only the
     * Authorization header; McpController overrides this to also allow the
     * secret-URL form for MCP clients that cannot send headers.
     */
    protected function presentedToken(): string
    {
        return $this->bearerToken();
    }

    /**
     * Resolve an agent's optional owner filter ('me', 'shared', a user id,
     * or a user email) to the ContactModel filter value ('', 'shared', or a
     * numeric string). Omitted means everyone — the filter is a choice,
     * never a forced default. Throws with an agent-presentable message on
     * anything unresolvable.
     */
    protected function resolveOwnerFilter(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $userId = (new OwnerResolver($this->db))->resolve($raw, $this->actorUserId);

        return $userId === null ? 'shared' : (string) $userId;
    }

    /**
     * The task list both transports serve (GET /api/v1/tasks and the MCP
     * list_tasks tool), so REST and MCP cannot disagree on a filter.
     * $args is the query string or the tool arguments. Like the contact
     * reads, omitting owner searches the whole workspace.
     *
     * @param array<string, mixed> $args status, owner, contact_id,
     *        organization_id, due_before, limit, cursor
     * @param string|null $owner the owner filter when the caller has
     *        already resolved it (resolveOwnerFilter), so REST can report a
     *        bad owner under its own error code; null resolves $args here
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     * @throws InvalidArgumentException with an agent-presentable message
     */
    protected function taskList(array $args, ?string $owner = null): array
    {
        $status = strtolower(trim((string) ($args['status'] ?? 'open')));

        if (!in_array($status, ['open', 'done', 'all'], true)) {
            throw new InvalidArgumentException("status must be 'open', 'done' or 'all'.");
        }

        $dueBefore = trim((string) ($args['due_before'] ?? ''));

        if ($dueBefore !== '' && !Dates::isValidYmd($dueBefore)) {
            throw new InvalidArgumentException('due_before must be YYYY-MM-DD.');
        }

        $contactId = ApiFormat::optionalId($args['contact_id'] ?? null, 'contact_id');
        $organizationId = ApiFormat::optionalId($args['organization_id'] ?? null, 'organization_id');
        $limit = ApiFormat::clampLimit($args['limit'] ?? null);

        $rows = (new TaskModel($this->db))->apiList(
            status: $status,
            owner: $owner ?? $this->resolveOwnerFilter((string) ($args['owner'] ?? '')),
            contactId: $contactId,
            organizationId: $organizationId,
            dueBefore: $dueBefore,
            afterId: ApiFormat::decodeCursor(isset($args['cursor']) ? (string) $args['cursor'] : null),
            limit: $limit,
        );

        return [
            'data' => array_map(ApiSerializer::task(...), $rows),
            'next_cursor' => count($rows) === $limit
                ? ApiFormat::encodeCursor((int) end($rows)['id'])
                : null,
        ];
    }

    /**
     * A contact's open tasks, serialized: both transports add this to the
     * single-contact read so an agent asked "what do I owe this person?"
     * needs one call.
     *
     * @return list<array<string, mixed>>
     */
    protected function openTasksForContact(int $contactId): array
    {
        return array_map(
            ApiSerializer::task(...),
            (new TaskModel($this->db))->openForContact($contactId),
        );
    }

    protected function bearerToken(): string
    {
        // REDIRECT_ variant: Apache re-exports the stripped Authorization
        // header via mod_rewrite (see public/.htaccess).
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }

    private function requestsInLastMinute(string $tokenName): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM api_audit
             WHERE token_name = :token_name
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 SECOND)'
        );
        $stmt->execute(['token_name' => $tokenName]);

        return (int) $stmt->fetchColumn();
    }

    protected function audit(int $responseCode): void
    {
        // The MCP secret-URL form carries the token in the path or a
        // token= query parameter; neither may be persisted in the audit
        // trail.
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = (string) preg_replace('#^/mcp/[^/]+#', '/mcp/[redacted]', $path);

        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $query = (string) preg_replace('/(^|&)token=[^&]*/', '$1token=[redacted]', $query);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO api_audit (token_name, user_id, method, path, query_string, response_code)
             VALUES (:token_name, :user_id, :method, :path, :query_string, :response_code)'
        );
        $stmt->execute([
            'token_name' => $this->tokenName ?? 'anonymous',
            'user_id' => $this->actorUserId,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'path' => substr($path, 0, 255),
            'query_string' => substr($query, 0, 1000) ?: null,
            'response_code' => $responseCode,
        ]);
    }
}
