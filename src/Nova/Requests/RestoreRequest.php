<?php

namespace NovaMcp\Nova\Requests;

use Laravel\Nova\Http\Requests\RestoreResourceRequest;

class RestoreRequest extends RestoreResourceRequest
{
    use ScopedLookup;
}
