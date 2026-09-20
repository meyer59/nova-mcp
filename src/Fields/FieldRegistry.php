<?php

namespace NovaMcp\Fields;

use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Hidden;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Http\Requests\NovaRequest;

class FieldRegistry
{
    private array $adapters = [];

    public function __construct()
    {
        foreach (['Text', 'Textarea', 'Email', 'URL', 'Slug', 'Select', 'Markdown', 'Code', 'Hidden'] as $field) {
            $this->register('Laravel\\Nova\\Fields\\'.$field, new ScalarAdapter('string'));
        }
        foreach (['Number', 'Currency'] as $field) {
            $this->register('Laravel\\Nova\\Fields\\'.$field, new ScalarAdapter('number'));
        }
        $this->register(Boolean::class, new ScalarAdapter('boolean'));
        $this->register(ID::class, new ScalarAdapter('string', false));
        $this->register(Date::class, new ScalarAdapter('string', true, 'date'));
        $this->register(DateTime::class, new ScalarAdapter('string', true, 'date-time'));
        $this->register(BelongsTo::class, new BelongsToAdapter);
        // Password, file, collection relationship and arbitrary custom fields are excluded.
        unset($this->adapters[Hidden::class]);
    }

    public function register(string $fieldClass, FieldAdapter $adapter): void
    {
        $this->adapters[$fieldClass] = $adapter;
    }

    public function adapter(Field $field): ?FieldAdapter
    {
        // Exact class matching: custom subclasses do not inherit write support.
        return $this->adapters[$field::class] ?? null;
    }

    public function describe(iterable $fields, NovaRequest $request, bool $writing = false): array
    {
        $output = [];
        foreach ($fields as $field) {
            if (! $field instanceof Field || ! $field->authorizedToSee($request) || ! ($adapter = $this->adapter($field))) {
                continue;
            }
            if ($writing ? ! $adapter->writable($field, $request) : ! $adapter->readable($field, $request)) {
                continue;
            }
            $output[$field->attribute] = $adapter->schema($field, $request) + ['title' => $field->name, 'readOnly' => ! $adapter->writable($field, $request)];
        }

        return $output;
    }

    public function values(iterable $fields, NovaRequest $request): array
    {
        $output = [];
        foreach ($fields as $field) {
            if ($field instanceof Field && $field->authorizedToSee($request) && ($adapter = $this->adapter($field)) && $adapter->readable($field, $request)) {
                $output[$field->attribute] = $adapter->value($field, $request);
            }
        }

        return $output;
    }

    public function prepare(iterable $fields, array $input, NovaRequest $request): array
    {
        $allowed = [];
        foreach ($fields as $field) {
            if ($field instanceof Field && $field->authorizedToSee($request) && ($adapter = $this->adapter($field)) && $adapter->writable($field, $request)) {
                $allowed[$field->attribute] = [$field, $adapter];
            }
        }
        $output = [];
        foreach ($input as $key => $value) {
            if (! is_string($key) || ! isset($allowed[$key]) || in_array($key, ['resource', 'resourceId', 'resources', 'action', 'viaResource', 'viaResourceId', 'viaRelationship', 'pivotAction', 'lens', '_method', '_token'], true) || str_starts_with($key, '_')) {
                throw ValidationException::withMessages(['fields' => 'A supplied field is unavailable or read-only.']);
            }
            [$field, $adapter] = $allowed[$key];
            $output[$key] = $adapter->prepare($field, $value, $request);
        }

        return $output;
    }
}
