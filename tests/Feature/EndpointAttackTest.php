<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\User;
use NovaMcp\Tests\TestCase;
use NovaMcp\Tokens\TokenService;

class EndpointAttackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TrustProxies::flushState();
        // Testbench does not install the host application's global proxy middleware.
        $this->app->make(Kernel::class)->prependMiddleware(TrustProxies::class);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    private function attack(?string $authorization, mixed $payload = null, array $server = [], string $method = 'POST', string $path = '/mcp/nova')
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '198.51.100.20'];
        if ($authorization !== null) {
            $headers['HTTP_AUTHORIZATION'] = $authorization;
        }

        $url = str_starts_with($path, '/') ? 'http://localhost'.$path : $path;

        return $this->call($method, $url, server: array_replace($headers, $server), content: json_encode($payload ?? ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
    }

    public function test_token_ids_and_secrets_cannot_be_swapped_forged_or_replayed_after_rotation(): void
    {
        $owner = $this->user();
        $one = $this->token($owner);
        $two = $this->token($owner);
        $secret = explode('_', $one['plain_text_token'])[2];
        foreach (['nvm_'.$two['token']->id.'_'.$secret, 'nvm_'.$one['token']->id.'_'.str_repeat('0', 64), 'nvm_1 OR 1=1_'.$secret, 'nvm_'.str_repeat('9', 256).'_'.$secret] as $forged) {
            $this->attack('Bearer '.$forged)->assertUnauthorized();
        }
        $rotated = app(TokenService::class)->change($owner, $owner, (string) $one['token']->id, 'rotate');
        $this->attack('Bearer '.$one['plain_text_token'])->assertUnauthorized();
        $this->attack('Bearer '.$rotated['plain_text_token'])->assertOk();
    }

    public function test_ambiguous_authorization_headers_are_rejected(): void
    {
        $token = $this->token()['plain_text_token'];
        foreach (['Basic x Bearer '.$token, 'Bearer '.$token.', Basic x', 'Bearer invalid, Bearer '.$token, 'NotBearer '.$token] as $header) {
            $this->attack($header)->assertUnauthorized();
        }
        $this->attack('bEaReR '.$token)->assertOk();
    }

    public function test_repeated_authorization_headers_are_rejected(): void
    {
        $token = $this->token()['plain_text_token'];
        // Exercise duplicates preserved by the server; a PHP server variable itself is a string.
        $request = Request::create('http://localhost/mcp/nova', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        $request->headers->set('Authorization', ['Bearer '.$token, 'Bearer invalid']);
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_forged_proxy_headers_do_not_bypass_an_ip_gate(): void
    {
        TrustProxies::at(['10.0.0.10']);
        Gate::define('accessNovaMcp', fn ($user, Request $request) => $request->ip() === '203.0.113.10');
        $token = $this->token()['plain_text_token'];
        $forged = ['HTTP_X_FORWARDED_FOR' => '203.0.113.10', 'HTTP_FORWARDED' => 'for=203.0.113.10;proto=https', 'HTTP_X_REAL_IP' => '203.0.113.10', 'HTTP_CF_CONNECTING_IP' => '203.0.113.10'];
        $this->attack('Bearer '.$token, server: $forged)->assertForbidden();
        $this->attack('Bearer '.$token, server: ['REMOTE_ADDR' => '203.0.113.10'])->assertOk();
        $this->attack('Bearer '.$token, server: ['REMOTE_ADDR' => '10.0.0.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10'])->assertOk();
        $this->attack('Bearer '.$token, server: ['REMOTE_ADDR' => '10.0.0.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 198.51.100.20'])->assertForbidden();
    }

    public function test_spoofed_host_cannot_enable_automatic_proxy_trust_to_bypass_ip_gate(): void
    {
        TrustProxies::flushState();
        config(['trustedproxy.proxies' => null]);
        Gate::define('accessNovaMcp', fn ($user, Request $request) => $request->ip() === '203.0.113.10');
        $token = $this->token()['plain_text_token'];
        foreach (['attacker.on-forge.com', 'attacker.on-vapor.com'] as $host) {
            $this->attack('Bearer '.$token, server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.10'], path: 'http://'.$host.'/mcp/nova')->assertForbidden();
        }
    }

    public function test_unapproved_hosts_are_rejected_before_rate_limit_keys_are_allocated(): void
    {
        $calls = 0;
        RateLimiter::for('nova-mcp-ip', function (Request $request) use (&$calls) {
            $calls++;

            return Limit::perMinute(2)->by($request->ip());
        });
        $this->attack(null, server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.10'], path: 'http://attacker.on-forge.com/mcp/nova')->assertForbidden();
        $this->assertSame(0, $calls);
        $this->attack(null)->assertUnauthorized();
        $this->assertSame(1, $calls);
    }

    public function test_gate_is_checked_for_batch_notification_and_spoofed_identity_payloads(): void
    {
        $allowed = $this->user('allowed');
        $denied = $this->user('denied');
        $token = $this->token($denied)['plain_text_token'];
        $this->actingAs($allowed);
        Gate::define('accessNovaMcp', fn ($user) => $user->name === 'allowed');
        $create = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nova.create', 'arguments' => ['resource' => 'records', 'fields' => ['name' => 'attack']]]];
        foreach ([$create, [$create, $create], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['user' => $allowed->toArray(), 'nova-mcp.token' => ['abilities' => ['*']], 'method' => 'ping', 'id' => 1]] as $payload) {
            $this->attack('Bearer '.$token, $payload, ['HTTP_X_USER_ID' => (string) $allowed->id])->assertForbidden();
        }
        $this->assertSame(0, Record::count());
        $this->assertSame($allowed, Auth::user());
    }

    public function test_session_ids_and_client_metadata_do_not_authenticate_or_override_gate(): void
    {
        $owner = $this->user('allowed');
        $token = $this->token($owner)['plain_text_token'];
        Gate::define('accessNovaMcp', fn ($user) => $user->name === 'allowed');
        $response = $this->attack('Bearer '.$token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'admin', 'version' => '1']]])->assertOk();
        $session = $response->headers->get('MCP-Session-Id');
        $this->assertNotEmpty($session);
        $headers = ['HTTP_MCP_SESSION_ID' => $session];
        $this->attack(null, server: $headers)->assertUnauthorized();
        $owner->update(['name' => 'denied']);
        $this->attack('Bearer '.$token, server: $headers)->assertForbidden();
        $owner->delete();
        $this->attack('Bearer '.$token, server: $headers)->assertUnauthorized();
    }

    public function test_method_variants_and_overrides_cannot_reach_tools_without_auth_or_gate(): void
    {
        $token = $this->token()['plain_text_token'];
        Gate::define('accessNovaMcp', fn () => false);
        foreach (['POST', 'GET', 'HEAD', 'DELETE'] as $method) {
            $this->attack(null, method: $method)->assertUnauthorized();
            $this->attack('Bearer '.$token, method: $method)->assertForbidden();
        }
        foreach (['GET', 'DELETE'] as $override) {
            $this->attack('Bearer '.$token, server: ['HTTP_X_HTTP_METHOD_OVERRIDE' => $override])->assertForbidden();
        }
        $this->assertSame(0, Record::count());
    }

    public function test_rate_limits_cannot_be_evaded_by_altering_untrusted_forwarded_ips_or_changing_ips_for_one_token(): void
    {
        TrustProxies::at(['10.0.0.10']);
        config(['nova-mcp.rate_limit' => 2]);
        $token = $this->token()['plain_text_token'];
        $this->attack('Bearer invalid', server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.1'])->assertUnauthorized();
        $this->attack('Bearer invalid', server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.2'])->assertUnauthorized();
        $this->attack('Bearer invalid', server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.3'])->assertStatus(429);
        foreach (['198.51.100.21', '198.51.100.22'] as $ip) {
            $this->attack('Bearer '.$token, server: ['REMOTE_ADDR' => $ip])->assertOk();
        }
        $this->attack('Bearer '.$token, server: ['REMOTE_ADDR' => '198.51.100.23'])->assertStatus(429);
    }

    public function test_a_different_provider_with_the_same_user_id_cannot_reuse_a_token(): void
    {
        $owner = $this->user();
        $token = $this->token($owner)['plain_text_token'];
        config(['auth.providers.alternate' => ['driver' => 'eloquent', 'model' => User::class], 'nova-mcp.auth.provider' => 'alternate']);
        $this->attack('Bearer '.$token)->assertUnauthorized();
    }

    public function test_host_allowlist_checks_original_and_forwarded_hosts_and_supports_explicit_tenant_hosts(): void
    {
        TrustProxies::at(['10.0.0.10']);
        $token = $this->token()['plain_text_token'];
        foreach (['evil.test', 'localhost.evil.test', 'tenant.localhost'] as $host) {
            $this->attack('Bearer '.$token, path: 'http://'.$host.'/mcp/nova')->assertForbidden();
        }
        $this->attack('Bearer '.$token, server: ['REMOTE_ADDR' => '10.0.0.10', 'HTTP_X_FORWARDED_HOST' => 'evil.test'])->assertForbidden();
        $this->attack('Bearer '.$token, server: ['REMOTE_ADDR' => '10.0.0.10', 'HTTP_X_FORWARDED_HOST' => 'localhost'], path: 'http://evil.test/mcp/nova')->assertForbidden();
        config(['nova-mcp.allowed_hosts' => ['tenant.example.test', 'internal.example.test']]);
        $this->attack('Bearer '.$token, path: 'http://tenant.example.test:8080/mcp/nova')->assertOk();
        $this->attack('Bearer '.$token, server: ['REMOTE_ADDR' => '10.0.0.10', 'HTTP_X_FORWARDED_HOST' => 'tenant.example.test'], path: 'http://internal.example.test/mcp/nova')->assertOk();
    }

    public function test_host_allowlist_supports_ipv6_and_case_insensitive_dns_names(): void
    {
        $token = $this->token()['plain_text_token'];
        config(['nova-mcp.allowed_hosts' => ['[::1]', 'Tenant.Example.Test']]);
        $this->attack('Bearer '.$token, path: 'http://[::1]:8000/mcp/nova')->assertOk();
        $this->attack('Bearer '.$token, path: 'http://TENANT.EXAMPLE.TEST/mcp/nova')->assertOk();
    }

    public function test_tokens_in_cookies_body_or_forwarded_auth_headers_do_not_authenticate(): void
    {
        $owner = $this->user();
        $token = $this->token($owner)['plain_text_token'];
        $this->actingAs($owner);
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'access_token' => $token, 'authorization' => 'Bearer '.$token];
        $this->attack(null, $body, ['HTTP_COOKIE' => 'access_token='.$token, 'HTTP_X_FORWARDED_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_X_AUTH_TOKEN' => $token, 'HTTP_MCP_SESSION_ID' => $token])->assertUnauthorized();
    }

    public function test_repeated_origin_headers_cannot_hide_a_disallowed_origin(): void
    {
        $token = $this->token()['plain_text_token'];
        $request = Request::create('http://localhost/mcp/nova', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        $request->headers->set('Origin', ['http://localhost', 'https://evil.test']);
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_untrusted_forwarded_proto_cannot_disable_https_enforcement(): void
    {
        $this->app['env'] = 'production';
        TrustProxies::at(['10.0.0.10']);
        $token = $this->token()['plain_text_token'];
        $this->attack('Bearer '.$token, server: ['HTTP_X_FORWARDED_PROTO' => 'https'])->assertStatus(400);
        $this->attack('Bearer '.$token, server: ['REMOTE_ADDR' => '10.0.0.10', 'HTTP_X_FORWARDED_PROTO' => 'https'])->assertOk();
    }

    public function test_gate_exceptions_fail_closed_and_restore_authentication_state(): void
    {
        $browser = $this->user('browser');
        $token = $this->token()['plain_text_token'];
        $this->actingAs($browser);
        config(['app.debug' => false]);
        Gate::define('accessNovaMcp', fn () => throw new \RuntimeException('private gate failure'));
        $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nova.create', 'arguments' => ['resource' => 'records', 'fields' => ['name' => 'attack']]]];
        $response = $this->attack('Bearer '.$token, $payload)->assertStatus(500);
        $this->assertStringNotContainsString('private gate failure', $response->getContent());
        $this->assertSame(0, Record::count());
        $this->assertSame('web', Auth::getDefaultDriver());
        $this->assertSame($browser, Auth::user());
        $this->assertFalse(Auth::guard('nova-mcp')->hasUser());
        Gate::define('accessNovaMcp', fn () => false);
        $this->attack('Bearer '.$token)->assertForbidden();
    }

    public function test_defined_gate_is_not_bypassed_in_local_environment(): void
    {
        $this->app['env'] = 'local';
        Gate::define('accessNovaMcp', fn () => false);
        $this->attack('Bearer '.$this->token()['plain_text_token'])->assertForbidden();
    }

    public function test_global_super_admin_override_must_exempt_the_access_gate(): void
    {
        $token = $this->token()['plain_text_token'];
        $exemptAccessGate = false;
        Gate::before(function ($user, $ability) use (&$exemptAccessGate) {
            return $exemptAccessGate && $ability === 'accessNovaMcp' ? null : true;
        });
        Gate::define('accessNovaMcp', fn () => false);
        // This is application policy overriding the gate, not forged authentication.
        $this->attack('Bearer '.$token)->assertOk();
        $exemptAccessGate = true;
        $this->attack('Bearer '.$token)->assertForbidden();
    }

    public function test_wildcard_proxy_trust_is_unsafe_when_the_origin_is_directly_reachable(): void
    {
        $token = $this->token()['plain_text_token'];
        Gate::define('accessNovaMcp', fn ($user, Request $request) => $request->ip() === '203.0.113.10');
        TrustProxies::at('*');
        $headers = ['HTTP_X_FORWARDED_FOR' => '203.0.113.10'];
        // Reproduce the host-configuration hazard even with a legitimate Host.
        $this->attack('Bearer '.$token, server: $headers)->assertOk();
        TrustProxies::at(['10.0.0.10']);
        $this->attack('Bearer '.$token, server: $headers)->assertForbidden();
    }
}
