# Feature Slice: Storage Sortable Detail And Admin Tables

Status: Done
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-inventory-sortable-tables.md`
Owner: Svein / Codex

## Goal

Extend the same sorting contract to Inventory settings and read-only detail/history tables while
keeping multiple tables on one page independent and preserving workflow-table order.

## User-Visible Behavior

- Warehouse settings can be sorted by default state, name, code, address, item count, box count, and
  active status.
- Item Movement History can be sorted by time, type, before/delta/after, reason, and actor.
- Box Contents and Box Events have independent sortable headings.
- Purchase Order Lines, Shipment Allocation Lines, and Receipt History have independent sortable
  headings.
- Sorting one detail table does not reorder another table on the same page.
- Interactive forms and printed control slips remain in their intentional order.

## Scope

- Storage-owned collection sorting utility with allowlists, normalized direction, missing-value
  placement, typed comparison, and deterministic ties.
- Admin Inventory controller/view sorting.
- Item and Box detail controller/view sorting.
- Purchase Order detail controller/view sorting for line, allocation, and receipt collections.
- Distinct query-parameter names and optional return fragments for multiple tables.
- Unit and feature coverage for all supported columns and table independence.

## Out Of Scope

- Sorting editable purchase-order, shipment, or receipt form rows.
- Sorting the printable control slip.
- Persisted preferences, drag ordering, client-side table libraries, or non-Storage modules.

## Data Touched

Only already-loaded Eloquent collections and existing read-only relationships. No stored data
changes.

## Permissions

No permission changes. Existing Tech/Admin route guards continue to apply.

## Tests

- Collection sorter allowlist, direction, type handling, null-last, and stable-tie unit tests.
- Warehouse, movement, box contents/events, order-line, shipment-line, and receipt-history feature
  tests.
- Independent sort state for pages with several tables.
- Non-sortable action and workflow-form headings.
- Responsive Blade compilation and current permission behavior.

## Documentation

- Parent RFC and TODO.
- Storage Knowledge and human-review instructions.
- Public-safe website handoff remains do-not-publish until review.

## Done Criteria

- [x] All listed read-only Admin/detail tables use the shared header contract.
- [x] Multiple tables retain independent sort state.
- [x] Form and print tables expose no misleading sort controls.
- [x] Unit, feature, Blade, Pint, PHP, and diff checks pass.
- [x] Human-review checks describe desktop, mobile, keyboard, and data-order verification.

## Verification Result

The dedicated collection-sorter and detail/Admin feature coverage passes with 9 tests and 147
assertions. The combined relevant regression package passes with 64 tests and 883 assertions. The
complete Storage suite passes with 95 tests and 1,233 assertions, and the complete Laravel suite
passes with 1,027 tests and 8,558 assertions. Human review remains open under
`HR-2026-08-04-002`.
