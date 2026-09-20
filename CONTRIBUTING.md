# Contributing

Use an isolated test database. Tests use Orchestra Testbench, in-memory SQLite, real Nova 5 resource classes/controllers and Laravel MCP HTTP requests. They never bootstrap an existing application's `.env` or connect to its database.

## Licensed Nova dependency

For development against an already-installed Nova checkout, copy `composer.json` to `composer.local.json` and add this repository to the copy:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/absolute/path/to/your-app/vendor/laravel/nova",
      "options": {"symlink": false, "versions": {"laravel/nova": "5.7.5"}}
    }
  ]
}
```

Then run `COMPOSER=composer.local.json composer install` (PowerShell: `$env:COMPOSER = 'composer.local.json'; composer install`). The local manifest, lock file, vendor tree, test runtime files and secrets are gitignored. The root manifest is the distributable package contract; keep local copies in sync after changing dependencies.

Alternatively configure the official Nova Composer repository and your license credentials using Composer's normal authentication mechanism. Never commit credentials or Nova source.

## Checks

Run `composer test`, `composer lint`, `composer analyse`, and `node scripts/build.mjs`. Commit both UI source and the built `dist/tool.js`. PHPStan uses level 2 for the dynamic Nova integration; do not add blanket ignored errors to conceal compatibility problems. Regression tests should cover authorization boundaries and real controller behavior.

The GitHub workflow needs a repository secret named `NOVA_COMPOSER_AUTH`, containing Composer's authentication JSON for `nova.laravel.com`. No secret value belongs in workflow source. Credentials are scoped to the dependency-install step, which disables Composer scripts and plugins; test and analysis steps do not receive them. Actions are pinned to immutable commits and Dependabot tracks their updates. Dependency installation is followed by `composer audit`.

CI without credentials (including public fork pull requests) explicitly reports that licensed integration tests cannot run; PHP syntax and JavaScript build checks still run. Do not use `pull_request_target` to execute untrusted contributor code with secrets. Before tagging a release, run the full integration suite in a trusted checkout with licensed dependencies; a green syntax-only workflow is not a release qualification.

## Compatibility boundaries

Nova controller/request integration is isolated under `src/Nova`. Recheck it against new Nova minor releases before claiming compatibility. Test multiple users in one application process to catch state leaks. Include global scopes, `indexQuery`, `detailQuery`, field `canSee`, action `canRun`, queue behavior and a custom Nova provider when extending the adapter.

Use Testbench 10 for Laravel 12 and Testbench 11 for Laravel 13. Laravel 13 requires PHP 8.3+ and Nova 5.8+. Before declaring a new combination supported, run the full suite with licensed Nova dependencies and exercise the intended MCP client.
