<?php

namespace NovaMcp\Tests\Fixtures;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class RecordResource extends \Laravel\Nova\Resource
{
    public static $model = Record::class;

    public static $title = 'name';

    public static $search = ['name'];

    public static function uriKey(): string
    {
        return 'records';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make(),
            BelongsTo::make('Category', 'category', CategoryResource::class)->nullable(),
            Text::make('Name')->rules('required', 'max:80')->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                if ($request->exists($requestAttribute)) {
                    $model->{$attribute} = strtoupper($request->input($requestAttribute));
                }
            }),
            Text::make('Secret')->canSee(fn () => false),
            Text::make('Tenant', 'tenant_id')->readonly(),
            new UnsupportedField('Custom', 'custom'),
        ];
    }

    public static function indexQuery(NovaRequest $request, Builder $query)
    {
        return $query->where('tenant_id', $request->user()->tenant_id);
    }

    public static function beforeCreate(NovaRequest $request, $model): void
    {
        $model->tenant_id = $request->user()->tenant_id;
    }

    public function filters(NovaRequest $request): array
    {
        return [new NameFilter];
    }

    public function lenses(NovaRequest $request): array
    {
        return [new FirstLens];
    }

    public function actions(NovaRequest $request): array
    {
        return [new RenameAction, (new HiddenAction)->canSee(fn () => false)];
    }
}
