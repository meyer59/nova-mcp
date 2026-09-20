<?php

namespace NovaMcp;

use Illuminate\Http\Request;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\Tool;

class McpAccess extends Tool
{
    public function boot(): void
    {
        Nova::script('nova-mcp', __DIR__.'/../dist/tool.js');
        Nova::style('nova-mcp', __DIR__.'/../resources/css/tool.css');
    }

    public function menu(Request $request): MenuSection
    {
        return MenuSection::make('MCP Access')->path('/mcp-access')->icon('key');
    }
}
