# Changelog

## v0.2.0

### Changed

- Action results default to `status`, including when a previously published config lacks `action_results`. Raw navigation, download, modal and event payloads are no longer returned by default.
- In status mode, Nova danger responses become MCP errors with code `action_failed`; queued actions report dispatch status.
- To retain raw responses, explicitly choose `action_results => 'full'` or list exact classes in `full_result_actions`. Application messages remain visible in status mode; exclude sensitive actions to prevent their side effects.

### Added

- Public `NovaMcp::isMcpRequest()` and `NovaMcp::token()` helpers.
- Action inclusion/exclusion lists with subclass matching and exclusion precedence.
- Action discovery metadata for destructive, queued, standalone and sole actions, plus bounded confirmation text.
- Action key and target count in execution audits, and debug audit events for excluded-action attempts.

### Fixed

- Conditional validation hints for `declined_if`, `accepted_if` and other `*_if`, `*_unless`, and `*_with*` rules.

See [Actions and sensitive results](README.md#actions-and-sensitive-results) for configuration and upgrade examples.
