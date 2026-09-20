<?php

namespace NovaMcp\Tests\Fixtures;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class NameFilter extends Filter
{
    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('name', $value);
    }

    public function options(NovaRequest $request)
    {
        return ['First' => 'FIRST', 'Second' => 'SECOND'];
    }
}
