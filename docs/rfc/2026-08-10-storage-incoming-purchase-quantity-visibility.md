# RFC: Storage Incoming Purchase Quantity Visibility

Status: Approved
Date: 2026-08-10
Owner: Svein Tore / Codex

## Context

The Inventory Items queue shows physical on-hand, reserved, available, and reorder status, but it
does not project outstanding quantities from active Purchase Order lines. An item can therefore be
ordered and still appear only as **Should order**, without showing that stock is already on its way.

The reported Dev example proves the gap: item `IMP-18-1324745-5BA90BDA` has one active ordered line
on `AUTO-2026-00000011`, with one ordered, zero received, zero cancelled, and one outstanding, while
the inventory row shows no incoming quantity.

## Goals

- Show the outstanding quantity already expected from active Purchase Orders on each inventory row.
- Show **On order** instead of **Should order** when an item has a positive incoming quantity.
- Keep incoming quantity sortable and consistent with Purchase Order line progress.
- Preserve the existing reorder-focused queue so items awaiting stock remain visible until receipt.
- Keep receipt posting as the only action that increases physical on-hand stock.

## Non-Goals

- Changing Purchase Order, shipment, receipt, cancellation, or inventory-posting behavior.
- Treating draft, received, closed, cancelled, or deleted Purchase Orders as incoming stock.
- Adding Purchase Order identity or links to inventory users without Purchase Order permission.
- Changing Storage APIs, permissions, database schema, queue workers, or scheduler jobs.
- Automatically clearing the stored manual `should_order` flag before goods are received.

## Current Behavior

`StorageIndexQuery` selects Storage Items and derives reorder attention only from item-level stock
fields. The Inventory table has no incoming column. Its status badge renders **Should order** whenever
the item-level reorder rules match, even when active Purchase Order lines already cover the need.

## Proposed Change

`StorageIndexQuery` will add a correlated aggregate named `qty_incoming`. It is the sum of each line's
positive `qty_ordered - qty_received - qty_cancelled` balance where the parent Purchase Order is not
deleted and is currently `ordered` or `partially_received`.

The Inventory Items table will add a sortable **Incoming** column. Status presentation uses this
precedence:

1. **On order** when `qty_incoming` is greater than zero.
2. **Should order** when no incoming quantity exists and the existing reorder rules match.
3. **OK** otherwise.

The existing **Should order** availability view remains the reorder-attention queue. It continues to
show an out-of-stock or otherwise reorder-relevant item while stock is physically unavailable, but
the row makes its already-ordered state and exact incoming quantity explicit.

## Impact Analysis

- Storage query: add one bounded correlated aggregate and one allowlisted sort key.
- Storage UI: add one compact numeric column and one honest status state.
- Storage models: no persisted field or lifecycle change is required.
- Permissions: `storage.view` may see only the aggregate incoming quantity, not Purchase Order
  identity, supplier-order metadata, cost, tracking, receipt history, or Purchase Order actions.
- Purchase Orders and receiving remain the source of truth for outstanding quantities and stock
  posting.
- No route, API, integration, queue, scheduler, or cross-module mutation changes.

The main query risk is counting inactive or historical orders, or failing to subtract received and
cancelled line quantities. The status risk is implying that incoming quantity is already usable
stock. The active-status scope, positive outstanding calculation, separate On-hand/Incoming columns,
and regression tests mitigate those risks.

## Data And Migration Plan

No migration or backfill is required. Incoming quantity is calculated from existing Purchase Order
and Purchase Order line records. Deployment requires normal cache clearing only. Rollback removes
the aggregate, column, sort key, and status presentation without changing stored data.

## Testing Plan

- Feature coverage for active ordered and partially received line balances.
- Exclusion coverage for received, closed, cancelled, draft, and deleted orders.
- Coverage that received and cancelled line quantities reduce the incoming total.
- UI coverage for the Incoming value and On order / Should order / OK precedence.
- Sorting coverage for the new Incoming column with current filters preserved.
- Existing Storage and Purchase Order regression suites.
- Manual desktop and narrow-width review of the Inventory queue.

## Documentation Plan

- Update Storage Inventory Knowledge with incoming quantity and status semantics.
- Update Purchase Orders/Receiving Knowledge to explain when an outstanding order appears in
  Inventory.
- Add a focused human-review entry and track completion in `docs/TODO.md`.

## Open Questions

None. The reported behavior defines the product requirement, while active-status scoping and
aggregate-only visibility follow existing Storage and permission boundaries.

## Approval

Approved by Svein Tore in the Codex task on 2026-08-10 with the explicit requirement that an ordered
inventory item must show that it is ordered and how many units are on the way in.
