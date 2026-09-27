<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Support\Auth;
use App\Support\Config;
use App\Support\Database;

/**
 * Base for the agent-facing JSON API (docs/API.md).
 *
 * Auth is bearer-token, not session: tokens live in the server .env
 * (API_TOKEN_READ / API_TOKEN_WRITE), compared in constant time. The write
 * token also grants read. Every request is recorded in api_audit, which
 * doubles as the per-token rate limit window.
 */
abstract class ApiController
{
    private const RATE_LIMIT_PER_MINUTE = 120;

    protected ?string $tokenName = null;

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
        if ($this->config->apiTokenRead === '' && $this->config->apiTokenWrite === '') {
            $this->emitError(503, 'not_configured', 'The API is not enabled on this server.');
        }

        $presented = $this->presentedToken();

        if ($presented === '') {
            $this->emitError(401, 'missing_token', 'Send an Authorization: Bearer <token> header.');
        }

        $name = null;

        if ($this->config->apiTokenRead !== '' && hash_equals($this->config->apiTokenRead, $presented)) {
            $name = 'read';
        } elseif (
            $this->config->apiTokenWrite !== ''
            && hash_equals($this->config->apiTokenWrite, $presented)
        ) {
            $name = 'write';
        }

        if ($name === null) {
            $this->emitError(401, 'invalid_token', 'That token is not recognized.');
        }

        if ($scope === 'write' && $name !== 'write') {
            $this->tokenName = $name;
            $this->emitError(403, 'read_only_token', 'This action requires the read-write token.');
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
            'INSERT INTO api_audit (token_name, method, path, query_string, response_code)
             VALUES (:token_name, :method, :path, :query_string, :response_code)'
        );
        $stmt->execute([
            'token_name' => $this->tokenName ?? 'anonymous',
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'path' => substr($path, 0, 255),
            'query_string' => substr($query, 0, 1000) ?: null,
            'response_code' => $responseCode,
        ]);
    }
}
