<?php

namespace NovaMcp\Nova\Requests;

use Laravel\Nova\Http\Requests\DeleteResourceRequest;

class DeleteRequest extends DeleteResourceRequest
{
    use ScopedLookup;
}
