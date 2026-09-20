<?php

namespace NovaMcp\Mcp;

use Composer\InstalledVersions;
use Laravel\Mcp\Server;

class NovaServer extends Server
{
    protected string $name = 'Nova MCP';

    protected string $version = 'unknown';

    protected string $instructions = 'Discover resources with nova.resources, then nova.describe. IDs are strings. Only visible Nova fields are returned. Describe with an id for update fields. Discover actions with explicit ids before execution. Writes and actions can have real side effects.';

    protected function boot(): void
    {
        if (InstalledVersions::isInstalled('meyer59/nova-mcp')) {
            $this->version = InstalledVersions::getPrettyVersion('meyer59/nova-mcp') ?? 'unknown';
        }
        $instructions = config('nova-mcp.instructions', '');
        if (is_string($instructions) && trim($instructions) !== '') {
            $this->instructions .= "\n\n".trim($instructions);
        }

        foreach (['resources', 'describe', 'list', 'get', 'create', 'update', 'delete', 'restore', 'actions', 'run_action', 'relationships'] as $operation) {
            $this->tools[] = new NovaTool($operation);
        }
    }
}
