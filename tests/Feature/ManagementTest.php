<?php

namespace NovaMcp\Tests\Feature;

use Laravel\Nova\Nova;
use NovaMcp\McpAccess;
use NovaMcp\Tests\TestCase;

class ManagementTest extends TestCase
{
    public function test_management_requires_nova_session_and_tool_authorization(): void
    {
        $this->getJson('/nova-vendor/nova-mcp/tokens')->assertUnauthorized();
        $user = $this->user();
        $this->actingAs($user, 'web')->getJson('/nova-vendor/nova-mcp/tokens')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Nova::$tools = [(new McpAccess)->canSee(fn () => false)];
        $this->getJson('/nova-vendor/nova-mcp/tokens')->assertForbidden();
    }

    public function test_management_endpoints_do_not_reveal_secrets_or_allow_cross_owner_ids(): void
    {
        $user = $this->user();
        $other = $this->user();
        $otherToken = $this->token($other);
        $this->actingAs($user)->postJson('/nova-vendor/nova-mcp/tokens', ['name' => 'New', 'abilities' => ['read'], 'expires_at' => now()->addDay()])->assertCreated()->assertJsonStructure(['token', 'plain_text_token']);
        $response = $this->getJson('/nova-vendor/nova-mcp/tokens')->assertOk();
        $this->assertCount(1, $response->json('tokens'));
        $this->assertArrayNotHasKey('token', $response->json('tokens.0'));
        $this->postJson('/nova-vendor/nova-mcp/tokens/'.$otherToken['token']->id.'/rotate')->assertNotFound();
        $this->getJson('/nova-vendor/nova-mcp/tokens?owner='.$other->id)->assertNotFound();
        $this->getJson('/nova-vendor/nova-mcp/tokens?owner=999999')->assertNotFound();
    }

    public function test_all_owned_tokens_remain_reachable_through_pagination(): void
    {
        $user = $this->user();
        $issued = $this->token($user)['token'];
        for ($i = 0; $i < 50; $i++) {
            $copy = $issued->replicate();
            $copy->token = hash('sha256', 'test-token-'.$i);
            $copy->revoked_at = now();
            $copy->save();
        }
        $first = $this->actingAs($user)->getJson('/nova-vendor/nova-mcp/tokens')->assertOk()->assertJsonPath('has_more', true);
        $second = $this->getJson('/nova-vendor/nova-mcp/tokens?page=2')->assertOk()->assertJsonPath('has_more', false);
        $this->assertCount(50, $first->json('tokens'));
        $this->assertCount(1, $second->json('tokens'));
        $this->assertSame($issued->id, $second->json('tokens.0.id'));
    }
}
