<?php

namespace NovaMcp\Nova\Requests;

use Closure;
use Laravel\Nova\Http\Requests\UpdateResourceRequest;
use NovaMcp\Nova\UpdateResource;

class UpdateRequest extends UpdateResourceRequest
{
    use ScopedLookup;

    public bool $bridgeValidation = false;

    public function newResourceWith($model)
    {
        $resource = parent::newResourceWith($model);

        return $this->bridgeValidation ? new UpdateResource($resource) : $resource;
    }

    public function withOriginalResource(Closure $callback): mixed
    {
        $previous = $this->bridgeValidation;
        $this->bridgeValidation = false;
        try {
            return $callback();
        } finally {
            $this->bridgeValidation = $previous;
        }
    }
}
