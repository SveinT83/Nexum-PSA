Economy owns internal order generation and later billing orchestration.

Current implementation:

- `economy_settings` stores when ticket time and picked ticket costs become order candidates.
- `economy_orders` stores one draft order per client and period when at least one billable line exists.
- `economy_order_lines` stores idempotent source-backed lines for ticket time and ticket costs.
- `GenerateOrders` is the shared generation action used by the UI and ticket storage picking.
- `ticket_time_entry_allocations` stores the timebank calculation per ticket time entry.
- `/tech/economy` lists orders and exposes manual `Generate orders` for catch-up.
- `/tech/economy/settings` controls trigger defaults, line text, order prefix, and default VAT.
- Ready orders can be moved back to draft before approval/export.
- Empty draft or ready orders can be deleted. Draft orders are automatically removed when their last line is deleted.

Important ownership boundaries:

- Ticket owns work and cost registration.
- Storage owns stock and picking.
- Commercial owns contract rates, SLA, and service timebank definitions.
- Economy owns orders and order lines, not invoices.

Time handling:

- Without-contract ticket time can become an order line when the ticket is closed by default.
- Contract-backed ticket time is calculated against the service timebank on the linked contract item.
- Covered contract/timebank time does not appear on orders; it gets a covered allocation.
- If a contract time entry is only partly covered, Economy creates an order line for the billable remainder.
- Economy processes ticket time chronologically so the first registered work consumes the timebank first.
- Yearly service timebanks use the contract anniversary period, not the selected order month.
- Closing a ticket queues Economy order generation for that billing period.
- A daily scheduled Economy generation job catches up pending entries.

Cost handling:

- Reserving a Storage item on a ticket does not create an order line.
- Picking the reservation consumes stock, creates a `storage_movements` record, marks the ticket cost as picked, and runs Economy generation for the current period.
- Picked ticket costs become order lines when `create_orders_from_picked_ticket_costs` is enabled.

Pending work:

- Billing runs and accounting integration handoff.
- Richer contract period logic for yearly and one-time timebanks and renewal boundaries.
