# RFC: Ticket Storage Reservation Release

Status: Approved
Date: 2026-07-21
Owner: Codex

## Context

A technician can reserve a Storage item from a Ticket and later change the reserved quantity, invoice
text, and internal note while the entry is still reserved. There is no supported way to remove an
incorrect reservation before picking. The stale entry therefore remains on the Ticket, continues to
increase `storage_items.qty_reserved`, and stays in the Storage Picking List.

The immediate case is an item that is no longer needed because existing equipment was installed
instead. The technician needs a small removal action on the Ticket cost row, and expects updating the
quantity to zero to have the same result.

This is a cross-module Ticket and Storage workflow change. It must also remain safe for approved
planned lines, Economy eligibility, and concurrent edit/pick requests.

## Goals

- Let a technician remove a reserved Storage-backed Ticket cost before it is picked.
- Show a small, visually quiet removal button inside the Edit cost modal for reserved cost rows.
- Treat an updated quantity of zero as the same removal operation.
- Release the full quantity from `storage_items.qty_reserved`.
- Remove the entry immediately from the Storage Picking List and the normal Ticket activity timeline.
- Preserve an auditable record of what was released, by whom, and from which Ticket.
- Restore an approved planned line to a state where it can be converted again when its generated
  reservation is released.
- Prevent stale update, pick, and release requests from applying conflicting stock changes.

## Non-Goals

- Hard-delete reservation history or Ticket audit events.
- Remove picked, billed, queued, covered, credited, or otherwise settled cost entries.
- Return stock after an item has been picked.
- Delete manual non-Storage costs.
- Add or change API endpoints in this slice.
- Change Ticket or Storage permissions.
- Change Economy order generation rules.

## Current Behavior

A Storage-backed Ticket cost is created with:

- `ticket_cost_entries.status = reserved`
- `ticket_cost_entries.billing_status = pending`
- `storage_reservations.status = active`
- `storage_items.qty_reserved` increased by the requested quantity

Positive quantity updates keep the cost entry, linked reservation, and item reserved quantity in
sync. The web validator and action reject quantities below one. The Storage Picking List selects
Storage-backed cost entries whose status is `reserved`.

The Ticket activity timeline loads every cost entry. Reserved rows expose only `Pick` and `Edit`.
Existing approved-scope code already excludes cost entries with `released` or `cancelled` status
from actual-cost totals, but no current action creates those states.

## Proposed Change

### User Interface

- Add a compact outline-danger `Delete reservation` button inside the Edit cost modal for cost
  entries whose status is `reserved`.
- Give the icon button an accessible `Remove reservation` label and explanatory title.
- Open one shared Bootstrap confirmation modal. The message states that reserved stock will be
  released and the entry will disappear from the Picking List.
- Allow `0` in the Edit cost quantity field and show a short note that zero removes the reservation.
- Submitting quantity zero invokes the same domain action as the explicit removal button.

### Domain Operation

Add a Ticket-owned `ReleaseTicketStorageReservation` action. Inside one database transaction it
will:

1. Re-fetch and lock the Ticket cost entry.
2. Confirm that it belongs to the requested Ticket and still has `status = reserved`.
3. Lock the linked Storage item.
4. Reduce `storage_items.qty_reserved` by the full cost-entry quantity without allowing a negative
   value.
5. Change the linked active Storage reservation to `status = released`.
6. Change the Ticket cost entry to `status = released` and `billing_status = cancelled`.
7. If an approved planned line points to this cost entry, clear `converted_cost_entry_id` and
   restore the planned line to `status = approved` so it can be converted again.
8. Create a `storage_reservation_released` Ticket event with the actor and a snapshot of the item,
   reservation, quantity, invoice text, note, and previous states.

Released cost entries remain in the database for audit, but the normal Ticket activity timeline
excludes `released` and `cancelled` entries. The Ticket System events section remains the durable
visible audit trail.

Add a Ticket module `DELETE` route for the explicit button. The existing update route accepts zero
and delegates to the same release action. Positive quantities continue through the existing update
action.

The update, pick, and release actions will use compatible row locking and fresh status checks so a
stale browser request cannot both release and pick the same reservation.

## Impact Analysis

- **Ticket module**
  - Adds one release action and one controller endpoint.
  - Extends update validation from minimum one to minimum zero.
  - Adds the removal control, confirmation modal, and quantity-zero guidance.
  - Hides released/cancelled cost entries from the normal activity timeline.
  - Writes a Ticket audit event and restores linked approved planned lines for reconversion.
- **Storage module**
  - Reserved quantity is released.
  - The existing Picking List query automatically excludes the entry after its status changes.
  - The linked Storage reservation remains as released history.
- **Economy module**
  - No order is created because only picked, pending costs are eligible.
  - Release is rejected after picking or settlement.
- **Workflow and approved scope**
  - Released costs stay excluded from approved-scope actual totals.
  - Linked approved planned lines become eligible for conversion again.
  - Storage workflow facts must report released lines as not reserved until reconverted.
- **Permissions**
  - No new permission. The operation follows the existing authenticated Ticket cost edit workflow.
- **Routes**
  - Adds one web DELETE route in `app/Modules/Ticket/routes.php`.
- **API**
  - No API contract change.
- **Queues, scheduler, cache, and build**
  - No queue, scheduler, cache, or frontend build changes.

## Data And Migration Plan

No schema migration or backfill is required. Status columns are strings and the existing business
logic already recognizes `released` and `cancelled`.

Existing reservations are unchanged until a technician explicitly removes one. Rolling the code back
leaves released reservations and costs in safe non-pickable, non-billable states. Older code may show
the released cost as a read-only activity row, but it will not return to the Picking List because its
status is no longer `reserved`.

## Testing Plan

- Ticket feature test: the explicit DELETE route releases the reserved item quantity, marks the
  reservation released, marks the cost released/cancelled, removes it from rendered activity, and
  records the audit event.
- Ticket feature test: updating quantity to zero produces the same released state.
- Ticket regression test: positive quantity updates continue to work.
- Ticket feature test: a cost entry belonging to another Ticket returns 404.
- Ticket feature test: picked or otherwise non-reserved costs cannot be released.
- Ticket workflow test: releasing a converted approved planned line clears the link, restores
  `approved`, and makes Storage reservation facts false until conversion occurs again.
- Storage feature test: a released entry disappears from the Picking List and queue statistics.
- Run the focused Ticket, Ticket Workflow v3, Storage, and Economy module test sets on Dev.
- Browser-check the Ticket removal button, confirmation text, quantity-zero path, Activity removal,
  and Picking List removal.

## Documentation Plan

- Update Ticket Storage cost reservation Knowledge documentation.
- Update Storage Picking List Knowledge documentation.
- Update the Ticket technical operations reference.
- Add the approved implementation to `docs/TODO.md`.
- Add a pending human-review entry before implementation handoff.
- Sync changed Knowledge documentation to BookStack after Dev verification.

## Open Questions

None. The recommended behavior is release with preserved audit history rather than hard deletion.

## Approval

Approved explicitly by Svein in conversation on 2026-07-21.
