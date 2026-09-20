<?php

return [
    // Remote Streamable HTTP endpoint. Use HTTPS in production.
    'path' => 'mcp/nova',

    // Optional application context for every authenticated MCP client.
    // Keep secrets and user-specific information out of this shared text.
    'instructions' => '',

    // Empty = all registered resources. Otherwise, only these Nova resource classes.
    // Example: [App\Nova\Donation::class, App\Nova\Campaign::class]
    'included_resources' => [],

    // These Nova resource classes are always excluded, even when included above.
    // Example: [App\Nova\User::class]
    'excluded_resources' => [],

    // Empty = every action Nova authorizes. Entries match subclasses too.
    'included_actions' => [],

    // Always excluded, including subclasses, even when included above.
    // Example: [App\Nova\Actions\ImpersonateUser::class]
    'excluded_actions' => [],

    // 'status' returns an outcome and bounded application message only.
    // 'full' returns Nova's raw response, including URLs and payloads.
    // This does not prevent an action's side effects or redact secrets in messages.
    'action_results' => 'status',

    // Exact action classes allowed to return full results in status mode.
    // Only list actions whose entire response is suitable for an MCP client.
    'full_result_actions' => [],

    'auth' => [
        // null = use the same user provider Nova already uses. If Nova logs in
        // App\Models\UserNova, tokens belong to those users. Normally leave null.
        'provider' => null,
    ],

    // Abilities belong ONLY to each token. The creation UI defaults to Read only.
    'tokens' => [
        'default_expiration_days' => 30,
        'max_expiration_days' => 365,
        'allow_non_expiring' => false,
        'max_per_user' => 20,
    ],

    'rate_limit' => 60, // Requests per minute, per IP before auth and per token.
    'max_page_size' => 100,
    'max_action_size' => 100,
    'audit' => true, // Security and mutation metadata only; never field values/secrets.
    // 'audit_channel' => 'stack',

    // app.url's host is always allowed. Add exact extra hosts (no scheme, port,
    // or wildcard) for tenant domains or internal reverse-proxy Host headers.
    // Both the original Host and the trusted forwarded host must be allowed.
    'allowed_hosts' => [],

    // Origin-less native MCP clients are allowed. Browser origins must match
    // app.url or appear here. This is an origin check, not a CORS configuration.
    'allowed_origins' => [],
    'require_https' => true, // Automatically relaxed in local/testing environments.

    // Add application middleware needed for tenant resolution, etc. These run
    // AFTER bearer authentication, so the token owner is the current user.
    'middleware' => [],

    // User-dependent resource/field/action schemas are deliberately not cached.
];
