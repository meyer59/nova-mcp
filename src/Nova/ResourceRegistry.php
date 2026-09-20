<?php

namespace NovaMcp\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;

class ResourceRegistry
{
    public function all(NovaRequest $request): array
    {
        $exposure = config('nova-mcp.resources', '*');

        return array_values(array_filter(Nova::$resources, fn ($class) => ($exposure === '*' || (is_array($exposure) && in_array($class, $exposure, true)))
            && $class::authorizedToViewAny($request)
        ));
    }

    public function metadata(NovaRequest $request, string $class): array
    {
        $metadata = ['key' => $class::uriKey(), 'label' => $class::label()];
        if (method_exists($class, 'mcpDescription') && is_callable([$class, 'mcpDescription'])) {
            $description = $class::mcpDescription($request);
            if (! is_string($description)) {
                throw new \UnexpectedValueException('mcpDescription must return a string.');
            }
            if (trim($description) !== '') {
                $metadata['description'] = trim($description);
            }
        }

        return $metadata;
    }

    public function resolve(NovaRequest $request, string $key): string
    {
        foreach ($this->all($request) as $class) {
            if ($class::uriKey() === $key) {
                return $class;
            }
        }
        abort(404, 'Resource unavailable.');
    }
}
