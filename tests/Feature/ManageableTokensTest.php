<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Support\Facades\Auth;
use NovaMcp\Models\Token;
use NovaMcp\NovaMcp;
use NovaMcp\Tests\TestCase;
use NovaMcp\Tokens\TokenService;

class ManageableTokensTest extends TestCase
{
    private const URL = '/nova-vendor/nova-mcp/tokens?scope=manageable';

    public function test_default_listing_only_shows_own_tokens_without_secrets(): void
    {
        $actor = $this->user();
        $own = $this->token($actor);
        $this->token($this->user('Other user'));
        $this->getJson(self::URL)->assertUnauthorized();
        $response = $this->actingAs($actor)->getJson(self::URL)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'tokens')->assertJsonPath('has_more', false)
            ->assertJsonPath('tokens.0.id', $own['token']->id)
            ->assertJsonPath('tokens.0.owner.id', (string) $actor->id)
            ->assertJsonPath('tokens.0.owner.is_self', true);
        foreach (['token', 'provider', 'tokenable_type', 'tokenable_id', 'plain_text_token'] as $hidden) {
            $response->assertJsonMissingPath('tokens.0.'.$hidden);
        }
        $this->assertStringNotContainsString($own['plain_text_token'], $response->getContent());
    }

    public function test_each_owner_is_authorized_before_their_metadata_is_listed(): void
    {
        $actor = $this->user();
        $allowed = $this->user('Allowed user');
        $denied = $this->user('Hidden user');
        $own = $this->token($actor)['token'];
        $other = $this->token($allowed)['token'];
        $this->token($denied);
        NovaMcp::manageTokensUsing(fn ($user, $target) => $user->is($actor) && $target->is($allowed));

        $response = $this->actingAs($actor)->getJson(self::URL)->assertOk()->assertJsonCount(2, 'tokens')
            ->assertJsonPath('tokens.0.owner.name', 'Allowed user')
            ->assertJsonPath('tokens.0.owner.is_self', false);
        $this->assertSame([$other->id, $own->id], array_column($response->json('tokens'), 'id'));
        $this->assertStringNotContainsString('Hidden user', $response->getContent());

        // Listing access is never an authorization grant for a later mutation.
        NovaMcp::manageTokensUsing(fn () => false);
        $this->postJson('/nova-vendor/nova-mcp/tokens/'.$other->id.'/rotate?owner='.$allowed->id)->assertNotFound();
        $this->patchJson('/nova-vendor/nova-mcp/tokens/'.$other->id.'?owner='.$allowed->id, [
            'name' => 'Changed', 'abilities' => ['read'], 'expires_at' => now()->addDay(),
        ])->assertNotFound();
        $this->postJson('/nova-vendor/nova-mcp/tokens/'.$other->id.'/revoke?owner='.$allowed->id)->assertNotFound();
    }

    public function test_deleted_owners_other_providers_and_wrong_owner_classes_are_not_listed(): void
    {
        $actor = $this->user();
        $own = $this->token($actor)['token'];
        $deleted = $this->user('Deleted user');
        $this->token($deleted);
        $deleted->delete();
        foreach (['provider' => 'another-provider', 'tokenable_type' => 'AnotherUserClass'] as $key => $value) {
            $copy = $own->replicate();
            $copy->{$key} = $value;
            $copy->token = hash('sha256', $key);
            $copy->save();
        }
        NovaMcp::manageTokensUsing(fn () => true);
        $this->actingAs($actor)->getJson(self::URL)->assertOk()
            ->assertJsonCount(1, 'tokens')->assertJsonPath('tokens.0.id', $own->id);
    }

    public function test_pagination_keeps_all_authorized_tokens_reachable_across_owners(): void
    {
        $actor = $this->user();
        $allowed = $this->user();
        $denied = $this->user();
        $oldest = $this->token($actor)['token'];
        $template = $this->token($allowed)['token'];
        for ($i = 0; $i < 49; $i++) {
            $copy = $template->replicate();
            $copy->token = hash('sha256', 'pagination-'.$i);
            $copy->save();
        }
        $this->token($denied);
        NovaMcp::manageTokensUsing(fn ($user, $target) => $target->is($allowed));
        $first = $this->actingAs($actor)->getJson(self::URL)->assertOk()
            ->assertJsonCount(50, 'tokens')->assertJsonPath('has_more', true);
        $second = $this->getJson(self::URL.'&before='.$first->json('next_cursor'))->assertOk()
            ->assertJsonCount(1, 'tokens')->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null)->assertJsonPath('tokens.0.id', $oldest->id);
        $this->assertCount(51, array_unique(array_merge(
            array_column($first->json('tokens'), 'id'), array_column($second->json('tokens'), 'id'),
        )));
    }

    public function test_restrictive_gates_have_bounded_scans_and_can_continue_to_older_tokens(): void
    {
        $actor = $this->user();
        $own = $this->token($actor)['token'];
        $denied = $this->user();
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = [
                'provider' => 'users', 'tokenable_type' => $denied::class, 'tokenable_id' => (string) $denied->id,
                'name' => 'Hidden', 'token' => hash('sha256', 'bounded-'.$i), 'abilities' => '["read"]',
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            Token::insert($chunk);
        }
        NovaMcp::manageTokensUsing(fn () => false);
        $first = $this->actingAs($actor)->getJson(self::URL)->assertOk()
            ->assertJsonCount(0, 'tokens')->assertJsonPath('has_more', true);
        $this->getJson(self::URL.'&before='.$first->json('next_cursor'))->assertOk()
            ->assertJsonCount(1, 'tokens')->assertJsonPath('tokens.0.id', $own->id)->assertJsonPath('has_more', false);
    }

    public function test_listing_works_with_the_database_user_provider_and_missing_names(): void
    {
        $record = $this->user('');
        config(['auth.providers.users' => ['driver' => 'database', 'table' => 'users']]);
        Auth::forgetGuards();
        $owner = Auth::createUserProvider('users')->retrieveById($record->id);
        $issued = app(TokenService::class)->create($owner, $owner, [
            'name' => 'Database owner', 'abilities' => ['read'], 'expires_at' => now()->addDay(),
        ]);
        $this->actingAs($owner)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'tokens')
            ->assertJsonPath('tokens.0.id', $issued['token']->id)
            ->assertJsonPath('tokens.0.owner.name', null)
            ->assertJsonPath('tokens.0.owner.id', (string) $record->id);
    }

    public function test_listing_scope_cannot_override_a_mutation_owner_and_validates_pagination(): void
    {
        $this->actingAs($this->user());
        $this->postJson(self::URL, [])->assertUnprocessable();
        foreach (['&owner=1', '&before=-1', '&before[]=1', '&before=garbage'] as $query) {
            $this->getJson(self::URL.$query)->assertUnprocessable();
        }
    }
}
