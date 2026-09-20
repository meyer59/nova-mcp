<?php

namespace NovaMcp\Tests\Fixtures;

use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;

class FirstLens extends Lens
{
    public static function query(LensRequest $request, $query)
    {
        return $request->withFilters($query->where('name', 'FIRST'));
    }

    public function fields(NovaRequest $request): array
    {
        return [ID::make(), Text::make('Name'), Text::make('Secret')->canSee(fn () => false)];
    }

    public function uriKey(): string
    {
        return 'first';
    }
}
