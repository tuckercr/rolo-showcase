<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\McpProtocol;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class McpProtocolTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $tools;

    private McpProtocol $protocol;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->tools = [
            ['name' => 'echo_tool', 'description' => 'Echoes.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'boom_tool', 'description' => 'Throws.', 'inputSchema' => ['type' => 'object']],
        ];
        $this->calls = [];

        $this->protocol = new McpProtocol($this->tools, function (string $name, array $args): array {
            $this->calls[] = [$name, $args];

            if ($name === 'boom_tool') {
                throw new RuntimeException('database exploded');
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode($args)]],
                'isError' => false,
            ];
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function request(string $method, array $params = [], mixed $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }

    public function testInitializeEchoesASupportedProtocolVersion(): void
    {
        $response = $this->protocol->handle(
            $this->request('initialize', ['protocolVersion' => '2025-03-26']),
        );

        self::assertSame('2025-03-26', $response['result']['protocolVersion']);
        self::assertSame('Rolo CRM', $response['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $response['result']['capabilities']);
    }

    public function testInitializeFallsBackToLatestForUnknownVersion(): void
    {
        $response = $this->protocol->handle(
            $this->request('initialize', ['protocolVersion' => '1999-01-01']),
        );

        self::assertSame(McpProtocol::LATEST_VERSION, $response['result']['protocolVersion']);
    }

    public function testToolsListReturnsTheCatalog(): void
    {
        $response = $this->protocol->handle($this->request('tools/list'));

        self::assertSame($this->tools, $response['result']['tools']);
        self::assertSame(1, $response['id']);
    }

    public function testPingReturnsEmptyResult(): void
    {
        $response = $this->protocol->handle($this->request('ping', [], 'ping-1'));

        self::assertSame('ping-1', $response['id']);
        self::assertEquals((object) [], $response['result']);
    }

    public function testToolsCallRunsTheExecutorWithArguments(): void
    {
        $response = $this->protocol->handle($this->request('tools/call', [
            'name' => 'echo_tool',
            'arguments' => ['q' => 'chen'],
        ]));

        self::assertSame([['echo_tool', ['q' => 'chen']]], $this->calls);
        self::assertFalse($response['result']['isError']);
    }

    public function testToolsCallUnknownToolIsAProtocolError(): void
    {
        $response = $this->protocol->handle($this->request('tools/call', ['name' => 'nope']));

        self::assertSame(-32602, $response['error']['code']);
        self::assertStringContainsString('echo_tool', $response['error']['message']);
        self::assertSame([], $this->calls);
    }

    public function testExecutorExceptionsBecomeToolErrorsNotProtocolErrors(): void
    {
        $response = $this->protocol->handle($this->request('tools/call', ['name' => 'boom_tool']));

        self::assertArrayNotHasKey('error', $response);
        self::assertTrue($response['result']['isError']);
        self::assertStringContainsString('database exploded', $response['result']['content'][0]['text']);
    }

    public function testNotificationsProduceNoResponse(): void
    {
        $message = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];

        self::assertNull($this->protocol->handle($message));
    }

    public function testRequestNotificationWithUnknownMethodIsSilentlyDropped(): void
    {
        $message = ['jsonrpc' => '2.0', 'method' => 'resources/list'];

        self::assertNull($this->protocol->handle($message));
    }

    public function testUnknownMethodWithIdIsMethodNotFound(): void
    {
        $response = $this->protocol->handle($this->request('resources/list'));

        self::assertSame(-32601, $response['error']['code']);
    }

    public function testBatchRequestsAreRejected(): void
    {
        $response = $this->protocol->handle([
            $this->request('ping'),
            $this->request('tools/list', [], 2),
        ]);

        self::assertSame(-32600, $response['error']['code']);
    }

    public function testNonJsonRpcEnvelopeIsRejected(): void
    {
        $response = $this->protocol->handle(['hello' => 'world']);

        self::assertSame(-32600, $response['error']['code']);
        self::assertNull($response['id']);
    }
}
