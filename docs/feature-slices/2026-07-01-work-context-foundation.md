# Feature Slice: Work Context Foundation

Status: Done
Date: 2026-07-01
Parent: `docs/rfc/2026-07-01-work-context-organization-scope.md`
Owner: Codex

## Goal

Create the shared WorkContext foundation after the audit slice and RFC approval.

Audit source: `docs/audits/2026-07-01-work-context-audit.md`.

## User-Visible Behavior

No broad workflow change yet. Admins and developers gain the foundation needed for later slices where
no Client selected becomes internal work.

## Scope

- Create the singular `WorkContext` module structure.
- Add the `work_contexts` table.
- Seed the default internal context.
- Create or resolve one client context per existing Client.
- Add WorkContext constants and resolver helpers for `internal` and `client`.
- Add tests for internal/client context lookup and resolver behavior.
- Add developer documentation for module adoption rules.

## Out Of Scope

- No Ticket, Task, Asset, Documentation, Risk, Calendar, Report, API, Commercial, or Economy behavior
  changes.
- No migration of existing self-client records.
- No NexumRelationship tables or sync behavior.
- No UI controls beyond what is required for diagnostics or tests.

## Data Touched

- New `work_contexts` table.
- Seeded internal context row.
- Client context rows for existing Clients.

## Permissions

No new user-facing permissions are required for this foundation slice.

Future permission work may add separate internal-work abilities if client-facing or restricted
technician surfaces require it. Current module API context filters are implemented in later completed
adoption slices.

## Tests

- Migration/feature test proves the internal context exists.
- Feature or unit test proves client contexts can be resolved for existing Clients.
- Unit tests cover resolver behavior for no Client, selected Client, and invalid Client.

## Documentation

- Add WorkContext developer documentation.
- Update the parent RFC if implementation finds a better field name or resolver contract.

## Done Criteria

- WorkContext module exists and follows module architecture rules.
- Default internal context is created.
- Client contexts are available for existing and future Clients.
- Tests cover resolver behavior.
- No module behavior changes are hidden in this slice.
