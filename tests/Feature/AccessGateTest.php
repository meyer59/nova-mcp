<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Nova;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\User;
use NovaMcp\Tests\TestCase;

class AccessGateTest extends TestCase
{
    public function test_undefined_gate_preserves_nova_access_checks(): void
    {
        $this->assertFalse(Gate::has('accessNovaMcp'));
        $this->rpc($this->token()['plain_text_token'], 'ping')->assertOk();
        $this->rpc($this->token($this->user('no-nova'))['plain_text_token'], 'ping')->assertForbidden();
    }

    public function test_denied_gate_blocks_initialization_discovery_and_mutations(): void
    {
        // Register during Nova boot, as applications may do in NovaServiceProvider.
        Nova::serving(fn () => Gate::define('accessNovaMcp', fn ($user) => false));
        $token = $this->token()['plain_text_token'];

        foreach (['initialize', 'ping', 'tools/list', 'resources/list', 'prompts/list'] as $method) {
            $this->rpc($token, $method)->assertForbidden();
        }
        $this->callTool($token, 'create', ['resource' => 'records', 'fields' => ['name' => 'blocked']])->assertForbidden();
        $this->assertSame(0, Record::count());
        $this->assertSame('web', Auth::getDefaultDriver());
        $this->assertNull(Auth::user());
    }

    public function test_gate_checks_token_owner_email_and_client_ip_on_each_request(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable());
        $owner = $this->user();
        $owner->update(['email' => 'admin@example.com']);
        $token = $this->token($owner)['plain_text_token'];
        $other = $this->user('other');
        $other->update(['email' => 'other@example.com']);
        $otherToken = $this->token($other)['plain_text_token'];
        // A pre-existing session must not determine the bearer user's access.
        $this->actingAs($other);
        Gate::define('accessNovaMcp', function (User $user, Request $request) {
            $this->assertSame($user, $request->user());
            $this->assertSame($user, Auth::user());

            return $user->email === 'admin@example.com' && $request->ip() === '203.0.113.10';
        });

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->rpc($token, 'ping')->assertOk();
        $this->rpc($otherToken, 'ping')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11']);
        $this->rpc($token, 'ping')->assertForbidden();
        $this->assertSame($other, Auth::user());
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $owner->update(['email' => 'removed@example.com']);
        $this->rpc($token, 'ping')->assertForbidden();
    }

    public function test_allowing_gate_does_not_override_nova_or_token_restrictions(): void
    {
        Gate::define('accessNovaMcp', fn ($user) => true);
        $this->rpc($this->token($this->user('no-nova'))['plain_text_token'], 'ping')->assertForbidden();
        $readOnly = $this->token(abilities: ['read'])['plain_text_token'];
        $this->callTool($readOnly, 'resources')->assertOk();
        $this->callTool($readOnly, 'create', ['resource' => 'records', 'fields' => ['name' => 'blocked']])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame(0, Record::count());
    }
}
