<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Nova;
use NovaMcp\Fields\FieldRegistry;
use NovaMcp\Fields\ScalarAdapter;
use NovaMcp\Http\Middleware\Authenticate;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\UnsupportedField;
use NovaMcp\Tests\TestCase;
use Psr\Log\LoggerInterface;

class SecurityTest extends TestCase
{
    public function test_auth_context_is_restored_when_downstream_throws(): void
    {
        $sessionUser = $this->user('browser');
        Auth::guard('web')->setUser($sessionUser);
        $tokenUser = $this->user('token');
        $token = $this->token($tokenUser)['plain_text_token'];
        $request = Request::create('/mcp/nova', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $request->setUserResolver(fn () => $sessionUser);
        $resolver = $request->getUserResolver();
        try {
            app(Authenticate::class)->handle($request, function ($inner) use ($tokenUser) {
                $this->assertTrue($inner->user()->is($tokenUser));
                $this->assertTrue(Nova::user()->is($tokenUser));
                throw new \RuntimeException('test');
            });
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('test', $e->getMessage());
        }
        $this->assertSame('web', Auth::getDefaultDriver());
        $this->assertTrue(Auth::user()->is($sessionUser));
        $this->assertSame($resolver, $request->getUserResolver());
        $this->assertFalse($request->attributes->has('nova-mcp.token'));
    }

    public function test_provider_override_and_deleted_owner_fail_closed(): void
    {
        config(['nova-mcp.auth.provider' => 'users']);
        $user = $this->user();
        $token = $this->token($user)['plain_text_token'];
        $user->delete();
        $this->rpc($token, 'ping')->assertUnauthorized();
    }

    public function test_read_token_cannot_access_management_api(): void
    {
        $token = $this->token(null, ['read'])['plain_text_token'];
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/nova-vendor/nova-mcp/tokens')->assertUnauthorized();
    }

    public function test_management_uses_csrf_protection(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->app['env'] = 'production'; // Laravel disables CSRF only in tests.
        $this->postJson('/nova-vendor/nova-mcp/tokens', ['name' => 'attack', 'abilities' => ['*'], 'expires_at' => now()->addDay()])->assertStatus(419);
    }

    public function test_https_and_rate_limits_are_enforced(): void
    {
        $token = $this->token()['plain_text_token'];
        $this->app['env'] = 'production';
        $this->rpc($token, 'ping')->assertStatus(400);
        $this->app['env'] = 'testing';
        config(['nova-mcp.rate_limit' => 1]);
        $this->rpc($token, 'ping')->assertStatus(429);
    }

    public function test_explicit_custom_field_adapter_enables_validated_nova_filling(): void
    {
        app(FieldRegistry::class)->register(UnsupportedField::class, new ScalarAdapter('string'));
        $record = Record::create(['name' => 'OWN', 'tenant_id' => 1]);
        $token = $this->token()['plain_text_token'];
        $this->payload($this->callTool($token, 'update', ['resource' => 'records', 'id' => (string) $record->id, 'fields' => ['name' => 'OWN', 'custom' => 'allowed']]));
        $this->assertSame('allowed', $record->fresh()->custom);
    }

    public function test_audit_never_records_bearer_or_field_values(): void
    {
        $token = $this->token()['plain_text_token'];
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->atLeast()->once()->withArgs(function ($event, $metadata) use ($token) {
            $encoded = json_encode($metadata);
            $this->assertStringNotContainsString($token, $encoded);
            $this->assertStringNotContainsString('sensitive-input', $encoded);

            return true;
        });
        Log::shouldReceive('channel')->andReturn($logger);
        $this->payload($this->callTool($token, 'create', ['resource' => 'records', 'fields' => ['name' => 'sensitive-input']]));
    }
}
