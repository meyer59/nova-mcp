<?php

namespace NovaMcp;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Nova\Nova;
use NovaMcp\Auth\TokenAuthenticator;
use NovaMcp\Fields\FieldRegistry;
use NovaMcp\Http\Controllers\TokenController;
use NovaMcp\Http\Middleware\Authenticate;
use NovaMcp\Http\Middleware\AuthorizeTool;
use NovaMcp\Http\Middleware\BootNova;
use NovaMcp\Http\Middleware\ValidateHost;
use NovaMcp\Mcp\NovaServer;

class NovaMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nova-mcp.php', 'nova-mcp');
        $this->app->singleton(FieldRegistry::class);
        $this->app['config']->set('auth.guards.nova-mcp', ['driver' => 'nova-mcp']);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/nova-mcp.php' => config_path('nova-mcp.php')], 'nova-mcp-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'nova-mcp-migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Auth::viaRequest('nova-mcp', fn (Request $request) => app(TokenAuthenticator::class)->authenticate($request));
        RateLimiter::for('nova-mcp-ip', fn (Request $r) => Limit::perMinute(config('nova-mcp.rate_limit'))->by($r->ip()));
        RateLimiter::for('nova-mcp-token', fn (Request $r) => Limit::perMinute(config('nova-mcp.rate_limit'))->by((string) $r->attributes->get('nova-mcp.token')?->id));
        RateLimiter::for('nova-mcp-management', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));

        Route::middleware(array_merge([ValidateHost::class, 'throttle:nova-mcp-ip', Authenticate::class, 'throttle:nova-mcp-token'], config('nova-mcp.middleware', []), [BootNova::class]))
            ->group(fn () => Mcp::web(config('nova-mcp.path'), NovaServer::class));

        $this->app->booted(function () {
            $middleware = ['nova', 'nova.auth', AuthorizeTool::class, 'throttle:nova-mcp-management'];
            Route::middleware($middleware)->prefix('nova-vendor/nova-mcp')->group(function () {
                Route::get('tokens', TokenController::class);
                Route::post('tokens', TokenController::class);
                Route::patch('tokens/{id}', TokenController::class)->whereNumber('id');
                Route::post('tokens/{id}/{operation}', TokenController::class)->whereNumber('id')->whereIn('operation', ['rotate', 'revoke']);
            });
            Nova::router($middleware, 'mcp-access')->group(function () {
                Route::get('/', fn () => Inertia::render('NovaMcpAccess'));
            });
        });
    }
}
