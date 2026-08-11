# Feature Slice: Supplier Order Policy Simplification

Status: Done
Date: 2026-08-10
Parent: `docs/rfc/2026-08-10-simplified-storage-supplier-order-ai.md`
Owner: Svein / Codex

## Goal

Make the normal Supplier Order Policy understandable without Integration internals.

## User-Visible Behavior

The normal flow selects Storage agent, fallback behavior, runtime, warehouse, supplier handling,
and new-Item behavior. Thresholds, learning, consensus, timeout, tokens, cost, and outage controls
remain available in one collapsed Advanced section.

## Scope

Update controller/query/request/action/view, validation copy, readiness messages, regression tests,
Knowledge, TODO, and human review.

## Out Of Scope

Supplier profile editor redesign, operational import/detail redesign, or changing deterministic
extraction and finalization gates.

## Data Touched

Storage policy and immutable revisions only. Saving may provision managed actor/workload records
through the earlier slices.

## Permissions

Existing `storage.purchase_import_policy_manage` route guard remains. No UI permission widens.

## Tests

Normal/advanced rendering, agent readiness, payload tampering, policy revisions, AI off/fallback,
Item choices, responsive markup, and absence of manual actor/workload controls.

## Documentation

Storage Knowledge, Integration Knowledge, TODO, Feature Slice status, and human review.

## Done Criteria

- [x] Normal setup contains no Automation user or Internal workload field.
- [x] Storage agent and AI fallback can be saved without separate workload setup.
- [x] Advanced controls remain honest and functional.
- [x] Relevant focused and full Storage tests pass on Dev.
