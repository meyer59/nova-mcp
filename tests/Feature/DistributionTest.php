<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use NovaMcp\Tests\Fixtures\User;
use NovaMcp\Tests\TestCase;
use NovaMcp\Tokens\TokenService;

class DistributionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nova-mcp.path', 'integrations/admin-mcp');
        $app['config']->set('nova.path', 'control-panel');
    }

    protected function rpc(string $token, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/integrations/admin-mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }

    public function test_custom_endpoint_and_nova_paths_work_without_registering_the_defaults(): void
    {
        $this->rpc($this->token()['plain_text_token'], 'ping')->assertOk();
        $this->postJson('/mcp/nova', [])->assertNotFound();
        $this->actingAs($this->user())->getJson('/nova-vendor/nova-mcp/tokens')
            ->assertOk()->assertJsonPath('endpoint', 'http://localhost/integrations/admin-mcp');
        $this->assertTrue(collect(app('router')->getRoutes())->contains(fn ($route) => $route->uri() === 'control-panel/mcp-access'));
    }

    public function test_uuid_and_ulid_owners_work_with_a_custom_session_guard(): void
    {
        Schema::create('string_users', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('tenant_id');
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        config(['nova.guard' => 'staff', 'auth.guards.staff' => ['driver' => 'session', 'provider' => 'staff'],
            'auth.providers.staff' => ['driver' => 'eloquent', 'model' => StringKeyUser::class]]);
        Auth::forgetGuards();
        foreach ([(string) Str::uuid(), (string) Str::ulid()] as $id) {
            $owner = StringKeyUser::create(['id' => $id, 'name' => 'admin', 'tenant_id' => 1]);
            $issued = app(TokenService::class)->create($owner, $owner, [
                'name' => 'String key', 'abilities' => ['read'], 'expires_at' => now()->addDay(),
            ]);
            $this->assertSame($id, $issued['token']->tokenable_id);
            $this->rpc($issued['plain_text_token'], 'ping')->assertOk();
            $this->assertSame('web', Auth::getDefaultDriver());
            $this->assertFalse(Auth::guard('staff')->hasUser());
        }
    }

    public function test_database_provider_does_not_require_an_eloquent_user_or_trait(): void
    {
        $user = $this->user();
        config(['nova.guard' => 'staff', 'auth.guards.staff' => ['driver' => 'session', 'provider' => 'staff'],
            'auth.providers.staff' => ['driver' => 'database', 'table' => 'users']]);
        Auth::forgetGuards();
        $owner = Auth::createUserProvider('staff')->retrieveById($user->id);
        $this->assertInstanceOf(GenericUser::class, $owner);
        $issued = app(TokenService::class)->create($owner, $owner, [
            'name' => 'Database provider', 'abilities' => ['read'], 'expires_at' => now()->addDay(),
        ]);
        $this->rpc($issued['plain_text_token'], 'ping')->assertOk();
        $this->assertSame(GenericUser::class, $issued['token']->tokenable_type);
        $this->assertSame('web', Auth::getDefaultDriver());
    }
}

class StringKeyUser extends User
{
    protected $table = 'string_users';

    protected $keyType = 'string';

    public $incrementing = false;
}
