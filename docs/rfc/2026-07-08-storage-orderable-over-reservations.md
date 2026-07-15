# RFC: Storage Orderable Over-Reservations

Status: Approved
Date: 2026-07-08
Owner: Codex

## Context

GitHub issue #177 reports that technicians cannot reserve a Storage item on a Ticket when the item
is out of stock. That blocks the intended reorder workflow: a technician should be able to record
that a ticket needs an item even when it must be ordered first.

Storage documentation already describes over-reservation as allowed and visible in the reorder
queue, while picking is blocked until enough on-hand stock exists. The implemented Ticket
reservation validation currently rejects reservation quantities above available stock.

## Goals

- Let technicians reserve active Storage items even when available quantity is zero or too low.
- Keep picking blocked until enough on-hand stock exists.
- Make over-reserved items appear in the Storage `Should order` view using existing reorder logic.
- Let admins/technicians mark active Storage items as not orderable.
- Reject over-reservation for items marked as not orderable, while still allowing in-stock
  reservations up to available quantity.
- Cover create and update reservation paths with tests.

## Non-Goals

- Build purchase orders, supplier ordering, receiving, or procurement automation.
- Change Economy order generation.
- Change inactive item behavior.
- Add customer-facing cost approval.

## Current Behavior

Ticket Storage reservations require `qty_available >= requested quantity`. The Ticket show item
picker also disables items with available quantity below 1. Storage picking already blocks rows with
insufficient on-hand quantity and labels them as waiting for stock.

Storage item records do not have an explicit "cannot be ordered" flag. `status = inactive` hides an
item from active workflows, but it is not the same as an active item that may be used only while
stock exists.

## Proposed Change

Add `storage_items.can_be_ordered` with default `true`.

Ticket reservation behavior:

- If the item can be ordered, allow reservation and reservation quantity updates above available
  stock.
- If the item cannot be ordered, keep the current available-quantity guard.
- Always keep picking guarded by on-hand quantity.

Storage item behavior:

- Web and API create/update surfaces expose `can_be_ordered`.
- The Ticket item picker keeps out-of-stock orderable items selectable and labels them as requiring
  ordering.
- Over-reserved items continue to appear in the Storage reorder-focused view through existing
  `needs_reorder` and suggested quantity logic.

## Impact Analysis

- Storage:
  - Adds one boolean column to `storage_items`.
  - Updates item model, web forms, API resource, and item API validation.
  - Updates Knowledge documentation.
- Ticket:
  - Updates Storage-backed reservation create/update actions.
  - Updates Ticket show item picker behavior.
  - Updates Ticket Knowledge documentation.
- Economy:
  - No direct behavior change. Picked costs still drive Economy order generation.
- Permissions:
  - No permission changes. Existing Storage item create/update and Ticket cost routes apply.
- Routes:
  - No route changes.
- Queues and scheduler:
  - No queue or scheduler changes.

## Data And Migration Plan

Add a boolean column:

- `storage_items.can_be_ordered` boolean default `true`.

Existing items default to orderable so current behavior is preserved except that out-of-stock active
items can now be reserved. Rollback drops the column.

## Testing Plan

- Ticket feature test: out-of-stock orderable Storage item can be reserved and appears as waiting
  for stock.
- Ticket feature test: not-orderable item cannot be over-reserved.
- Ticket feature test: not-orderable item can still be reserved up to available quantity.
- Ticket feature test: reserved quantity can be increased beyond stock only when item is orderable.
- Storage feature/API tests: item create/update/resource preserve `can_be_ordered`.
- Run focused Ticket and Storage test suites on Dev.

## Documentation Plan

- Update Storage inventory Knowledge docs.
- Update Ticket Storage cost reservation Knowledge docs.
- Update `docs/TODO.md`.
- Sync changed Knowledge docs to BookStack after Dev verification.

## Open Questions

None.

## Approval

Approved by Svein in conversation on 2026-07-08 after creating GitHub issue #177 and asking to move
on to the next implementation item.
