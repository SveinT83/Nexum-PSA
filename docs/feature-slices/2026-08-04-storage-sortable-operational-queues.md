# Feature Slice: Storage Sortable Operational Queues

Status: Done
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-inventory-sortable-tables.md`
Owner: Svein / Codex

## Goal

Give every paginated Storage work queue consistent, safe, accessible server-side sorting without
losing its current search, filters, pagination context, or default operational priority.

## User-Visible Behavior

- Inventory Items, Picking List, Purchase Orders, and Receiving expose linked data headings.
- Clicking a heading sorts ascending; clicking the active heading reverses direction.
- The active heading shows its direction and exposes `aria-sort`.
- Search and filters remain active, while a new sort starts at the first page.
- Empty optional names and dates remain at the end.
- Action columns remain plain and non-sortable.

## Scope

- Reusable global sortable-header Blade component.
- Allowlisted SQL sorting in `StorageIndexQuery`, `PickingListQuery`, and
  `PurchaseOrderIndexQuery` for both Purchase Orders and Receiving.
- Controller filter-state changes needed to pass normalized sort parameters.
- Inventory Items, Picking List, Purchase Orders, and Receiving Blade headers.
- Stable tie-breakers and existing default ordering.
- Feature tests for every visible sortable column and current-view preservation.

## Out Of Scope

- Detail/history tables; they belong to the second slice.
- Interactive form tables, control slips, saved sorts, or preferences.
- Database, API, permission, integration, or background-work changes.

## Data Touched

Read-only queries against existing Storage, Ticket, Client, Vendor, warehouse, box, shipment, and
receipt tables. No stored data changes.

## Permissions

No permission changes. Existing Storage and purchase-order view permissions continue to apply.

## Tests

- All queue columns sort both directions.
- Missing optional values stay last.
- Reorder and pick-status semantics match displayed badges.
- Progress and outstanding aggregates match displayed capped values.
- Invalid sort keys and directions use safe defaults.
- Search/filter parameters persist and page is removed from new sort links.
- Sort links and headings meet the shared accessibility contract.

## Documentation

- Parent RFC and TODO.
- Storage inventory, picking, and purchase/receiving Knowledge.
- Shared human-review entry and public-safe website handoff.

## Done Criteria

- [x] Shared header component is implemented and used by all four queues.
- [x] Every data heading is backed by an allowlisted query sort.
- [x] Existing default queue ordering remains unchanged without explicit sort input.
- [x] Focused tests, Storage suites, Blade compilation, Pint, PHP, and diff checks pass.
- [x] Knowledge and review tracking are updated.

## Verification Result

The focused operational suite passes with 55 tests and 736 assertions. All 60 queue
column/direction combinations execute successfully against the configured Dev MySQL database. The
complete Storage suite passes with 95 tests and 1,233 assertions, and the complete Laravel suite
passes with 1,027 tests and 8,558 assertions. Human review remains open under
`HR-2026-08-04-002`.
