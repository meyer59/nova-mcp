<?php

namespace NovaMcp\Nova\Requests;

use Laravel\Nova\Http\Requests\UpdateResourceRequest;

class UpdateRequest extends UpdateResourceRequest
{
    use ScopedLookup;
}
