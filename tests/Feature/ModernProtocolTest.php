<?php

namespace NovaMcp\Tests\Feature;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\TestCase;

class ModernProtocolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (version_compare(InstalledVersions::getVersion('laravel/mcp'), '1.0.0', '<')) {
            $this->markTestSkipped('The discovery protocol requires Laravel MCP 1.x.');
        }
    }

    private function modernRpc(string $token, string $method, array $params = []): TestResponse
    {
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ];
        if (isset($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }
        $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []];

        return $this->withHeaders($headers)->postJson('/mcp/nova', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ]);
    }

    public function test_modern_discovery_and_tools_preserve_token_permissions(): void
    {
        config(['nova-mcp.instructions' => 'Application context for modern clients.']);
        $token = $this->token(abilities: ['read'])['plain_text_token'];
        $response = $this->modernRpc($token, 'server/discover')->assertOk();
        $this->assertStringContainsString('Application context for modern clients.', $response->json('result.instructions'));
        $tools = $this->modernRpc($token, 'tools/list')->assertOk()->json('result.tools');
        $this->assertContains('nova.resources', array_column($tools, 'name'));
        $this->assertNotContains('nova.create', array_column($tools, 'name'));
        $resources = $this->payload($this->modernRpc($token, 'tools/call', [
            'name' => 'nova.resources', 'arguments' => (object) [],
        ]));
        $this->assertContains('records', array_column($resources['resources'], 'key'));
        $this->modernRpc($token, 'tools/call', [
            'name' => 'nova.create', 'arguments' => ['resource' => 'records', 'fields' => ['name' => 'blocked']],
        ])->assertStatus(400)->assertJsonPath('error.code', -32602);
        $this->assertSame(0, Record::count());

        $full = $this->token()['plain_text_token'];
        $this->payload($this->modernRpc($full, 'tools/call', [
            'name' => 'nova.create', 'arguments' => ['resource' => 'records', 'fields' => ['name' => 'created']],
        ]));
        $this->assertSame(1, Record::where('name', 'CREATED')->count());
    }

    public function test_modern_requests_cannot_bypass_bearer_nova_or_mcp_gate(): void
    {
        $allowed = $this->token()['plain_text_token'];
        $denied = $this->token($this->user('no-nova'))['plain_text_token'];
        foreach (['server/discover', 'tools/list', 'tools/call'] as $method) {
            $params = $method === 'tools/call'
                ? ['name' => 'nova.create', 'arguments' => ['resource' => 'records', 'fields' => ['name' => 'blocked']]]
                : [];
            $this->modernRpc('invalid', $method, $params)->assertUnauthorized();
            $this->modernRpc($denied, $method, $params)->assertForbidden();
            Gate::define('accessNovaMcp', fn () => false);
            $this->modernRpc($allowed, $method, $params)->assertForbidden();
            Gate::define('accessNovaMcp', fn () => true);
        }
        $this->assertSame(0, Record::count());
    }
}
