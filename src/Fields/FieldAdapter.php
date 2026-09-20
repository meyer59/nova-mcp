<?php

namespace NovaMcp\Fields;

use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;

interface FieldAdapter
{
    public function schema(Field $field, NovaRequest $request): array;

    public function readable(Field $field, NovaRequest $request): bool;

    public function writable(Field $field, NovaRequest $request): bool;

    public function value(Field $field, NovaRequest $request): mixed;

    // Validate/normalize a single value. Nova still performs validation and filling.
    public function prepare(Field $field, mixed $value, NovaRequest $request): mixed;
}
