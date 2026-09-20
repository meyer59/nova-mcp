<?php

namespace NovaMcp\Tests;

use Composer\InstalledVersions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaCoreServiceProvider;
use NovaMcp\McpAccess;
use NovaMcp\NovaMcpServiceProvider;
use NovaMcp\Tests\Fixtures\CategoryResource;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordPolicy;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\Fixtures\User;
use NovaMcp\Tokens\TokenService;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [NovaCoreServiceProvider::class, McpServiceProvider::class, NovaMcpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('nova.guard', 'web');
        $app['config']->set('nova.middleware', ['web']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('nova-mcp.rate_limit', 1000);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['users', 'nova_users'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->integer('tenant_id')->default(1);
                $t->string('password')->nullable();
                $t->rememberToken();
                $t->timestamps();
            });
        }
        Schema::create('records', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('tenant_id');
            $t->string('secret')->nullable();
            $t->string('custom')->nullable();
            $t->unsignedBigInteger('category_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        (require __DIR__.'/../database/migrations/2026_09_18_000000_create_nova_mcp_tokens_table.php')->up();
        $novaPath = InstalledVersions::getInstallPath('laravel/nova');
        $migration = glob($novaPath.'/database/migrations/*create_action_events_table*')[0];
        require_once $migration;
        (new \CreateActionEventsTable)->up();
        require_once $novaPath.'/database/migrations/2019_05_10_000000_add_fields_to_action_events_table.php';
        (new \AddFieldsToActionEventsTable)->up();
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('tenant_id');
            $t->timestamps();
        });
        Nova::$resources = [RecordResource::class, CategoryResource::class];
        Nova::$tools = [new McpAccess];
        Nova::auth(fn ($request) => $request->user() && $request->user()->name !== 'no-nova');
        Gate::policy(Record::class, RecordPolicy::class);
    }

    protected function user(string $name = 'admin'): User
    {
        return User::create(['name' => $name, 'tenant_id' => 1]);
    }

    protected function token(?User $user = null, array $abilities = ['*']): array
    {
        $user ??= $this->user();

        return app(TokenService::class)->create($user, $user, ['name' => 'Test', 'abilities' => $abilities, 'expires_at' => now()->addDay()->toIso8601String()]);
    }

    protected function rpc(string $token, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
            ->postJson('/mcp/nova', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }

    protected function callTool(string $token, string $tool, array $arguments = []): TestResponse
    {
        return $this->rpc($token, 'tools/call', ['name' => 'nova.'.$tool, 'arguments' => (object) $arguments]);
    }

    protected function payload(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse($response->json('result.isError') ?? false, $response->getContent());

        return json_decode($response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);
    }
}
