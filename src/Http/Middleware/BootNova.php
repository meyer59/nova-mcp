<?php

namespace NovaMcp\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;

class BootNova
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Nova applications register resources and their access gate in ServingNova.
        // Save request-sensitive state so long-running workers cannot retain it.
        $state = [Nova::$resources, Nova::$resourcesByModel, Nova::$tools, Nova::$dashboards, Nova::$authUsing, Nova::$jsonVariables];
        $exceptionHandler = app(ExceptionHandler::class);
        $strict = Model::preventsAccessingMissingAttributes();
        $previousRequest = app()->bound(NovaRequest::class) ? app(NovaRequest::class) : null;
        try {
            Model::preventAccessingMissingAttributes(false);
            app()->forgetInstance(NovaRequest::class);
            ServingNova::dispatch(app(), $request);
            abort_unless(Nova::check($request), 403);
            if (Gate::has('accessNovaMcp')) {
                abort_unless(Gate::forUser($request->user())->allows('accessNovaMcp', [$request]), 403);
            }

            return $next($request);
        } finally {
            [Nova::$resources, Nova::$resourcesByModel, Nova::$tools, Nova::$dashboards, Nova::$authUsing, Nova::$jsonVariables] = $state;
            app()->instance(ExceptionHandler::class, $exceptionHandler);
            Model::preventAccessingMissingAttributes($strict);
            app()->forgetInstance(NovaRequest::class);
            if ($previousRequest !== null) {
                app()->instance(NovaRequest::class, $previousRequest);
            }
        }
    }
}
