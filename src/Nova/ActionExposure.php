<?php

namespace NovaMcp\Nova;

use Laravel\Nova\Actions\Action;
use Laravel\Nova\Http\Requests\NovaRequest;
use NovaMcp\NovaMcp;
use NovaMcp\Support\Audit;

class ActionExposure
{
    public function allows(Action $action, ?NovaRequest $request = null): bool
    {
        $included = config('nova-mcp.included_actions', []);
        $excluded = config('nova-mcp.excluded_actions', []);
        $allowed = $this->validList($included) && $this->validList($excluded)
            && ! $this->matches($action, $excluded)
            && ($included === [] || $this->matches($action, $included));

        if (! $allowed && $request && $request->query('action') === $action->uriKey()
            && ! $request->attributes->get('nova-mcp.hidden-action-logged', false)) {
            $request->attributes->set('nova-mcp.hidden-action-logged', true);
            app(Audit::class)->debug('action.hidden', [
                'token_id' => NovaMcp::token($request)?->id,
                'tool' => 'nova.run_action', 'action' => $action->uriKey(),
            ]);
        }

        return $allowed;
    }

    private function validList(mixed $classes): bool
    {
        return is_array($classes) && count(array_filter($classes, fn ($class) => is_string($class) && $class !== '')) === count($classes);
    }

    private function matches(Action $action, array $classes): bool
    {
        foreach ($classes as $class) {
            if (is_a($action, $class)) {
                return true;
            }
        }

        return false;
    }

    public function fullResult(Action $action): bool
    {
        $classes = config('nova-mcp.full_result_actions', []);

        return config('nova-mcp.action_results', 'status') === 'full'
            || ($this->validList($classes) && in_array($action::class, $classes, true));
    }
}
