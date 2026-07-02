Economy is the internal workspace for preparing orders before billing. Orders are not customer-facing invoices. They collect billable lines from operational domains so billing and accounting integrations can process them later.

Current flow:

- Ticket time entries remain pending when a technician registers time.
- Without-contract ticket time can become an order line when the ticket is closed, according to Economy settings.
- Contract-backed time is calculated against the linked contract item service timebank for the selected period.
- Covered time is stored as a `ticket_time_entry_allocations` record and does not appear on orders.
- Partially covered time creates an order line only for the billable remainder.
- Timebank consumption is chronological. Economy processes pending ticket time by work date, creation time, and id so the earliest registered work consumes included minutes first.
- Yearly timebanks use the contract anniversary period instead of resetting every calendar month.
- Ticket Storage costs are first reserved. A reserved item does not create an order line.
- When a technician clicks `Pick` on a reserved ticket cost, Storage reduces on-hand and reserved stock, creates a movement, marks the cost as picked, and Economy creates the order line.
- Manual `Generate orders` in Economy runs the same generation logic for a selected period.
- Closing a ticket queues Economy order generation for the ticket's billing period.
- A daily scheduled Economy job runs as catch-up so picked costs and closed-ticket time do not pile up until month-end.

Orders are grouped as one draft order per client per billing period. A client does not get an empty order; the order is created only when the first billable line is added.

Economy remains client-only under the Work Context rollout. Ticket time, ticket costs, quick
timebank overuse, and other generated order lines must have a real Client before Economy can create
or attach them to an order. Internal Tickets, Tasks, Assets, and Calendar work are operational records
only and must not become customer order lines.

Order statuses:

- `draft`: open order that can receive automatic lines.
- `ready`: reviewed and ready for billing handoff.
- `approved`: internally accepted.
- `exported`: sent to billing or an accounting integration.
- `manual_invoiced`: manually marked invoiced inside Nexum PSA. No external export is implied.
- `cancelled`: voided.

Ready orders can be moved back to `draft` when a technician marks one ready by mistake. Approved or exported orders are not moved back by this workflow.

Line amounts are stored excluding VAT. Economy stores VAT amount and total including VAT when a line has its own VAT rate or can use the current Economy default VAT. The order list and detail views calculate display totals from active order lines so old lines missing a VAT rate still use the configured default.

Deleting a draft order line unlocks the source record:

- Ticket time is set back to pending and its allocation is removed.
- Ticket cost is set back to pending while the picked stock movement remains intact.
- The source record can then be corrected and included again by `Generate orders`.

Empty draft or ready orders can be deleted. When the last line is deleted from a draft order, Economy removes the empty order automatically.
