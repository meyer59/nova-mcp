<?php

namespace NovaMcp\Fields;

use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Support\UndefinedValue;

/** Best-effort hints only. Nova's validator remains authoritative. */
class ValidationSchema
{
    public function rules(Field $field, NovaRequest $request): array
    {
        $rules = $request->isUpdateOrUpdateAttachedRequest() ? $field->getUpdateRules($request) : $field->getCreationRules($request);

        return Arr::flatten((array) ($rules[$field->attribute] ?? []));
    }

    private function parse(array $rules): array
    {
        $parsed = [];
        $hints = [];
        $enums = [];
        foreach ($rules as $rule) {
            if (is_object($rule) && $rule::class === Rules\In::class) {
                $enums[] = str_getcsv(substr((string) $rule, 3), ',', '"', '\\');

                continue;
            }
            if (is_object($rule) && $rule::class === Rules\Enum::class) {
                // Laravel exposes no type getter. Keep this limited to the exact
                // framework rule, and never serialize arbitrary rule objects.
                $type = (new \ReflectionProperty(Rules\Enum::class, 'type'))->getValue($rule);
                if (is_subclass_of($type, BackedEnum::class)) {
                    $enums[] = array_map(fn ($case) => $case->value, array_values(array_filter($type::cases(), fn ($case) => $rule->passes('', $case))));
                } else {
                    $hints[] = 'server';
                }

                continue;
            }
            if (is_object($rule) && in_array($rule::class, [Rules\Unique::class, Rules\Exists::class, Rules\Password::class], true)) {
                $hints[] = 'server';

                continue;
            }
            if (! is_string($rule) || preg_match('/(?:^|\\|)(?:not_)?regex:/', $rule)) {
                $hints[] = 'custom';

                continue;
            }
            foreach (explode('|', $rule) as $part) {
                if (trim($part) === '') {
                    continue;
                }
                [$name, $parameters] = array_pad(explode(':', $part, 2), 2, '');
                $parsed[strtolower($name)] = $parameters;
            }
        }

        return [$parsed, $hints, $enums];
    }

    public function required(Field $field, NovaRequest $request): bool
    {
        [$rules] = $this->parse($this->rules($field, $request));

        return isset($rules['required']) && ! isset($rules['sometimes']);
    }

