<?php

namespace NovaMcp\Nova;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Http\Requests\NovaRequest;
use NovaMcp\Mcp\ValidationFailure;

class RequestContext
{
    public function make(string $type, string $resource, array $parameters = [], ?string $id = null, string $method = 'GET'): NovaRequest
    {
        $outer = request();
        $server = $outer->server->all();
        unset($server['CONTENT_TYPE'], $server['CONTENT_LENGTH'], $server['HTTP_AUTHORIZATION']);
        $request = $type::create('/nova-api/'.$resource.($id !== null ? '/'.rawurlencode($id) : ''), $method, $parameters, [], [], $server);
        $route = new Route($method, 'nova-api/{resource}/{resourceId?}', fn () => null);
        $route->bind($request);
        $route->setParameter('resource', $resource);
        if ($id !== null) {
            $route->setParameter('resourceId', $id);
        }
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver($outer->getUserResolver());
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->headers->set('Accept', 'application/json');
        $request->attributes->add($outer->attributes->all());

        return $request;
    }

    public function run(NovaRequest $request, Closure $callback): mixed
    {
        $outer = app('request');
        $previousNova = app()->bound(NovaRequest::class) ? app(NovaRequest::class) : null;
        app()->instance('request', $request);
        app()->instance(NovaRequest::class, $request);
        Facade::clearResolvedInstance('request');
        try {
            return $callback($request);
        } catch (ValidationException $exception) {
            throw ValidationFailure::from($exception, $request->attributes->get('nova-mcp.error-fields', []));
        } finally {
            app()->instance('request', $outer);
            Facade::clearResolvedInstance('request');
            app()->forgetInstance(NovaRequest::class);
            if ($previousNova !== null) {
                app()->instance(NovaRequest::class, $previousNova);
            }
        }
    }
}
