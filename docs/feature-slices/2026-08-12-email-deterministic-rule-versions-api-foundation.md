# Feature Slice: Email Deterministic Rule Versions And API Foundation

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-005`

## Goal

Deliver the first deterministic rules/API foundation for the Mail full-client RFC without replacing
the existing Admin rule builder or changing current inbound Ticket behavior.

## User-Visible Behavior

Existing Email rule management still works from `/tech/admin/settings/email/rules`. Each create,
update, or enable/disable now publishes an immutable rule version. The rule list shows the currently
published version number.

API clients with `email.rules.read` can list and view rule definitions and preview one rule against
one authorized message. Preview reports account-scope match, condition match details, and actions
that would run. Preview has no side effects.

## Scope

- Add `email_rule_versions` for immutable published snapshots.
- Add `email_rule_execution_attempts` for idempotent matched rule execution.
- Backfill version 1 for existing Email rules.
- Publish a new version on Admin create, update, and toggle.
- Run inbound rules from the published snapshot when available.
- Keep compatibility for programmatic legacy rules without published versions.
- Add read/preview API routes under `/api/v1/email/rules`.
- Add `email.rules.read` API ability metadata.

## Out Of Scope

- Personal technician rules.
- Full grouped all/any builder UI.
- Draft-only unpublished rule editing.
- Bulk preview by search/folder/date range.
- Background reprocessing UI/API.
- Safe retry/undo controls.
- Automatic external replies.

## Data Touched

- `email_rules`
- `email_rule_versions`
- `email_rule_execution_attempts`
- `email_rule_logs`
- API ability catalog

## Tests

- Admin-created rules publish version 1.
- Runtime execution records one idempotent execution attempt per message/rule-version and does not
  replay successful side effects on repeated processing.
- Rules API lists published versions.
- Preview API reports match/action state without mutating the message or execution ledger.
- Existing Email rule, Inbox, Ticket routing, and inbound automation regressions still pass.

## Done Criteria

- [x] Existing rules are backfilled to published version 1.
- [x] Admin save/toggle publishes immutable snapshots.
- [x] Runtime processing uses published snapshots where available.
- [x] Matched execution attempts are idempotent by message, placement, rule, and version.
- [x] Preview API is read-only and mailbox-authorized.
- [x] Email and Integration Knowledge document the new API and execution boundary.
- [x] Focused Email rule and inbound automation tests pass on Dev after migration.