    public function enrich(Field $field, NovaRequest $request, array $schema): array
    {
        [$parsed, $hints, $enums] = $this->parse($this->rules($field, $request));
        $schema['x-nova-required'] = isset($parsed['required']) && ! isset($parsed['sometimes']);
        $types = (array) $schema['type'];
        if (isset($parsed['required']) || (! isset($parsed['nullable']) && ($enums || array_intersect(array_keys($parsed), ['string', 'numeric', 'integer', 'boolean', 'email', 'url', 'uuid', 'date', 'date_format', 'ip', 'ipv4', 'ipv6', 'in'])))) {
            $schema['type'] = array_values(array_diff($types, ['null']));
            if (isset($schema['enum'])) {
                $schema['enum'] = array_values(array_filter($schema['enum'], fn ($value) => $value !== null));
            }
        }
        if (isset($parsed['integer']) && in_array('number', $types, true)) {
            $schema['type'] = array_map(fn ($type) => $type === 'number' ? 'integer' : $type, (array) $schema['type']);
        }
        $numeric = isset($parsed['numeric']) || isset($parsed['integer']);
        foreach ($parsed as $name => $parameters) {
            if (in_array($name, ['min', 'max', 'between', 'size'], true)) {
                $limits = explode(',', $parameters);
                if (count(array_filter($limits, 'is_numeric')) !== count($limits)) {
                    continue;
                }
                $bounds = match ($name) {
                    'between' => count($limits) === 2 ? ['min' => $limits[0], 'max' => $limits[1]] : [],
                    'size' => ['min' => $limits[0], 'max' => $limits[0]],
                    default => [$name => $limits[0]],
                };
                foreach ($bounds as $bound => $limit) {
                    if (! is_numeric($limit) || ! is_finite((float) $limit)) {
                        continue;
                    }
                    if ($numeric && in_array('number', $types, true)) {
                        $schema[$bound === 'min' ? 'minimum' : 'maximum'] = $limit + 0;
                    } elseif (! $numeric && $types === ['string', 'null'] && ctype_digit($limit)) {
                        $schema[$bound === 'min' ? 'minLength' : 'maxLength'] = (int) $limit;
                    }
                }
            } elseif (in_array($name, ['email', 'url', 'uuid', 'ipv4', 'ipv6'], true)) {
                $schema['format'] = $name === 'url' ? 'uri' : $name;
            } elseif ($name === 'ip') {
                $schema['anyOf'] = [['type' => 'string', 'format' => 'ipv4'], ['type' => 'string', 'format' => 'ipv6']];
                if (in_array('null', (array) $schema['type'], true)) {
                    $schema['anyOf'][] = ['type' => 'null'];
                }
            } elseif ($name === 'date_format') {
                // PHP date formats need not be ISO / RFC 3339.
                if ($parameters === 'Y-m-d') {
                    $schema['format'] = 'date';
                }
                $schema['description'] = trim(($schema['description'] ?? '').' Expected PHP date format: '.$parameters.'.');
                $hints[] = 'server';
            } elseif ($name === 'in') {
                $enums[] = str_getcsv($parameters, ',', '"', '\\');
            } elseif (! in_array($name, ['required', 'nullable', 'sometimes', 'bail', 'string', 'numeric', 'integer', 'boolean'], true)) {
                $hints[] = preg_match('/_(?:if|unless|with.*)$/D', $name) || str_starts_with($name, 'required_') || str_starts_with($name, 'prohibited_') || str_starts_with($name, 'exclude_') ? 'conditional' : 'server';
            }
        }
        foreach ($enums as $enum) {
            if (isset($schema['enum'])) {
                $schema['enum'] = array_values(array_filter($schema['enum'], fn ($value) => $value === null
                    ? in_array('null', (array) $schema['type'], true)
                    : in_array((string) $value, array_map('strval', $enum), true)));
            } elseif ($types === ['string', 'null']) {
                $schema['enum'] = array_map('strval', $enum);
                if (in_array('null', (array) $schema['type'], true)) {
                    $schema['enum'][] = null;
                }
            } elseif (in_array('number', $types, true)) {
                try {
                    $numericEnum = array_map(fn ($value) => (new ScalarAdapter('number'))->prepare($field, $value, $request), $enum);
                    $schema['enum'] = array_values(array_unique($numericEnum, SORT_REGULAR));
                    if (in_array('null', (array) $schema['type'], true)) {
                        $schema['enum'][] = null;
                    }
                } catch (ValidationException) {
                    $hints[] = 'server';
                }
            } else {
                $hints[] = 'server';
            }
        }
        $help = $field->getHelpText();
        $description = array_filter([$schema['description'] ?? null, is_string($help) ? trim(strip_tags($help)) : null,
            is_string($field->placeholder) ? $field->placeholder : null]);
        if ($description) {
            $schema['description'] = implode(' ', $description);
        }
        if ($request->isCreateOrAttachRequest() || $request->isActionRequest()) {
            $default = $field->resolveDefaultValue($request);
            $adapter = app(FieldRegistry::class)->adapter($field);
            if ($adapter instanceof CollectionAdapter && (is_array($default) || $default instanceof \stdClass)) {
                try {
                    $schema['default'] = json_decode($adapter->prepare($field, $default, $request), false, 512, JSON_THROW_ON_ERROR);
                } catch (ValidationException) {
                    $hints[] = 'server';
                }
            } elseif (! $default instanceof UndefinedValue && $default !== null && is_scalar($default) && ! $adapter instanceof CollectionAdapter) {
                $schema['default'] = $default;
            }
        }
        if ($hints) {
            $schema['x-nova-rules'] = array_values(array_unique($hints));
        }

        return $schema;
    }

    public function object(array $fields, bool $updating): array
    {
        return ['type' => 'object', 'properties' => (object) $fields, 'additionalProperties' => false,
            'required' => $updating ? [] : array_keys(array_filter($fields, fn ($field) => ($field['x-nova-required'] ?? false) && ! array_key_exists('default', $field))),
        ];
    }
}
