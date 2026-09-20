<?php

namespace NovaMcp\Nova\Requests;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\TrashedStatus;

trait ScopedLookup
{
    public function newQueryWithoutScopes(): Builder
    {
        $resource = $this->resource();
        $query = $resource::newModel()->newQuery();
        if ($this instanceof RestoreRequest) {
            TrashedStatus::ONLY->applySoftDeleteConstraint($query);
        }

        return $resource::indexQuery($this, $query);
    }
}
