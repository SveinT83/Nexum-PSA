# Feature Slice: Ticket Workflow v3 Migration And Release Hardening

Status: Done
Date: 2026-07-17
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Codex

## Goal

Complete safe active-Ticket migration, performance/security verification, documentation,
BookStack synchronization, and release handoff.

## User-Visible Behavior

Admins preview and apply compatible workflow upgrades; operators have complete documentation and
honest diagnostics; existing Tickets retain their prior behavior until explicitly migrated.

## Scope

Migration preview/apply, compatibility reports, query/performance review, full API contract audit,
Dev deployment, broad tests, HTTP/UI smoke checks, Knowledge sync, website handoff, and human review.

## Out Of Scope

Removing legacy columns or completing human review without explicit named confirmation.

## Data Touched

Workflow version assignments, migration audit, caches, documentation, BookStack sync state, TODO,
website handoff, and human-review register.

## Permissions

Workflow migrate/override and all read permissions needed for preview evidence.

## Tests

Retry-safe migration, rollback setting, API/view parity inventory, broad Laravel suite, Blade build,
authenticated and unauthenticated HTTP smoke, queue dispatch, and BookStack sync verification.

## Documentation

All affected Knowledge articles, API docs, deployment/rollback, TODO, RFC/ADR/slices, website
handoff, and human review.

## Done Criteria

- [x] Dev migration and rollback/compatibility checks pass.
- [x] Focused and broad automated suites pass or every failure is resolved.
- [x] Every view mutation has a tested API equivalent.
- [x] Knowledge documentation is synced to BookStack and verified.
- [x] Human review entry remains Pending until Svein Tore explicitly reviews it.
