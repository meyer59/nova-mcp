<?php

namespace NovaMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use NovaMcp\Auth\ProviderResolver;
use NovaMcp\Auth\TokenAuthenticator;

class Authenticate
{
    public function handle(Request $request, Closure $next): mixed
    {
        $origin = $request->header('Origin');
        $app = parse_url(config('app.url'));
        $appOrigin = ($app['scheme'] ?? 'https').'://'.($app['host'] ?? '').(isset($app['port']) ? ':'.$app['port'] : '');
        abort_if(count($request->headers->all('origin')) > 1 || ($origin !== null && ! in_array($origin, array_merge([$appOrigin], config('nova-mcp.allowed_origins', [])), true)), 403);
        abort_if(config('nova-mcp.require_https') && ! app()->environment('local', 'testing') && ! $request->isSecure(), 400, 'HTTPS is required.');

        $user = app(TokenAuthenticator::class)->authenticate($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $auth = Auth::getFacadeRoot();
        $default = $auth->getDefaultDriver();
        $authResolver = $auth->userResolver();
        $requestResolver = $request->getUserResolver();
        $providers = app(ProviderResolver::class);
        $providerName = $providers->name();
        $oldMcpConfig = config('auth.guards.nova-mcp');
        $novaGuardName = $providers->guard();
        $novaGuard = $auth->guard($novaGuardName);
        $oldNovaUser = $novaGuard->user();
        $mcpGuard = $auth->guard('nova-mcp');
        $oldMcpUser = $mcpGuard->hasUser() ? $mcpGuard->user() : null;

        try {
            config(['auth.guards.nova-mcp.provider' => $providerName]);
            $novaGuard->setUser($user);
            $mcpGuard->setUser($user);
            $auth->shouldUse('nova-mcp');
            $request->setUserResolver(fn ($guard = null) => in_array($guard, [null, 'nova-mcp', $novaGuardName], true) ? $user : $requestResolver($guard));

            $token = $request->attributes->get('nova-mcp.token');
            // Never save a stale token object: rotation/revocation may have happened concurrently.
            $token->newQuery()->whereKey($token->id)->where('token', $token->getRawOriginal('token'))
                ->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinute()))
                ->update(['last_used_at' => now()]);
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } finally {
            $oldNovaUser ? $novaGuard->setUser($oldNovaUser) : $novaGuard->forgetUser();
            $oldMcpUser ? $mcpGuard->setUser($oldMcpUser) : $mcpGuard->forgetUser();
            $auth->shouldUse($default);
            config(['auth.guards.nova-mcp' => $oldMcpConfig]);
            $auth->resolveUsersUsing($authResolver);
            $request->setUserResolver($requestResolver);
            $request->attributes->remove('nova-mcp.token');
        }
    }
}
