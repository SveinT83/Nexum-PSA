# Feature Slice: Email Mail Grouped Rules And Reprocessing

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Make shared/system Email rules easier to model with grouped all/any conditions and provide an
admin-owned way to reprocess a stored email through the published rule engine.

## User-Visible Behavior

The Admin Email rule create/edit form now lets rule managers add multiple condition rows, name
groups, choose all/any matching inside each group, and choose whether all groups or any group must
match. The rule list displays grouped snapshots so the published behavior is readable.

The Email rules index also includes a compact `Reprocess message` card. An admin with
`email.rule_manage` can enter an Email message ID and run rule processing now or queue it.

## Scope

- Support grouped condition snapshots in `InboundEmailRuleEngine`.
- Support grouped condition previews in the Email rules API.
- Support grouped condition evaluation for safe personal rules as a forward-compatible parser.
- Add add/remove condition UI for the Admin Email rule builder.
- Add admin reprocessing route and index form.

## Out Of Scope

- Drag ordering or nested groups deeper than group -> condition.
- Reprocessing messages outside the existing published rule engine.
- Reprocessing provider folders directly from IMAP.
- Personal rule grouped-builder UI.

## Data Touched

- Existing `email_rules.conditions_json`.
- Existing `email_rule_versions.conditions_json`.
- Existing `email_rule_execution_attempts` and `email_rule_logs` when reprocessing runs.

## Permissions

Admin grouped rule editing and reprocessing require `email.rule_manage`. Preview API still requires
`email.rule_manage` plus effective mailbox View access for the message being previewed.

## Tests

- Grouped all/any preview matches when one group matches.
- Admin reprocess-now executes the same published rule snapshot and applies the configured action.

## Automated Verification

- Focused Mail regressions including this slice passed on Dev with 6 tests and 50 assertions.
- Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Dev migration, cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, no failed jobs, route registration, and git diff checks were also completed.

## Done Criteria

- Runtime and preview use identical grouped match semantics.
- Admins can add/remove condition rows without editing JSON.
- Reprocessing is permission-gated and records normal rule execution attempts.
