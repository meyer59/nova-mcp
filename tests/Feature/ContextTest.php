<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Support\Facades\Request;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use NovaMcp\Nova\RequestContext;
use NovaMcp\Nova\Requests\UpdateRequest;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\Fixtures\RenameAction;
use NovaMcp\Tests\TestCase;

class ContextTest extends TestCase
{
    public function test_nova_container_and_request_facade_match_the_synthetic_request(): void
    {
        $outer = app('request');
        $outer->server->set('HTTP_HOST', 'tenant.example.test');
        $outer->server->set('HTTP_X_TENANT', 'tenant-context');
        $outer->server->set('CONTENT_TYPE', 'application/json');
        Request::getFacadeRoot();
        $context = app(RequestContext::class);
        $request = $context->make(UpdateRequest::class, 'records', ['name' => 'SYNTHETIC'], '4', 'PUT');
        $context->run($request, function () use ($request) {
            $this->assertSame($request, app(NovaRequest::class));
            $this->assertSame('tenant.example.test', $request->getHost());
            $this->assertSame('tenant-context', $request->header('X-Tenant'));
            $this->assertSame('SYNTHETIC', Request::input('name'));
            $this->assertSame('PUT', request()->method());
        });
        $this->assertSame($outer, app('request'));
        $this->assertSame($outer, Request::getFacadeRoot());
    }

    public function test_discovery_is_recomputed_for_each_user_and_action_can_run_is_enforced(): void
    {
        Nova::$resources = [ContextResource::class];
        $record = Record::create(['name' => 'OWN', 'tenant_id' => 1, 'secret' => 'admin only']);
        $admin = $this->token()['plain_text_token'];
        $reader = $this->token($this->user('reader'))['plain_text_token'];
        $args = ['resource' => 'contexts', 'id' => (string) $record->id];
        $this->assertArrayHasKey('secret', $this->payload($this->callTool($admin, 'get', $args))['fields']);
        $this->assertArrayNotHasKey('secret', $this->payload($this->callTool($reader, 'get', $args))['fields']);
        $this->assertArrayHasKey('secret', $this->payload($this->callTool($admin, 'get', $args))['fields']);
        $actions = $this->payload($this->callTool($admin, 'actions', ['resource' => 'contexts', 'ids' => [(string) $record->id]]));
        $this->assertSame([], $actions['actions']);
    }

    public function test_route_cache_can_serialize_the_package_routes(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (str_contains($route->uri(), 'nova-mcp') || $route->uri() === 'mcp/nova') {
                $route->prepareForSerialization();
                $this->assertIsString(serialize($route));
            }
        }
    }
}

class ContextResource extends RecordResource
{
    public static function uriKey(): string
    {
        return 'contexts';
    }

    public function fields(NovaRequest $request): array
    {
        return [ID::make(), Text::make('Name'), Text::make('Secret')->canSee(fn ($r) => $r->user()->name === 'admin')];
    }

    public function actions(NovaRequest $request): array
    {
        return [(new RenameAction)->canRun(fn () => false)];
    }
}
