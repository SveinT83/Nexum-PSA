# Feature Slice: Storage Supplier Order Import Foundation

Status: Implemented - Awaiting Human Review
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`
Owner: Svein / Codex

## Goal

Create the disabled-by-default Storage foundation for supplier-order imports, versioned policy,
source provenance, attempts, and an operational import queue without creating Purchase Orders or
calling AI.

## User-Visible Behavior

Authorized admins can open Purchase Order Automation settings and see the effective disabled/shadow
policy. Authorized technicians can inspect a searchable, filterable, sortable Supplier Order
Imports queue and an import detail page populated from protected manual fixtures. The page explains
status, stage, source, normalized data, reasons, and retry eligibility honestly.

No finalization, AI, Item creation, Email rule, or unfinished control is exposed in this slice.

## Scope

- Add Storage-owned relational records for automation policy and immutable revisions.
- Add import headers, lines, attempts/events, source identity, safe source snapshot, normalized
  commercial/delivery snapshot, status/stage/reason, retry data, and optional PO link.
- Define the versioned canonical supplier-order schema and import state machine.
- Add unique source/action and supplier/external-order conflict constraints plus supporting indexes.
- Keep imports unresolved until every line can later resolve outside the PO tables.
- Add global settings with `off`, `shadow`, and `review` only; default `off`.
- Add explicit automation-actor selection but do not use it for writes yet.
- Add permissions for import view, resolve, execute, profile management, and policy management.
- Add Storage module routes/controllers/queries/views/tests for settings, queue, and detail.
- Add a protected fixture/manual-ingest test action available only in test/development tooling, not a
  production broad-upload control.
- Preserve sanitized source metadata/body snapshot and fingerprints without raw EML, unrestricted
  headers, prompts, or model output.

## Out Of Scope

- Email or Signal handoff.
- Profile extraction DSL and Admin profile editor.
- Item mapping or creation.
- Purchase Order creation.
- AI/provider calls.
- Automatic supplier creation.
- Receipt, shipment, or stock mutation.

## Data Touched

- New Storage automation policy/revision tables.
- New Storage import, line, attempt/event, and source-snapshot fields/tables.
- Permission and role seed data.
- Storage routes, controllers, queries, actions/state support, views, and tests.
- No existing Purchase Order, Item, Email, Signal, or receipt row is migrated or backfilled.

## Permissions

- `storage.purchase_import_view` for queue/detail.
- `storage.purchase_import_resolve` reserved for later resolution actions.
- `storage.purchase_import_execute` reserved for later retry/reprocess actions.
- `storage.purchase_import_profile_manage` reserved for the profile slice.
- `storage.purchase_import_policy_manage` for global policy settings.
- Existing `storage.purchase_manage` remains separate.

## Tests

- Fresh and upgrade migrations, foreign keys, indexes, state constraints, and fail-safe defaults.
- Permission and route-middleware coverage.
- Import state transitions and invalid transition rejection.
- Source/action and supplier/external-order conflict behavior.
- Sanitized source snapshot and forbidden raw/secret field assertions.
- Search, filters, sorting, pagination preservation, mobile rendering, and empty states.
- Automation actor must be explicit, active, and appropriately permissioned when later used.
- No PO, Item, receipt, Stock Unit, Movement, Signal, or AI call is created.

## Documentation

- Add initial Storage developer/Knowledge documentation for the disabled foundation and state model.
- Update permission documentation and `docs/TODO.md` status.
- Add/update the stable human-review entry when the slice is implemented.

## Done Criteria

- [x] Apply and verify the disabled-by-default schema and policy migrations on Dev.
- [x] Import records can represent unresolved lines without creating invalid PO lines.
- [x] Queue/detail/settings surfaces are implemented, permissioned, dense, responsive, and honest.
- [x] Source snapshots are sanitized and retention-ready.
- [x] State, uniqueness, permission, UI, and no-side-effect tests cover the implemented foundation.
- [x] No AI or automatic Purchase Order behavior is active by default.
- [x] Knowledge, TODO, deployment notes, and human review are updated.
- [ ] Complete the named human review in `HR-2026-08-04-003`.
