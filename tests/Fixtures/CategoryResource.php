<?php

namespace NovaMcp\Tests\Fixtures;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class CategoryResource extends \Laravel\Nova\Resource
{
    public static $model = Category::class;

    public static $title = 'name';

    public static function uriKey(): string
    {
        return 'categories';
    }

    public function fields(NovaRequest $request): array
    {
        return [ID::make(), Text::make('Name')];
    }

    public static function indexQuery(NovaRequest $request, Builder $query)
    {
        return $query->where('tenant_id', $request->user()->tenant_id);
    }

    public static function relatableQuery(NovaRequest $request, Builder $query)
    {
        return $query->where('name', '!=', 'NOT RELATABLE');
    }
}
