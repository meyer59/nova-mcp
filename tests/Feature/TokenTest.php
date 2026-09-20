<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use NovaMcp\NovaMcp;
use NovaMcp\Tests\Fixtures\NovaUser;
use NovaMcp\Tests\TestCase;
use NovaMcp\Tokens\TokenService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TokenTest extends TestCase
{
    public function test_secrets_are_hashed_and_only_returned_at_creation_or_rotation(): void
    {
        $user = $this->user();
        $issued = $this->token($user);
        $token = $issued['token'];
        $this->assertStringNotContainsString($issued['plain_text_token'], $token->toJson());
        $this->assertArrayNotHasKey('token', $token->toArray());
        $this->assertSame(64, strlen($token->getRawOriginal('token')));
        $rotated = app(TokenService::class)->change($user, $user, (string) $token->id, 'rotate');
        $this->rpc($issued['plain_text_token'], 'ping')->assertUnauthorized();
        $this->rpc($rotated['plain_text_token'], 'ping')->assertOk();
        app(TokenService::class)->change($user, $user, (string) $token->id, 'revoke');
        $this->rpc($rotated['plain_text_token'], 'ping')->assertUnauthorized();
    }

    public function test_expiration_and_nova_access_are_enforced(): void
    {
        $issued = $this->token();
        $issued['token']->update(['expires_at' => now()->subSecond()]);
        $this->rpc($issued['plain_text_token'], 'ping')->assertUnauthorized();
        $denied = $this->token($this->user('no-nova'));
        $this->rpc($denied['plain_text_token'], 'ping')->assertForbidden();
    }

    public function test_owner_isolation_and_administrator_callback(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $issued = $this->token($owner);
        try {
            app(TokenService::class)->query($other, $owner);
            $this->fail('Cross-user access was permitted.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        NovaMcp::manageTokensUsing(fn ($actor, $target) => $actor->is($other));
        $this->assertSame(1, app(TokenService::class)->query($other, $owner)->count());
        $this->assertArrayNotHasKey('plain_text_token', app(TokenService::class)->change($other, $owner, (string) $issued['token']->id, 'update', ['name' => 'Renamed', 'abilities' => ['read'], 'expires_at' => now()->addDay()]));
    }

    public function test_custom_guard_provider_and_identity_are_restored(): void
    {
        config(['nova.guard' => 'nova', 'auth.guards.nova' => ['driver' => 'session', 'provider' => 'nova_users'], 'auth.providers.nova_users' => ['driver' => 'eloquent', 'model' => NovaUser::class]]);
        $web = $this->user('web');
        $nova = NovaUser::create(['name' => 'nova', 'tenant_id' => 1]);
        Auth::guard('web')->setUser($web);
        $issued = $this->token($nova);
        $this->rpc($issued['plain_text_token'], 'ping')->assertOk();
        $this->assertSame('web', Auth::getDefaultDriver());
        $this->assertTrue(Auth::guard('web')->user()->is($web));
        $this->assertFalse(Auth::guard('nova')->hasUser());
        $this->assertFalse(Auth::guard('nova-mcp')->hasUser());
        $this->assertSame('nova_users', $issued['token']->provider);
        $issued['token']->update(['tokenable_type' => $web::class]);
        $this->rpc($issued['plain_text_token'], 'ping')->assertUnauthorized();
    }

    public function test_token_abilities_limit_discovery_and_execution(): void
    {
        $issued = $this->token(null, ['read']);
        $tools = $this->rpc($issued['plain_text_token'], 'tools/list');
        $names = array_column($tools->json('result.tools'), 'name');
        $this->assertContains('nova.get', $names);
        $this->assertNotContains('nova.delete', $names);
        $this->callTool($issued['plain_text_token'], 'create', ['resource' => 'records', 'fields' => ['name' => 'test']])->assertJsonPath('error.code', -32602);
    }

    public function test_origins_and_https_are_checked(): void
    {
        $issued = $this->token();
        $this->withHeader('Origin', 'https://evil.example')->rpc($issued['plain_text_token'], 'ping')->assertForbidden();
    }

    public function test_nonexpiring_unknown_abilities_and_limits_are_rejected(): void
    {
        $user = $this->user();
        foreach ([['abilities' => ['admin'], 'expires_at' => now()->addDay()], ['abilities' => ['read'], 'expires_at' => null], ['abilities' => ['*', 'read'], 'expires_at' => now()->addDay()], ['abilities' => ['read'], 'expires_at' => now()->addYears(2)]] as $data) {
            try {
                app(TokenService::class)->create($user, $user, ['name' => 'bad'] + $data);
                $this->fail('Invalid token accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        config(['nova-mcp.tokens.max_per_user' => 1]);
        $this->token($user);
        $this->expectException(ValidationException::class);
        $this->token($user);
    }
}
