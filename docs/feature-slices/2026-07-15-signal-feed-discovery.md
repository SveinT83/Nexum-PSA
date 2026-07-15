# Feature Slice: Signal Feed Discovery

Status: Done
Date: 2026-07-15
Parent: `docs/rfc/2026-07-15-signal-rule-builder-and-recovery.md`
Owner: Codex

## Goal

Keep a growing Signal feed fast to scan and easy to search.

## User-Visible Behavior

The feed defaults to 30 days and supports date range overrides, filters, search, sortable columns,
and query-preserving pagination.

## Scope

Signal index query, filter UI, sortable table headers, and tests.

## Out Of Scope

Rule editing and execution retry.

## Data Touched

Read-only queries against `signals` and related records.

## Permissions

Existing `signal.view` permission.

## Tests

Default range, all/custom range, filter, search, sort, and permission regression tests.

## Documentation

Signal README, Knowledge overview, and human review checklist.

## Done Criteria

- [x] Feed defaults to 30 days.
- [x] Overrides, filtering, search, sort, and pagination work.
- [x] Focused tests pass on Dev.
