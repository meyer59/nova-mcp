# Nova MCP — MCP Server for Laravel Nova

A Laravel Nova package that connects AI assistants to your application through the Model Context Protocol (MCP). Assistants can discover Nova resources, read records and perform the operations you allow.

**MCP follows the user's existing Nova permissions. A token can limit those permissions, but cannot add more.** All registered Nova resources are available by default; you can choose a smaller list.

[Quick start](#quick-start) · [Connect a client](#connect-a-client) · [Explain your resources](#explain-your-resources-to-the-llm) · [Permissions](#permissions) · [Tokens](#token-management) · [Tools](#tools) · [Compatibility](#compatibility)

## Quick start

Start with an application that already has a licensed Nova installation. **Laravel 12 is supported; Laravel 13 support is experimental.** See [compatibility](#compatibility) for PHP and Nova requirements.

### 1. Install the package

Install it from [Packagist](https://packagist.org/packages/meyer59/nova-mcp):

```bash
composer require meyer59/nova-mcp
php artisan migrate
```

Keep your application's existing Nova Composer repository and license credentials configured. No additional Composer repository is needed for Nova MCP.

### 2. Add MCP Access to Nova

Add the tool to the existing list in `NovaServiceProvider::tools()`:

```php
public function tools(): array
{
    return [
        // Your other Nova tools...
        new \NovaMcp\McpAccess,
    ];
}
```

If you use a custom Nova main menu, add the tool's menu entry there too. The package provider, endpoint and migrations are registered automatically. Consumers do not need Node or an asset build.

### 3. Create a token

Open **MCP Access** in Nova, give the token a name, choose its permissions and copy it into your MCP client's secret settings.

New tokens default to **Read only** and expire after **30 days**. The secret is shown only when creating or rotating a token.

[Browse the default configuration](config/nova-mcp.php) to see the available options. To customize the package, publish its optional config:

```bash
php artisan vendor:publish --tag=nova-mcp-config
```

Set `APP_URL` to your application's real URL and use HTTPS in production. Additional domains belong in [allowed hosts](#hosts-https-and-proxies).

## Connect a client

Use Streamable HTTP at:

```text
https://your-app.example/mcp/nova
Authorization: Bearer <your-token>
```

For clients using a `mcpServers` configuration with HTTP headers:

```json
{
  "mcpServers": {
    "nova": {
      "type": "http",
      "url": "https://your-app.example/mcp/nova",
      "headers": {"Authorization": "Bearer <configure-your-secret-here>"}
    }
  }
}
```

Client configuration formats differ. Use the client's secret store or environment substitution where available; do not commit a real token. This release implements personal bearer tokens, **not an OAuth authorization server**. Clients that require an interactive OAuth connection instead of a configurable bearer header need an additional OAuth integration. The package does not add Passport or alter an existing Sanctum API.

## Explain your resources to the LLM

Give the assistant enough context to understand your application's terminology and workflows. Both options below are optional.

For context shared across the application, add a plain string to the published config:

```php
// config/nova-mcp.php
'instructions' => 'This application manages digital signage. Players are devices; playlists contain scheduled media.',
```

For a resource, add one method to its existing Nova class:

```php
use Laravel\Nova\Http\Requests\NovaRequest;

// Add inside your existing App\Nova\Player resource:
public static function mcpDescription(NovaRequest $request): string
{
    return 'A player is a device running the signage app. '
        .'It controls a connected screen. Changing its playlist changes '
        .'the content shown on that screen.';
}
```

The description is included in `nova.resources` and `nova.describe`, only for users allowed to access that resource. No trait or extra registration is required.

Prefer a Markdown file? Return `file_get_contents(resource_path('mcp/players.md'))` from the same method.

Create `resources/mcp/players.md` in your application and explain the resource's purpose, important fields, relationships and workflow rules. The method can use `$request->user()` to tailor the description. Keep secrets and restricted information out of shared application instructions. Descriptions provide context; Nova policies and validation still enforce the rules.

Resource descriptions are read on each discovery request. After changing application instructions in cached config, run `php artisan config:cache` and reconnect the MCP client.

## Permissions

**MCP follows the user's existing Nova permissions. A token can limit those permissions, but cannot add more. Only resources enabled for MCP are available.**

For example, an admin with a read-only token can read data, but cannot change or delete it. A full-access token cannot bypass a Nova policy.

All registered Nova resources are enabled by default. To expose only selected resources:

```php
// config/nova-mcp.php
'resources' => [App\Nova\Donation::class, App\Nova\Campaign::class],
```

Token permissions are `read`, `create`, `update`, `delete`, `restore`, `actions` and `relationships`. A token with `*` adds no further restriction to the user's Nova permissions. Permissions are selected per token; there is no second set of default abilities in the config.

To restrict the entire MCP endpoint by email, IP or another rule, use the optional [MCP access gate](#restrict-all-mcp-access).

## Token management

Users can list, create, rename, change abilities/expiration, rotate and revoke their own tokens. The presets are Read only, Read + Actions, Full access allowed by my Nova permissions, and Custom.

To authorize administration of another user's tokens:

```php
use NovaMcp\NovaMcp;

NovaMcp::manageTokensUsing(function ($actor, $targetUser) {
    return $actor->can('manageMcpTokensFor', $targetUser);
});
```

This defines the `manageNovaMcpTokens` gate. By default, cross-user management is denied. Administrators can use “Manage another user” in the tool and enter a user ID from Nova's configured provider. They cannot retrieve an existing secret; rotation is required.

## Tools

| Tool | Ability | Purpose |
| --- | --- | --- |
| `nova.resources` | read | Authorized resources and their descriptions |
| `nova.describe` | read | Resource documentation, visible fields, write schemas, filters, lenses and relationships |
| `nova.list` | read | Nova search, filters, index scope, optional lens, pagination |
| `nova.get` | read | Visible detail fields for a scoped record |
| `nova.create` | create | Nova creation validation, filling and hooks |
| `nova.update` | update | Nova update validation, filling and hooks |
| `nova.delete` | delete | Nova deletion, including soft deletes where the model supports them |
| `nova.restore` | restore | Restore a scoped, soft-deleted resource |
| `nova.actions` | actions | Visible and runnable actions for selected records |
| `nova.run_action` | actions | Nova action validation, filling, authorization and dispatch |
| `nova.relationships` | relationships + read | Relationship discovery, related rows and BelongsTo candidates |

`*` on a token means no additional token restriction; it does not override Nova. Abilities exist only on tokens. There is no global capability/default-abilities configuration.

IDs are strings. Examples of tool arguments:

```json
{"resource":"donations","page":1,"per_page":25,"search":"receipt"}
{"resource":"donations","id":"123"}
{"resource":"donations","id":"123","fields":{"note":"Reviewed"}}
{"resource":"donations","ids":["123","127"]}
{"resource":"donations","action":"resend-receipt","ids":["123","127"],"fields":{}}
{"resource":"donations","id":"123","relationship":"campaign","mode":"candidates"}
```

Call `nova.describe` with an `id` to obtain that record's update fields. Descriptions are always computed in the current user's context. Custom field validation still comes from Nova at execution time; schemas describe accepted shapes rather than attempting to translate arbitrary Laravel validation rules.

Filters are an object mapping the filter keys returned by `describe` to values. `list` accepts `lens` using an authorized lens key. Pages default to 25 records and are capped at 100. Responses have `has_more`, not an unrestricted count. Actions accept at most 100 explicitly selected IDs; an omitted `ids` argument selects standalone actions only. There is no implicit “all records” action execution.

## Production settings

### Hosts, HTTPS and proxies

The server uses Laravel MCP's stateless Streamable HTTP transport. Authenticated POST handles initialization, notifications, pings and tool calls; unsupported GET/SSE listening and DELETE/session termination return 405. Use HTTPS outside `local`/`testing`; configure your application's trusted proxy settings when TLS terminates at a proxy.

The endpoint accepts only one complete `Authorization: Bearer ...` header. Combined/duplicate credentials and duplicate Origin headers are rejected. Tokens in cookies, URL parameters, request bodies or MCP session IDs do not authenticate a request.

Set `APP_URL` to the application's actual URL. Its host is allowed automatically; extra tenant domains and internal proxy hosts must be listed explicitly:

```php
// config/nova-mcp.php — exact hosts, without scheme, port or wildcards
'allowed_hosts' => ['tenant.example.com', 'internal-proxy.example.com'],
```

Both the original Host header and the host resolved through trusted forwarding headers must be allowed. This check also runs in local/testing environments. It prevents forged hostnames from activating Laravel's platform-specific automatic proxy trust. Unknown hosts receive HTTP 403 even if a bearer token is valid. Existing deployments using a host different from `APP_URL` must configure it before upgrading.

### Restrict all MCP access

Define the optional `accessNovaMcp` gate in your application's service provider `boot()` method to restrict the entire MCP endpoint by user, email, IP, or another application rule:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

Gate::define('accessNovaMcp', function ($user, Request $request) {
    return in_array($user->email, ['admin@example.com'], true)
        && in_array($request->ip(), ['203.0.113.10'], true);
});
```

The gate receives the token owner and the current HTTP request. It runs on every authenticated MCP request after Nova's own access check and the configured application middleware, including initialization, discovery, and tool execution. Denial returns HTTP 403 even for a token with `*` abilities. An allowed result cannot override Nova authorization or token restrictions. Configure Laravel's trusted proxies correctly when checking client IP addresses behind a proxy.

When this gate is not defined, Nova authorization continues to apply as before. There is no local-environment bypass for a defined MCP gate. It restricts the MCP endpoint only; Nova dashboard access and token management keep their existing authorization.

Laravel's global `Gate::before` callbacks still apply. If your application automatically grants every ability to super-admins, modify that existing callback to return `null` for `accessNovaMcp` before its super-admin shortcut. Otherwise that callback can intentionally override this gate, including IP restrictions. Adding another callback after an existing callback that returns `true` cannot undo it.

For an IP-based gate, trust only the actual reverse proxies and make them overwrite or correctly append forwarding headers. Do not trust `*` on an origin that arbitrary clients can reach directly: a valid-token holder can then forge `X-Forwarded-For` even with a legitimate Host. The package host check does not override an application's explicit proxy-trust policy.

If your application resolves tenancy or imposes additional access requirements using custom HTTP middleware, add those middleware to `nova-mcp.middleware`. They run after bearer authentication and before Nova boots. Browser/session-specific middleware from `nova.api_middleware` is not blindly copied onto the bearer endpoint; mirror application-specific requirements such as tenant selection or verified-email checks explicitly. Do not rely on a cookie to establish MCP tenancy.

### Authentication and other safeguards

Tokens use a dedicated `nova-mcp` bearer guard. Nova's configured guard/provider is detected automatically, including separate Nova user tables and models. The package never changes the configured driver of Nova's session guard or your API guard. It installs the token owner as the current user for one MCP request and restores authentication state in `finally`.

Nova's `ServingNova` event runs under that identity, registering the application's resources and Nova access callback. Record reads and mutations enforce the resource's `indexQuery`, model global scopes, record policies and, where relevant, `detailQuery`. Tenant scoping is retained even on Nova controller paths that normally use unscoped ID lookups.

Other safeguards include:

- 256-bit random secrets, SHA-256 storage, constant-time comparison, expiration and revocation checks.
- Owner-bound tokens that also record the provider and concrete authenticatable type; no user-model trait required.
- Default maximum lifetime of 365 days, no non-expiring tokens by default, maximum 20 active tokens per owner.
- Atomic rotation and serialized per-owner issuance using the application's cache locks. Use a shared lock-capable cache store across multiple application servers.
- Rate limits before authentication and per token, HTTPS enforcement, and explicit browser Origin validation.
- Session authentication, CSRF protection, Nova access checks and Tool `canSee` authorization on management routes.
- Exact field allowlists, no mass assignment, no globally cached user-dependent schemas, and sanitized MCP errors.
- Package audit logs contain operation metadata, not token secrets or field input. Nova's own action event logging remains in effect.

Exclude Authorization headers and the token-management response bodies from host request/response capture, APM, reverse proxy logs and debugging tools. The package cannot redact logs recorded outside its own audit logger.

Rotation invalidates the previous secret for subsequent requests immediately. It cannot cancel an already executing operation or a job Nova already queued. Token ability edits apply to subsequent requests. `actions` authorizes Nova actions independently of generic CRUD abilities: an action can have destructive or external side effects, subject to its Nova permissions.

## Field and relationship support

Supported scalar fields include Text, Textarea, Email, URL, Slug, Select, Markdown, Code, Number, Currency, Boolean, Date and DateTime. ID is read-only. Field visibility, context and readonly status are evaluated through Nova. Unknown/custom subclasses are excluded unless explicitly adapted; they do not inherit permission to write just because they extend Text.

BelongsTo reads and writes require the `relationships` ability as well as the operation's ability. Related resources must be exposed, visible, tenant-scoped, and eligible under Nova's relatable query. Nova still performs its own relationship validation and filling.

The relationships tool supports BelongsTo, HasOne, HasMany and BelongsToMany reads. Candidate discovery currently supports BelongsTo on an existing, updatable parent. Related rows and BelongsTo assignments are intersected with a fresh query that enforces the related model's global scopes and Nova index scope, even if an application relationship removes scopes. Related authorization and field callbacks run under the related resource's request context.

Collection attachment/detachment, pivot writes, polymorphic writes, file uploads, repeaters, and force deletion are intentionally not exposed in this release. Unsupported fields are excluded, rather than silently treated as writable. Lenses must return an Eloquent query for the resource's model and table, selecting plain columns with real model IDs. Joins, unions, grouping, aggregates, expression/alias projections, alternate tables, and custom paginators are rejected because this adapter cannot safely authorize their transformed rows. Lens OR conditions remain constrained by the resource's scope. Queued actions retain Nova's normal queue behavior.

For custom fields, register an adapter in a service provider:

```php
use NovaMcp\Fields\FieldRegistry;
use NovaMcp\Fields\ScalarAdapter;

app(FieldRegistry::class)->register(MyPlainTextField::class, new ScalarAdapter('string'));
```

Only use the scalar adapter for a field whose input really is a scalar. More complex fields implement `NovaMcp\Fields\FieldAdapter`: `schema`, `readable`, `writable`, `value`, and `prepare`. `prepare` validates/normalizes one supplied value; it must not save models. Nova remains responsible for resource validation, field filling, hooks and persistence. Inspect authorization and tenancy carefully in adapters for relationship-like fields.

## Compatibility

| Laravel | PHP | Nova | Status |
| --- | --- | --- | --- |
| 12.41.1+ | 8.2+ | 5.7+ | Supported |
| 13 | 8.3+ | 5.8+ | Experimental; full integration validation is pending |

Laravel MCP 0.6.7+ is required. Composer enforces the PHP and framework requirements of each dependency. [Laravel 13 requires PHP 8.3+](https://laravel.com/docs/13.x/releases), and [Nova 5.8 introduced Laravel 13 support](https://nova.laravel.com/releases/5.8.0).

## Development

Nova is proprietary and is never committed or bundled into this package. Install its licensed dependency through your own Composer Nova credentials/repository, or use a local path repository. See [CONTRIBUTING.md](CONTRIBUTING.md) for the isolated local setup and CI requirements.

```bash
composer test
composer lint
composer analyse
node --check resources/js/tool.js
node scripts/build.mjs
```

The shipped `dist/tool.js` uses Nova's Vue runtime. No Node installation or asset compilation is needed by package consumers. The build copies the checked JavaScript into the distribution directory; there is no second Vue runtime or runtime template compilation.

Internal boundaries: `Auth` resolves identity, `Tokens` manages credentials, `Fields` adapts field shapes, `Nova/Gateway` and `Nova/Requests` isolate Nova 5 compatibility, `Mcp` defines the compact tool surface, and HTTP middleware establishes/restores request context.

References: [Nova tools](https://nova.laravel.com/docs/v5/customization/tools), [Nova authorization](https://nova.laravel.com/docs/v5/resources/authorization), [Laravel MCP](https://laravel.com/docs/12.x/mcp).
