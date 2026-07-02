Ticket cost entries let technicians capture material and expense costs before billing has been built.

Cost billing remains client-gated. Internal Tickets have no `client_id`, so picked cost entries on
internal work are kept for operational tracking and are not selected for Economy order generation.

Technicians use the `Add cost` action beside `Add time` on the ticket show page. The form supports two modes:

- `Storage item` reserves an active Storage item for the ticket.
- `Manual cost` records an ad-hoc cost such as parking, shipping, subcontractor work, or other
  expenses that should not be mapped to warehouse stock.

Storage-backed costs create a pending `ticket_cost_entries` record and a linked `storage_reservations` record. The reservation increments the Storage item's `qty_reserved` value. It does not reduce `qty_on_hand`, does not create an invoice line, and does not decide whether the item is contract-covered or directly billable. Billing will settle that later.

Manual costs create a pending `ticket_cost_entries` record without a Storage item or Storage reservation. Manual costs do not appear in the Storage picking list and do not alter stock counts.

Saved cost entries appear in the ticket Activity timeline as their own rows. Storage-backed rows are labeled `Storage cost`; manual rows are labeled `Manual cost`. Storage-backed reserved rows can be edited before picking. Editing quantity updates both the ticket cost entry and the linked Storage reservation, and adjusts the Storage item's reserved quantity by the difference.

Important status fields:

- `ticket_cost_entries.status = reserved` means the item is currently held for the ticket.
- `ticket_cost_entries.status = manual` means the entry is a non-stock cost with no picking step.
- `ticket_cost_entries.billing_status = pending` means billing has not settled the item yet.
- `storage_reservations.status = active` means Storage still treats the item as reserved.

Later billing should read pending ticket cost entries together with pending time entries. At that point it can decide whether to invoice the item, include it in a contract, convert the reservation to a stock movement, or release it if the work is cancelled.
