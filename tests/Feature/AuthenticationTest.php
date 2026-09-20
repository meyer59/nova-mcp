<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Nova;
use Laravel\Nova\Util;
use NovaMcp\Auth\ProviderResolver;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\Fixtures\User;
use NovaMcp\Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_initialize_requires_a_valid_token(): void
    {
        $this->rpc('invalid', 'initialize')->assertUnauthorized();
        $token = $this->token();
        $this->rpc($token['plain_text_token'], 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'test', 'version' => '1']])->assertOk()->assertJsonPath('result.serverInfo.name', 'Nova MCP');
    }

    public function test_normal_nova_serving_lifecycle_registers_resources_and_access_gate(): void
    {
        Nova::$resources = [];
        Nova::$authUsing = null;
        Nova::serving(function ($event) {
            $this->assertNotNull($event->request->user());
            Nova::resources([RecordResource::class]);
            Nova::auth(fn ($r) => $r->user()->name !== 'no-nova');
        });
        $token = $this->token()['plain_text_token'];
        $this->assertCount(1, $this->payload($this->callTool($token, 'resources'))['resources']);
        $this->assertSame([], Nova::$resources);
        $this->assertNull(Nova::$authUsing);
        $denied = $this->token($this->user('no-nova'))['plain_text_token'];
        $this->rpc($denied, 'ping')->assertForbidden();
        $this->assertSame('web', Auth::getDefaultDriver());
    }

    public function test_null_nova_guard_retains_the_default_user_provider_during_the_request(): void
    {
        config(['nova.guard' => null]);
        $before = config('auth.guards.nova-mcp');
        Nova::serving(function () {
            $this->assertSame(User::class, Util::userModel());
            $this->assertSame('users', app(ProviderResolver::class)->name());
        });
        $token = $this->token()['plain_text_token'];
        $this->payload($this->callTool($token, 'create', ['resource' => 'records', 'fields' => ['name' => 'default guard']]));
        $this->assertNull(config('nova.guard'));
        $this->assertSame($before, config('auth.guards.nova-mcp'));
    }
}
