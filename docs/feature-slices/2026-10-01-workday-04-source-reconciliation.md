# Feature Slice 04: Existing Time And Calendar Evidence Reconciliation

Status: Done On Dev; employee pilot active, human review Pending (2026-10-03).
Date: 2026-10-01
Owner: Codex; product/reviewer: Svein Tore
Parent: [Workday RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Delivery contract: [Implementation plan](../plans/2026-10-01-workday-implementation-plan.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md) - Pending, complete pilot not ready.
Dependencies: Slices 01-03.

## Goal

Reuse permitted Task/Ticket time and Calendar evidence without double-counting or treating estimates as actual work.

## User-Visible Behavior

The employee sees existing entries and optional meeting suggestions, places known minutes where appropriate, and reviews unallocated/overlapping time before confirming.

## Scope

- Add source adapters using authoritative Task/Ticket/Calendar authorization and current source semantics. Use stable source IDs/revisions and recorded/estimated/unknown provenance.
- Read source domains directly through guarded adapters for the employee. Do not call coordinator endpoints using a shared token or relax existing workload-bound pseudonymized APIs.
- Exclude task_billing and Task-originated Ticket mirrors; keep Commercial quick consumption, billing minimums and allocations out of actual totals.
- Keep date-only entries as date-level minutes until the employee places them. Calendar attendance and estimate-derived Task time remain unconfirmed suggestions.
- Reconcile source allocations to the confirmed interval total, with transactional limits across adjacent work dates. Multiple activity labels may describe concurrent work, but attributed minutes cannot inflate elapsed time.
- Expose bounded/paginated source discovery and complete/partial/unavailable status in UI/API. Missing source permission is not evidence of missing work.
- Mark a reference stale when source content changes; retain the employee's confirmed revision and require explicit reconciliation.

## Out Of Scope

Business-action telemetry collectors, autonomous AI durations, source time rewriting, billing changes and expanding coordinator grants.

## Data Touched

Workday allocation/source-reference snapshots only; source Task/Ticket/Calendar records remain authoritative and unchanged.
Implemented table: workday_source_allocations, referencing immutable Workday revisions.
Migration 2026_10_02_160000 was applied only on Dev after tests; table empty and both switches off.

## Permissions

Own Workday operations plus each source domain's existing authorization. Recheck source access on every source-detail read and confirmation. Calendar selection remains explicit.

## Tests

- Eight actual hours plus two imported hours still totals eight, with two allocated and six unallocated.
- Five Task actual minutes with thirty Ticket billing minutes contributes five; Commercial consumption contributes no extra actual minutes.
- Estimate-only, missing interval, denied/deleted/truncated source and source edits remain visibly distinguishable.
- Repeated source selection, multi-day allocation and concurrent confirmation cannot allocate the same source minutes twice.
- Existing Report/Commercial coordinator contract tests and Task/Ticket billing regressions still pass.
- Run the narrow affected Laravel suites on Dev with synthetic fixtures; no local PHP fallback.
- Inspect authenticated UI/API/HTTP read-back for the implemented behavior; an unauthenticated login
  redirect does not prove the feature works.

## Documentation

Document source classes, incompleteness, allocation semantics and correction behavior in Workday Knowledge/API and relevant Task/Ticket/Report help.
Update this slice and the parent TODO row in the same session as verification or a concrete blocker.

## Done Criteria

Synthetic mixed-source fixtures reconcile exactly through UI/API; no billing/source mutation occurs; complete-source claims have evidence.
Record changed files, exact tests/results, migrations/commands, HTTP/UI/API evidence and remaining
human checks. Passing automated tests never marks human review complete. Keep production runtime
off until the required review and separate rollout approval. Do not leave visible stubs for later slices.

## Delivery Evidence

Slice 04 delivery (2026-10-02): source reconciliation is Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-04-verification.md) records 271 distinct passing tests / 2236 latest assertions, two source API operations and one additive Dev migration. Source minutes stay within actual totals; original Task/Ticket/Calendar and billing data are unchanged. Next: Slice 05. HR-2026-10-01-WORKDAY remains Pending; Svein requested manual review only when the complete pilot actually needs it.
