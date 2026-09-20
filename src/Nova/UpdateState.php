<?php

namespace NovaMcp\Nova;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

/** Existing state is for validation only; never copy display values into writes. */
class UpdateState
{
    public function values(Resource $resource, NovaRequest $request): array
    {
        $values = [];
        $model = $resource->model();
        $excluded = [];
        if ($model instanceof Authenticatable) {
            $excluded = [$model->getAuthPasswordName(), $model->getRememberTokenName()];
        }
        foreach ($resource->updateFields($request) as $field) {
            if ($field instanceof Password || $field instanceof File) {
                $excluded[] = $field->attribute;
            }
        }
        foreach ($model->getAttributes() as $key => $raw) {
            if ($this->safeKey($key) && ! in_array($key, $excluded, true) && (is_scalar($raw) || $raw === null)) {
                $values[$key] = $raw;
            }
        }

        return $values;
    }

    public function forFields(Resource $resource, NovaRequest $request): array
    {
        $values = $this->values($resource, $request);
        foreach ($resource->updateFields($request) as $field) {
            if (! is_string($field->attribute) || ! $this->safeKey($field->attribute)) {
                continue;
            }
            if (in_array($field::class, [Date::class, DateTime::class], true)) {
                $value = $resource->model()->getAttribute($field->attribute);
                if ($value instanceof \DateTimeInterface) {
                    $values[$field->attribute] = $value->format($field::class === Date::class ? 'Y-m-d' : DATE_ATOM);
                }
            } elseif ($field::class === BelongsTo::class) {
                // The field attribute is the relationship name, not its foreign key.
                $relation = $resource->model()->{$field->attribute}();
                $value = $resource->model()->getAttribute($relation->getForeignKeyName());
                $values[$field->attribute] = $value === null ? null : (string) $value;
            } elseif ($field::class === Boolean::class && array_key_exists($field->attribute, $values)) {
                $value = $values[$field->attribute];
                $values[$field->attribute] = $value === null ? null : $value == $field->trueValue;
            }
        }

        return $values;
    }

    private function safeKey(string $key): bool
    {
        return ! str_starts_with($key, '_') && ! in_array($key, [
            'resource', 'resourceId', 'resources', 'action', 'viaResource', 'viaResourceId',
            'viaRelationship', 'pivotAction', 'lens',
        ], true);
    }
}
