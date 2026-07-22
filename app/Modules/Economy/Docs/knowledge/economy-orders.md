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
- Active Cloud Factory subscriptions linked to an eligible contract create one confirmed billing
  period per subscription and month. Economy converts each period into one
  `cloudfactory_licence` order line using the synchronized quantity, sale price, and currency.
- Repeated licence synchronization and order generation update the same source-backed line instead
  of duplicating the charge.
- `Export ready orders` sends ready and approved orders through the Data Exchange Economy Orders
  profile and stores the generated CSV/JSON/XLSX file in Data Exchange run history.
- Closing a ticket queues Economy order generation for the ticket's billing period.
- Closing with `customer_declined`, `cancelled`, or `no_sale` requires a reason and does not queue Economy order generation.
- A completed Ticket can be blocked when actual time and costs exceed its accepted quote scope plus the workflow state's configured tolerance.
- A daily scheduled Economy job runs as catch-up so picked costs and closed-ticket time do not pile up until month-end.

Orders are grouped as one draft order per client per billing period. A client does not get an empty order; the order is created only when the first billable line is added.

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

## Customer Portal

Economy orders are hidden from the Customer Portal by default. A technician can publish or hide an
order from the Economy order show page.

Portal order pages show:

- Order number.
- Period.
- Customer-safe status label.
- Active order lines.
- Quantity, unit, unit price, ex. VAT, VAT, and total including VAT.

Portal order pages do not show internal generation diagnostics, deletion actions, source edit links,
margin data, accounting export internals, or payment controls. Economy orders are currently
client-level records, so site-scoped portal memberships do not see order summaries until Economy owns
a site-level order split.

Publishing or hiding an order writes a CustomerPortal audit event. Published orders are summaries for
customer review, not provider-backed invoices or payment requests.

## Data Exchange Export

Economy does not own a standalone accounting export path. Economy Orders export uses the shared Data
Exchange runtime.

The default profile exports one row per order line and repeats the order/client fields needed for
billing review:

- order number, period, status, and order totals;
- client number, client name, organization number, and billing email;
- line date, type, description, quantity, unit, price, VAT, total, currency, and ticket key.

Generated files are visible from Admin -> Data Exchange with status, checksum, retention date, and
download action. Future Tripletex and PowerOffice profiles should reuse Data Exchange profiles
instead of adding a separate Economy export workflow.
