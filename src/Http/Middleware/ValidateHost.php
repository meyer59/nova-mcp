<?php

namespace NovaMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ValidateHost
{
    public function handle(Request $request, Closure $next): mixed
    {
        $app = parse_url(config('app.url'));
        $hosts = array_map('strtolower', array_merge(isset($app['host']) ? [$app['host']] : [], config('nova-mcp.allowed_hosts', [])));
        $rawHost = $request->header('Host', '');
        // Check the original Host before using a potentially forwarded IP for
        // rate limiting: platform hostnames can activate automatic proxy trust.
        abort_unless(count($request->headers->all('host')) === 1 && preg_match('/^(?:\[[a-f0-9:.]+\]|[a-z0-9.-]+)(?::[0-9]+)?$/iD', $rawHost), 403);
        $host = parse_url('http://'.$rawHost, PHP_URL_HOST);
        abort_unless(is_string($host) && in_array(strtolower($host), $hosts, true) && in_array(strtolower($request->getHost()), $hosts, true), 403);

        return $next($request);
    }
}
