<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Throwable;

/**
 * Minimal MCP (Model Context Protocol) server core: JSON-RPC 2.0 over the
 * streamable HTTP transport, stateless, tools only. Pure logic with no
 * HTTP or database concerns, so it is unit-testable; the executor closure
 * supplied by McpController does the actual CRM work.
 *
 * Supported methods: initialize, ping, tools/list, tools/call. All
 * notifications are accepted and produce no response. Batch messages are
 * rejected (removed in protocol revision 2025-06-18, which we target).
 */
final class McpProtocol
{
    public const LATEST_VERSION = '2025-06-18';

    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const SERVER_INFO = ['name' => 'Rolo CRM', 'version' => '1.0.0'];

    private const INSTRUCTIONS = 'Rolo is a consultancy\'s CRM. Read tools return live data. '
        . 'Write tools (propose_*) NEVER change the CRM directly: each call queues a proposal that '
        . 'a human approves or rejects in Rolo. Pass an idempotency_key on writes so retries cannot '
        . 'queue duplicates, and use get_proposal to check whether a proposal was approved.';

    /**
     * @param list<array<string, mixed>> $tools MCP tool definitions
     * @param Closure(string, array<string, mixed>): array<string, mixed> $executor
     *        runs a tool by name, returns an MCP CallToolResult
     */
    public function __construct(
        private readonly array $tools,
        private readonly Closure $executor,
    ) {
    }

    /**
     * Handle one decoded JSON-RPC message. Returns the response envelope,
     * or null when no response must be sent (notifications).
     *
     * @return array<string, mixed>|null
     */
    public function handle(mixed $message): ?array
    {
        if (is_array($message) && array_is_list($message)) {
            return $this->error(null, -32600, 'Batch requests are not supported.');
        }

        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return $this->error(null, -32600, 'Expected a single JSON-RPC 2.0 message.');
        }

        $method = $message['method'] ?? null;
        $isNotification = !array_key_exists('id', $message);
        $id = $message['id'] ?? null;

        if (!is_string($method) || $method === '') {
            return $isNotification ? null : $this->error($id, -32600, 'Missing method.');
        }

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        $response = match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => $this->negotiateVersion($params),
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => self::SERVER_INFO,
                'instructions' => self::INSTRUCTIONS,
            ]),
            'ping' => $this->result($id, []),
            'tools/list' => $this->result($id, ['tools' => $this->tools]),
            'tools/call' => $this->callTool($id, $params),
            default => $this->error($id, -32601, sprintf('Method "%s" is not supported.', $method)),
        };

        return $isNotification ? null : $response;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $known = array_column($this->tools, 'name');

        if (!in_array($name, $known, true)) {
            return $this->error($id, -32602, sprintf(
                'Unknown tool "%s". Available: %s.',
                $name,
                implode(', ', $known),
            ));
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            return $this->result($id, ($this->executor)($name, $arguments));
        } catch (Throwable $e) {
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => 'Tool failed: ' . $e->getMessage()]],
                'isError' => true,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function negotiateVersion(array $params): string
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::LATEST_VERSION;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? (object) [] : $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
