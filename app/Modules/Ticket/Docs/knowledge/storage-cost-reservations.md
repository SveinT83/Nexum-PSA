Ticket storage cost reservations let technicians reserve stock items for a ticket before billing has been built.

Technicians use the `Add cost` action beside `Add time` on the ticket show page. The form selects an active Storage item, quantity, invoice text, and optional internal note. Saving the form creates a pending `ticket_cost_entries` record and a linked `storage_reservations` record.

The reservation increments the Storage item's `qty_reserved` value. It does not reduce `qty_on_hand`, does not create an invoice line, and does not decide whether the item is contract-covered or directly billable. Billing will settle that later.

Saved cost entries appear in the ticket Activity timeline as their own rows. Each row can be opened and edited. Editing quantity updates both the ticket cost entry and the linked Storage reservation, and adjusts the Storage item's reserved quantity by the difference.

Important status fields:

- `ticket_cost_entries.status = reserved` means the item is currently held for the ticket.
- `ticket_cost_entries.billing_status = pending` means billing has not settled the item yet.
- `storage_reservations.status = active` means Storage still treats the item as reserved.

Later billing should read pending ticket cost entries together with pending time entries. At that point it can decide whether to invoice the item, include it in a contract, convert the reservation to a stock movement, or release it if the work is cancelled.
