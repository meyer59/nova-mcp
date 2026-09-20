# Security

Report suspected vulnerabilities privately to the repository owner. Do not include working credentials or customer data in public issues.

MCP follows the user's Nova permissions. Tokens can restrict access further, and only resources enabled for MCP are available. Keep Nova policies, tenant scoping and application-specific access rules in place.

Use HTTPS, configure trusted proxies carefully, and set `APP_DEBUG=false` in production. Exclude bearer headers and token-creation/rotation responses from application, proxy and monitoring logs.

Custom Nova actions and field adapters run application code and must enforce the application's own rules. Treat record contents and action output as untrusted data.
