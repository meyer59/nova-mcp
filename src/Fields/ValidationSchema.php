<?php

namespace NovaMcp\Fields;

use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Support\UndefinedValue;

/** Best-effort hints only. Nova's validator remains authoritative. */
class ValidationSchema
{
    public function enrich(Field $field, NovaRequest $request, array $schema): array
    {
        $rules = $request->isUpdateOrUpdateAttachedRequest() ? $field->getUpdateRules($request) : $field->getCreationRules($request);
        $parsed = [];
        $hints = [];
        foreach ((array) ($rules[$field->attribute] ?? []) as $rule) {
            if (! is_string($rule) || preg_match('/(?:^|\\|)(?:not_)?regex:/', $rule)) {
                $hints[] = 'custom';

                continue;
            }
            foreach (explode('|', $rule) as $part) {
                [$name, $parameters] = array_pad(explode(':', $part, 2), 2, '');
                $parsed[strtolower($name)] = $parameters;
            }
        }
        $schema['x-nova-required'] = isset($parsed['required']) && ! isset($parsed['sometimes']);
        $types = (array) $schema['type'];
        if (isset($parsed['required']) || (! isset($parsed['nullable']) && array_intersect(array_keys($parsed), ['string', 'numeric', 'integer', 'boolean', 'email', 'url', 'uuid', 'date', 'in']))) {
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
            if (in_array($name, ['min', 'max', 'between'], true)) {
                $limits = explode(',', $parameters);
                if (count(array_filter($limits, 'is_numeric')) !== count($limits)) {
                    continue;
                }
                $bounds = $name === 'between' && count($limits) === 2 ? ['min' => $limits[0], 'max' => $limits[1]] : [$name => $limits[0]];
                foreach ($bounds as $bound => $limit) {
                    if (! in_array($bound, ['min', 'max'], true) || ! is_numeric($limit) || ! is_finite((float) $limit)) {
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
            } elseif ($name === 'in' && $types === ['string', 'null']) {
                $enum = str_getcsv($parameters, ',', '"', '\\');
                if (in_array('null', (array) $schema['type'], true)) {
                    $enum[] = null;
                }
                $schema['enum'] = isset($schema['enum']) ? array_values(array_intersect($schema['enum'], $enum)) : $enum;
            } elseif (! in_array($name, ['required', 'nullable', 'sometimes', 'bail', 'string', 'numeric', 'integer', 'boolean'], true)) {
                $hints[] = str_contains($name, '_') ? 'conditional' : 'server';
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
            if (! $default instanceof UndefinedValue && $default !== null && is_scalar($default)) {
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
