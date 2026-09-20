<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use NovaMcp\Models\Token;
use NovaMcp\NovaMcp;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\TestCase;
use Psr\Log\LoggerInterface;

class ReleaseSecurityTest extends TestCase
{
    private function tokenInput(array $overrides = []): array
    {
        return array_replace(['name' => 'Client', 'abilities' => ['read'], 'expires_at' => now()->addDay()->toIso8601String()], $overrides);
    }

    public function test_browser_session_and_query_string_cannot_replace_bearer_authentication(): void
    {
        $owner = $this->user();
        $token = $this->token($owner)['plain_text_token'];
        $this->actingAs($owner);
        $this->postJson('/mcp/nova?access_token='.urlencode($token), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        foreach (['', 'invalid', $token.'suffix', str_replace('nvm_', 'other_', $token), 'nvm_0_'.str_repeat('a', 64), substr($token, 0, -1)] as $invalid) {
            $this->rpc($invalid, 'ping')->assertUnauthorized();
        }
        $this->rpc($token, 'ping')->assertOk();
    }

    public function test_creation_cannot_inject_owner_hash_provider_or_revocation_metadata(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $response = $this->actingAs($owner)->postJson('/nova-vendor/nova-mcp/tokens', $this->tokenInput([
            'tokenable_id' => (string) $other->id, 'tokenable_type' => 'InjectedClass', 'provider' => 'other',
            'token' => str_repeat('a', 64), 'revoked_at' => now(), 'last_used_at' => now(), 'id' => 999,
        ]))->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $token = Token::findOrFail($response->json('token.id'));
        $this->assertSame((string) $owner->id, $token->tokenable_id);
        $this->assertSame($owner::class, $token->tokenable_type);
        $this->assertSame('users', $token->provider);
        $this->assertNull($token->revoked_at);
        $this->assertNull($token->last_used_at);
        $this->assertNotSame(str_repeat('a', 64), $token->token);
        foreach (['token', 'provider', 'tokenable_id', 'tokenable_type'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $response->json('token'));
        }
        $this->rpc($response->json('plain_text_token'), 'ping')->assertOk();
    }

    public function test_every_management_mutation_rejects_foreign_token_ids(): void
    {
        $owner = $this->user();
        $attacker = $this->user();
        $issued = $this->token($owner);
        $path = '/nova-vendor/nova-mcp/tokens/'.$issued['token']->id;
        $this->actingAs($attacker);
        foreach (['', '?owner='.$owner->id] as $query) {
            $this->patchJson($path.$query, $this->tokenInput(['abilities' => ['*']]))->assertNotFound();
            $this->postJson($path.'/rotate'.$query)->assertNotFound();
            $this->postJson($path.'/revoke'.$query)->assertNotFound();
        }
        $this->postJson('/nova-vendor/nova-mcp/tokens?owner='.$owner->id, $this->tokenInput())->assertNotFound();
        $this->getJson('/nova-vendor/nova-mcp/tokens?owner[]=1')->assertUnprocessable();
        $this->assertSame(1, Token::count());
        $this->rpc($issued['plain_text_token'], 'ping')->assertOk();
    }

    public function test_administrator_issuance_uses_target_identity_and_ability_changes_apply_immediately(): void
    {
        $admin = $this->user('administrator');
        $target = $this->user('reader');
        NovaMcp::manageTokensUsing(fn ($actor, $owner) => $actor->is($admin) && $owner->is($target));
        $response = $this->actingAs($admin)->postJson('/nova-vendor/nova-mcp/tokens?owner='.$target->id, $this->tokenInput(['abilities' => ['*']]))->assertCreated();
        $id = $response->json('token.id');
        $plain = $response->json('plain_text_token');
        $this->assertSame((string) $target->id, Token::findOrFail($id)->tokenable_id);
        $this->callTool($plain, 'create', ['resource' => 'records', 'fields' => ['name' => 'denied']])->assertJsonPath('result.isError', true);
        $this->patchJson('/nova-vendor/nova-mcp/tokens/'.$id.'?owner='.$target->id, $this->tokenInput())->assertOk()->assertJsonMissingPath('plain_text_token');
        $names = array_column($this->rpc($plain, 'tools/list')->json('result.tools'), 'name');
        $this->assertContains('nova.get', $names);
        $this->assertNotContains('nova.create', $names);
        $this->assertSame(0, Record::count());
    }

    public function test_revoked_and_expired_tokens_cannot_be_reactivated_through_management(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);
        foreach (['revoked_at', 'expires_at'] as $column) {
            $token = $this->token($owner);
            $token['token']->update([$column => now()->subSecond()]);
            $path = '/nova-vendor/nova-mcp/tokens/'.$token['token']->id;
            $this->patchJson($path, $this->tokenInput(['revoked_at' => null]))->assertUnprocessable();
            $this->postJson($path.'/rotate')->assertUnprocessable();
            $this->rpc($token['plain_text_token'], 'ping')->assertUnauthorized();
        }
    }

