<?php

namespace NovaMcp\Nova\Requests;

use Illuminate\Support\Collection;
use NovaMcp\Nova\ActionExposure;

class ActionRequest extends \Laravel\Nova\Http\Requests\ActionRequest
{
    use ScopedLookup;

    protected function resolveActions(): Collection
    {
        // Nova's controller resolves actions again. Apply the same exposure rule
        // before its visibility callbacks, including any future pivot action path.
        return parent::resolveActions()->filter(fn ($action) => app(ActionExposure::class)->allows($action, $this));
    }
}
