<?php

namespace NovaMcp\Support;

use Illuminate\Support\Facades\Log;

class Audit
{
    public function debug(string $event, array $metadata = []): void
    {
        if (config('nova-mcp.audit', true)) {
            Log::channel(config('nova-mcp.audit_channel'))->debug('nova-mcp.'.$event, $metadata);
        }
    }

    public function record(string $event, array $metadata = []): void
    {
        if (config('nova-mcp.audit', true)) {
            // Callers supply identifiers and outcomes, never arbitrary request data.
            Log::channel(config('nova-mcp.audit_channel'))->info('nova-mcp.'.$event, $metadata);
        }
    }
}
