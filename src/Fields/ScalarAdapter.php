<?php

namespace NovaMcp\Fields;

use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class ScalarAdapter implements FieldAdapter
{
    public function __construct(private string $type, private bool $write = true, private ?string $format = null) {}

    public function schema(Field $field, NovaRequest $request): array
    {
        $schema = ['type' => [$this->type, 'null']];
        if ($this->format) {
            $schema['format'] = $this->format;
        }
        if ($field::class === Select::class) {
            $schema['type'] = ['string', 'integer', 'null'];
            $schema['enum'] = array_merge(array_column($field->jsonSerialize()['options'] ?? [], 'value'), [null]);
        }

        return $schema;
    }

    public function readable(Field $field, NovaRequest $request): bool
    {
        return true;
    }

    public function writable(Field $field, NovaRequest $request): bool
    {
        return $this->write && ! $field->isReadonly($request) && ! $field->isComputed();
    }

    public function value(Field $field, NovaRequest $request): mixed
    {
        $value = $field->value;

        return is_scalar($value) || $value === null ? $value : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : null);
    }

    public function prepare(Field $field, mixed $value, NovaRequest $request): mixed
    {
        $valid = $value === null || match ($this->type) {
            'string' => is_string($value) || ($field::class === Select::class && is_int($value)),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
        $schema = $this->schema($field, $request);
        if (! $valid || (isset($schema['enum']) && ! in_array($value, $schema['enum'], true))) {
            throw ValidationException::withMessages([$field->attribute => 'Invalid field value.']);
        }

        return $value;
    }
}