    public function test_token_limits_and_validation_are_enforced_over_http(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);
        foreach ([['abilities' => ['unknown']], ['abilities' => ['*', 'read']], ['abilities' => ['read', 'read']], ['expires_at' => null], ['expires_at' => now()->addYears(2)], ['expires_at' => now()->subDay()]] as $invalid) {
            $this->postJson('/nova-vendor/nova-mcp/tokens', $this->tokenInput($invalid))->assertUnprocessable();
        }
        $this->assertSame(0, Token::count());
        config(['nova-mcp.tokens.max_per_user' => 1]);
        $issued = $this->postJson('/nova-vendor/nova-mcp/tokens', $this->tokenInput())->assertCreated();
        $this->postJson('/nova-vendor/nova-mcp/tokens', $this->tokenInput())->assertUnprocessable();
        $this->postJson('/nova-vendor/nova-mcp/tokens/'.$issued->json('token.id').'/revoke')->assertOk()->assertJsonMissingPath('plain_text_token');
        $this->postJson('/nova-vendor/nova-mcp/tokens', $this->tokenInput())->assertCreated();
    }

    public function test_origin_allowlist_uses_exact_origins_and_allows_native_clients(): void
    {
        config(['app.url' => 'https://nova.example.test', 'nova-mcp.allowed_origins' => ['https://client.example.test']]);
        app('url')->forceRootUrl('https://nova.example.test');
        $plain = $this->token()['plain_text_token'];
        $this->rpc($plain, 'ping')->assertOk();
        foreach (['null', 'https://nova.example.test.evil.test', 'http://nova.example.test', 'https://nova.example.test:444', 'https://evil.test'] as $origin) {
            $this->withHeader('Origin', $origin)->rpc($plain, 'ping')->assertForbidden();
        }
        foreach (['https://nova.example.test', 'https://client.example.test'] as $origin) {
            $this->withHeader('Origin', $origin)->rpc($plain, 'ping')->assertOk();
        }
    }

    public function test_mixed_tenant_action_selection_is_rejected_before_any_side_effect(): void
    {
        $own = Record::create(['name' => 'OWN', 'tenant_id' => 1]);
        $foreign = Record::create(['name' => 'FOREIGN', 'tenant_id' => 2]);
        $plain = $this->token()['plain_text_token'];
        $action = $this->payload($this->callTool($plain, 'actions', ['resource' => 'records', 'ids' => [(string) $own->id]]))['actions'][0]['key'];
        $this->callTool($plain, 'run_action', ['resource' => 'records', 'action' => $action, 'ids' => [(string) $own->id, (string) $foreign->id]])->assertJsonPath('result.isError', true);
        $this->assertSame('OWN', $own->fresh()->name);
        $this->assertSame('FOREIGN', $foreign->fresh()->name);
        $this->assertDatabaseCount('action_events', 0);
    }

    public function test_unexpected_application_errors_do_not_expose_exception_messages_or_credentials(): void
    {
        Nova::$resources = [FailingResource::class];
        $plain = $this->token()['plain_text_token'];
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->withArgs(function ($event, $metadata) use ($plain) {
            $this->assertSame('nova-mcp.operation.failed', $event);
            $this->assertStringNotContainsString('private-database-message', json_encode($metadata));
            $this->assertStringNotContainsString($plain, json_encode($metadata));

            return true;
        });
        Log::shouldReceive('channel')->andReturn($logger);
        $response = $this->callTool($plain, 'describe', ['resource' => 'records'])->assertJsonPath('result.isError', true);
        $this->assertStringNotContainsString('private-database-message', $response->getContent());
        $this->assertStringNotContainsString($plain, $response->getContent());
    }
}

class FailingResource extends RecordResource
{
    public function fields(NovaRequest $request): array
    {
        throw new \RuntimeException('private-database-message');
    }
}
