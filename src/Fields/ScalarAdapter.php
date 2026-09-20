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
        if ($field instanceof Select) {
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
        $schema = $this->schema($field, $request);
        if ($this->type === 'boolean') {
            $value = match ($value) {
                'true', '1', 1 => true,
                'false', '0', 0 => false,
                default => $value,
            };
        } elseif ($this->type === 'number' && is_string($value) && is_numeric($value)) {
            $number = $value + 0;
            // Reject lossy large integer / high-precision decimal conversions.
            if (is_int($number) || (is_finite($number) && ! preg_match('/^[+-]?\\d+$/D', $value)
                && strlen(ltrim(preg_replace('/[^0-9]/', '', $value), '0')) <= 15
                && ($number != 0 || ! preg_match('/[1-9]/', preg_split('/e/i', $value)[0])))) {
                $value = $number;
            }
        } elseif ($field instanceof Select && is_string($value)) {
            foreach ($schema['enum'] ?? [] as $option) {
                if (is_int($option) && (string) $option === $value) {
                    $value = $option;
                    break;
                }
            }
        }
        $valid = $value === null || match ($this->type) {
            'string' => is_string($value) || ($field instanceof Select && is_int($value)),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'boolean' => is_bool($value),
            default => false,
        };
        if (! $valid || (isset($schema['enum']) && ! in_array($value, $schema['enum'], true))) {
            throw ValidationException::withMessages([$field->attribute => 'Invalid field value.']);
        }

        return $value;
    }
}
