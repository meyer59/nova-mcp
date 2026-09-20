<?php

namespace NovaMcp\Tests\Fixtures;

use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class RenameAction extends Action
{
    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $model) {
            $model->update(['name' => 'ACTION']);
        }

        return static::message('Renamed');
    }
}
