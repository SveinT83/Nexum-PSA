# Feature Slice: Storage Managed AI Workload

Status: Done
Date: 2026-08-10
Parent: `docs/rfc/2026-08-10-simplified-storage-supplier-order-ai.md`
Owner: Svein / Codex

## Goal

Use an existing Storage-domain agent through an automatically managed, capability-isolated
structured workload.

## User-Visible Behavior

An administrator selects the Storage agent. Nexum derives its provider and model and manages the
internal workload automatically. The agent's ordinary tools do not need to be removed.

## Scope

Add managed workload metadata, policy agent reference, Integration provisioning/readiness/outbound
rules, forced local or privacy-relay processing, deactivation on AI-off/agent change, and tests.

## Out Of Scope

Generic coordinator workloads, arbitrary managed purposes, tool execution, direct external mode,
full-context data, and new AI provider transports.

## Data Touched

`ai_workload_profiles`, `storage_purchase_order_automation_policies`, revisions/snapshots, access
audit metadata, and existing provider/agent configuration.

## Permissions

Existing Storage policy permission approves the domain-scoped managed workload. No API ability,
token, role, or AI write permission is added.

## Tests

Storage-domain selection, capability isolation, stable upsert, provider/model updates, AI-off
deactivation, forced privacy profile, generic-governance regression, strict schema, audit, and
synthetic provider smoke.

## Documentation

RFC, ADR, Integration Knowledge, Storage Knowledge, TODO, and human review.

## Done Criteria

- [x] One Storage agent produces one active managed workload.
- [x] Agent tools/data/actions are absent from structured execution.
- [x] External execution is privacy-relay/pseudonymized only.
- [x] Generic workload governance remains unchanged.
- [x] Focused tests pass on Dev.
