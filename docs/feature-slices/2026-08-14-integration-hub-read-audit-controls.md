# Feature Slice: Integration Hub Read Audit And Emergency Controls

Status: Ready for Human Review
Date: 2026-08-14
Parent: GitHub #216
Owner: Svein / Codex

## Goal

Audit allowed and denied reads and fail closed through global, capability, Integration, and target
emergency controls.

## Routes And Behavior

Service reads pass central policy middleware before controllers/adapters. Authorized operators can
inspect paginated sanitized audit/control state and update an exact control through an explicit API.
Health differentiates disabled, misconfigured, unavailable, stale, unknown, and healthy.

## Data Touched

Hub settings, emergency controls, control-change audit, and read audit. Retention is configured in
Hub settings; a pruning command removes expired rows without provider access.

## Permissions And Isolation

`integration-hub.audit.read` for inspection and `integration-hub.controls.manage` for changes.
Service reads always enforce controls regardless of caller role or workload. Re-enable is a separate
authorized audited action.

## Tests

Allowed/denied audit, global/integration/capability/target disablement, retries/concurrency,
propagation, wrong installation, permissions, redaction, readiness classification, and pruning.

## Documentation

Operations/security/API/Knowledge docs, emergency runbook, and human review.

## Done Criteria

- [x] Every Hub read records minimal sanitized evidence.
- [x] Every disablement scope fails closed centrally and in the adapter.
- [x] Operator changes are attributable and independently audited.

The pruning command is scheduled daily at 04:00, and operator endpoints require an explicit narrow
token in addition to the governance permission. Human review remains `HR-2026-08-15-001`.
