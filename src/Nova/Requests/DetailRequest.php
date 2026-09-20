<?php

namespace NovaMcp\Nova\Requests;

use Laravel\Nova\Http\Requests\ResourceDetailRequest;

class DetailRequest extends ResourceDetailRequest
{
    use ScopedLookup;
}
