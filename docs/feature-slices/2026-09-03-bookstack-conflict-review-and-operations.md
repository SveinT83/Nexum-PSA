# Feature Slice: BookStack Conflict Review And Operations

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-knowledge-bookstack-revision-safe-sync.md`
Owner: Codex

## Goal

Give authorized technicians an explicit, auditable path to review inbound candidates and choose
the Nexum or BookStack content without exposing unsafe automatic resolution.

## User-Visible Behavior

The Knowledge article page shows BookStack state and a current-versus-candidate review card for
conflicts. An authorized technician can accept the BookStack candidate or keep the current Nexum
revision and queue an exact outbound resolution. Remote deletion and missing identifiers are
visible and require an explicit Keep Nexum action before recreation.

The Integration settings page separates automatic clean inbound policy from two-way outbound sync
and reports candidate, conflict, and remote-deletion totals.

## Scope

Review routes/controller, `knowledge.update` enforcement, state UI, settings policy, summaries,
sanitized status API, Knowledge/Integration documentation, TODO, human review, and website handoff.

## Out Of Scope

Line-by-line merge editor, general version browser, non-BookStack approval workflow, and production
activation.

## Permissions

Article visibility controls candidate visibility. `knowledge.update` is required for either
resolution action.

## Tests

Candidate preservation and explicit acceptance are covered in the focused integration regression;
existing route, permission, settings, and BookStack regressions remain green.

## Done Criteria

Routes, Blade compilation, focused tests, documentation, migration, and human-review registration
are verified on Dev.

## Dev Evidence

Both conflict-resolution routes are registered and guarded by the Tech permission middleware.
Blade compilation succeeds. The complete automated evidence is recorded in
`HR-2026-09-03-003`; human review remains Pending and blocks Main promotion, production migration,
deployment, release, and automatic inbound activation.
