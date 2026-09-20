<?php

namespace NovaMcp\Fields;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\BooleanGroup;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\MultiSelect;
use Laravel\Nova\Http\Requests\NovaRequest;

/** Convert MCP collections to Nova's JSON-encoded form input. */
class CollectionAdapter implements FieldAdapter
{
    public function schema(Field $field, NovaRequest $request): array
    {
        if ($field::class === MultiSelect::class) {
            return ['type' => ['array', 'null'], 'items' => ['enum' => $this->options($field)], 'uniqueItems' => true, 'maxItems' => 100];
        }
        if ($field::class === BooleanGroup::class) {
            return ['type' => ['object', 'null'], 'properties' => (object) array_fill_keys($this->options($field), ['type' => 'boolean']),
                'additionalProperties' => false, 'maxProperties' => 100];
        }

        return ['type' => ['object', 'null'], 'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'null'], 'maxLength' => 1000],
            'propertyNames' => ['maxLength' => 100], 'maxProperties' => 100];
    }

    private function options(Field $field): array
    {
        return array_column($field->jsonSerialize()['options'] ?? [], $field::class === BooleanGroup::class ? 'name' : 'value');
    }

    public function readable(Field $field, NovaRequest $request): bool
    {
        return true;
    }

    public function writable(Field $field, NovaRequest $request): bool
    {
        // Restricted maps need an existing-key-aware adapter; never bypass
        // restrictions imposed by Nova's KeyValue editor.
        return ! $field->isReadonly($request) && ! $field->isComputed()
            && (! $field instanceof KeyValue || (! $field->readonlyKeys($request) && $field->canAddRow && $field->canDeleteRow));
    }

    public function value(Field $field, NovaRequest $request): mixed
    {
        $value = $field->value;
        if (is_string($value)) {
            $value = json_decode($value, true);
        } elseif ($value instanceof Arrayable) {
            $value = $value->toArray();
        }
        if (! is_array($value)) {
            return null;
        }
        if ($field::class === MultiSelect::class) {
            $options = $this->options($field);

            return array_values(array_filter($value, fn ($item) => in_array($item, $options, true)));
        }
        if ($field::class === BooleanGroup::class) {
            $value = array_intersect_key($value, array_flip($this->options($field)));
            $value = array_filter($value, 'is_bool');
        }

        return (object) $value;
    }

    public function prepare(Field $field, mixed $value, NovaRequest $request): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (! is_array($value) || count($value) > 100) {
            $this->invalid($field);
        }
        if ($field::class === MultiSelect::class) {
            $options = $this->options($field);
            if (! array_is_list($value)) {
                $this->invalid($field);
            }
            $output = [];
            foreach ($value as $item) {
                if (is_string($item)) {
                    foreach ($options as $option) {
                        if (is_int($option) && (string) $option === $item) {
                            $item = $option;
                            break;
                        }
                    }
                }
                if (! in_array($item, $options, true) || in_array($item, $output, true)) {
                    $this->invalid($field);
                }
                $output[] = $item;
            }

            return json_encode($output, JSON_THROW_ON_ERROR);
        }
        $options = $field::class === BooleanGroup::class ? $this->options($field) : [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || mb_strlen($key) > 100) {
                $this->invalid($field);
            }
            if ($field::class === BooleanGroup::class) {
                if (! in_array($key, $options, true) || ! is_bool($item)) {
                    $this->invalid($field);
                }
            } elseif ((! is_scalar($item) && $item !== null)
                || (is_string($item) && mb_strlen($item) > 1000)
                || (is_float($item) && ! is_finite($item))) {
                $this->invalid($field);
            }
        }

        return json_encode((object) $value, JSON_THROW_ON_ERROR);
    }

    private function invalid(Field $field): never
    {
        throw ValidationException::withMessages([$field->attribute => 'Invalid collection value.']);
    }
}
