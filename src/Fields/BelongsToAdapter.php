<?php

namespace NovaMcp\Fields;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceIndexRequest;
use NovaMcp\Nova\RequestContext;
use NovaMcp\Nova\ResourceRegistry;

class BelongsToAdapter implements FieldAdapter
{
    public function schema(Field $field, NovaRequest $request): array
    {
        $field = $this->field($field);

        return ['type' => ['string', 'null'], 'description' => 'Related '.$field->resourceName.' record ID. Use nova.relationships for permitted candidates.'];
    }

    public function readable(Field $field, NovaRequest $request): bool
    {
        $field = $this->field($field);

        return ($request->attributes->get('nova-mcp.token')?->allows('relationships') ?? false)
            && in_array($field->resourceClass, app(ResourceRegistry::class)->all($request), true);
    }

    public function writable(Field $field, NovaRequest $request): bool
    {
        return $this->readable($field, $request) && ! $field->isReadonly($request);
    }

    public function value(Field $field, NovaRequest $request): mixed
    {
        $field = $this->field($field);
        if ($field->belongsToId === null) {
            return null;
        }
        $class = $field->resourceClass;
        $context = app(RequestContext::class);
        $related = $context->make(ResourceIndexRequest::class, $class::uriKey());

        return $context->run($related, function () use ($class, $related, $field) {
            if (! $class::authorizedToViewAny($related)) {
                return null;
            }
            $model = $class::indexQuery($related, $class::newModel()->newQuery())->whereKey($field->belongsToId)->first();

            return $model && (new $class($model))->authorizedToView($related) ? (string) $model->getKey() : null;
        });
    }

    public function prepare(Field $field, mixed $value, NovaRequest $request): mixed
    {
        $field = $this->field($field);
        if ($value === null) {
            return null;
        }
        abort_unless(is_string($value) && strlen($value) <= 160, 422);
        $class = $field->resourceClass;
        $query = $field->buildAssociatableQuery($request, $class, false)->toBase();
        $context = app(RequestContext::class);
        $related = $context->make(ResourceIndexRequest::class, $class::uriKey());
        $context->run($related, function () use ($class, $related, $query, $value) {
            abort_unless($class::authorizedToViewAny($related), 422);
            $model = $class::newModel();
            $key = $model->getQualifiedKeyName();
            $scoped = $class::indexQuery($related, $model->newQuery());
            $scoped->whereIn($key, $query->select($key));
            $model = $scoped->whereKey($value)->first();
            abort_unless($model && (new $class($model))->authorizedToView($related), 422);
        });

        return $value;
    }

    private function field(Field $field): BelongsTo
    {
        if (! $field instanceof BelongsTo) {
            throw new \InvalidArgumentException('Expected a Nova BelongsTo field.');
        }

        return $field;
    }
}
