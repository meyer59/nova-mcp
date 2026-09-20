<?php

namespace NovaMcp\Nova;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use NovaMcp\Fields\FieldRegistry;
use NovaMcp\Mcp\ValidationFailure;
use NovaMcp\Nova\Requests\UpdateRequest;

/**
 * A request-local validation bridge for Nova's unchanged update controller.
 * Application resource methods always receive the original resource.
 */
class UpdateResource extends Resource
{
    public function __construct(public Resource $original)
    {
        parent::__construct($original->model());
    }

    private static function updateRequest(Request $request): UpdateRequest
    {
        if (! $request instanceof UpdateRequest) {
            throw new \LogicException('The update bridge requires an MCP update request.');
        }

        return $request;
    }

    public function fields(NovaRequest $request)
    {
        $request = self::updateRequest($request);

        return $request->withOriginalResource(fn () => $this->original->fields($request));
    }

    public function authorizeToUpdate(Request $request)
    {
        $request = self::updateRequest($request);

        return $request->withOriginalResource(fn () => $this->original->authorizeToUpdate($request));
    }

    public static function validateForUpdate(NovaRequest $request, ?Resource $resource = null): void
    {
        if (! $resource instanceof self) {
            throw new \LogicException('Expected the MCP update resource.');
        }
        $original = $resource->original;
        $request = self::updateRequest($request);
        $class = $request->resource();
        $input = $request->all();
        try {
            $request->withOriginalResource(function () use ($request, $class, $original, &$input) {
                $state = app(UpdateState::class);
                $request->replace($input + $state->values($original, $request));
                $request->replace($input + $state->forFields($original, $request));
                // Recheck writable fields against the controller's freshly loaded model.
                app(FieldRegistry::class)->prepare($original->updateFields($request)->applyDependsOn($request)->onlyUpdateFields($request, $original->model()), $input, $request);
                $class::validateForUpdate($request, $original);
                // Keep hook normalization of submitted values, without turning
                // validation-only stored values into submitted fields.
                $input = array_intersect_key($request->all(), $input);
            });
        } finally {
            $request->replace($input);
        }
    }

    public static function fillForUpdate(NovaRequest $request, $model): array
    {
        $request = self::updateRequest($request);
        $class = $request->resource();

        return $request->withOriginalResource(function () use ($request, $class, $model) {
            $resource = new $class($model);
            try {
                // Do not report success if Nova would silently skip a submitted field
                // because its fill-time dependencies require additional form input.
                app(FieldRegistry::class)->prepare($resource->updateFields($request)->applyDependsOn($request)->onlyUpdateFields($request, $model), $request->all(), $request);
            } catch (ValidationException $exception) {
                throw new ValidationFailure(['_' => ['Nova needs additional form context for this update. Supply the relevant writable dependent fields, or use the Nova form.']]);
            }

            return $class::fillForUpdate($request, $model);
        });
    }

    public static function beforeUpdate(NovaRequest $request, Model $model)
    {
        $request = self::updateRequest($request);
        $class = $request->resource();
        $request->withOriginalResource(fn () => $class::beforeUpdate($request, $model));
    }

    public static function afterUpdate(NovaRequest $request, Model $model)
    {
        $request = self::updateRequest($request);
        $class = $request->resource();
        $request->withOriginalResource(fn () => $class::afterUpdate($request, $model));
    }

    public static function redirectAfterUpdate(NovaRequest $request, Resource $resource)
    {
        $request = self::updateRequest($request);
        $class = $request->resource();
        $original = $resource instanceof self ? $resource->original : $resource;

        return $request->withOriginalResource(fn () => $class::redirectAfterUpdate($request, $original));
    }
}
