<?php

namespace NovaMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use NovaMcp\McpAccess;

class AuthorizeTool
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(Nova::user($request) && Nova::check($request), 403);
        $tool = collect(Nova::registeredTools())->first(fn ($tool) => $tool instanceof McpAccess);
        abort_unless($tool && $tool->authorize($request), 403);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
