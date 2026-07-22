# Feature Slice: CloudFactory Production Validation And Reconciliation

Status: In Progress
Date: 2026-07-20
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
Owner: Codex

## Goal

Validate the production-only provider safely and leave automatic reconciliation observable.

## User-Visible Behavior

Administrators see health, role coverage, last runs, conflicts, failed operations, retry actions, and
the allowlisted fictitious Client used for the first write validation. Manual synchronization opens
a live modal backed by the queue run, with separate item counters for Clients, Catalogue and prices,
and Licences.

## Scope

Scheduled polling, locks, backoff, reconciliation, stale-state warnings, operation retry, audit,
allowlist enforcement, manual run controls, authenticated notification webhooks, deterministic
delivery deduplication, and runtime human-review checklist.
Manual runs include durable category progress, duplicate-run protection, and resumable status polling.

## Out Of Scope

Broad production writes before the fictitious Client test passes. Live webhook registration and
delivery are verified during the same controlled production validation.

## Data Touched

Integration settings and encrypted secrets, sync runs, operations, conflicts, webhook receipts,
audit events, queue jobs, and schedule configuration.

## Permissions

Integration managers can run reads; CloudFactory write permission plus allowlist is required for tests.

## Tests

Scheduling, overlap lock, backoff, stale state, allowlist, failure recovery, shared-key rejection,
24-hour retry acceptance, deterministic deduplication, queued reconciliation, persistent item/source
progress, duplicate manual-run protection, status polling, and smoke tests.

## Documentation

Administrator runbook, incident response, and `docs/human-review.md`.

## Done Criteria

- [x] Manual synchronization remains queued and exposes durable per-category live progress.
- [x] Automated verification passes on Dev.
- [ ] Only the allowlisted fictitious Client can receive initial writes.
- [ ] Live webhook registration and one provider delivery are verified without exposing the key.
- [x] Remaining human checks are explicitly recorded in `docs/human-review.md`.
