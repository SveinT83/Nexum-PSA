Economy owns internal order generation and later billing orchestration. It should not own ticket work registration, contract definitions, or storage stock. Those domains expose pending records and Economy turns billable records into internal orders.

Domain ownership:

- Ticket owns operational work records: ticket time entries, ticket cost entries, ticket lifecycle, and activity rows.
- Commercial owns contract and coverage rules: included contract minutes, applicable rates, and whether a time entry is covered or billable.
- Storage owns stock: reservations, picking, on-hand quantity, reserved quantity, and stock movements.
- Economy owns order generation: order queues, order runs, open orders, order lines, idempotency, period handling, and later handoff to billing/invoice integrations.

Orders are internal billing preparation records. Customers do not see orders. Customer-facing invoices will be produced later by Billing or an accounting integration.

Order generation flow:

1. A source event occurs, such as a ticket being closed or a ticket cost being picked.
2. The source domain dispatches an event or requests Economy generation.
3. Economy queues a worker job rather than doing order generation inside the request.
4. The job evaluates pending source records, asks Commercial whether ticket time is covered or billable, and asks Storage only for picked cost state.
5. Economy creates or updates the customer's open order for the billing period.
6. Economy creates idempotent order lines so the same time entry or cost entry cannot be added twice.
7. Source records are marked with order/settlement status after order line creation or contract coverage.

Manual catch-up must use the same generation logic. The Economy UI needs a `Generate orders` action that can process a period, a client, or all pending records. This action is for records missed by event handling, delayed jobs, or settings changes.

Default settings:

- `create_orders_from_resolved_ticket_time`: off.
- `create_orders_from_closed_ticket_time`: on.
- `include_unresolved_ticket_time_in_period_close`: off.
- `create_orders_from_picked_ticket_costs`: on.
- `auto_pick_ticket_costs_on_resolved_or_closed_ticket`: off.
- `time_order_line_grouping`: `per_entry`.
- `order_line_text_format`: `ticket_date_text`.

Ticket time:

- Ticket time entries remain pending when registered.
- Default order trigger is ticket closed, not resolved.
- Economy must not blindly invoice all pending time. Before order line creation, Economy asks Commercial coverage logic whether the time is covered by contract/timebank.
- Covered time does not appear on orders. It belongs in later reporting.
- Billable time becomes one order line per time entry by default, preserving date, ticket reference, technician context, selected rate, quantity, and invoice text.
- Period-close billing can later include unresolved ticket time only when the Economy setting enables it.

Ticket costs and Storage:

- Ticket cost entries are reserved first.
- Reserved cost entries do not create order lines.
- A cost becomes orderable when it is picked.
- Picking requires available stock. If stock is not available, picking must be blocked until stock exists.
- Picking reduces `qty_reserved` and `qty_on_hand`, creates a `storage_movements` record, marks the ticket cost as picked, and then queues Economy order generation.
- Auto-pick can be added as a setting, but default is manual picking.

Open order grouping:

- One open order per client per billing period.
- The order is created only when the first billable order line is added.
- A client should never receive an empty order.
- Open orders can be manually sent to billing at any time, even before the period ends.

Order statuses:

- `draft`: open order that can receive automatic lines.
- `ready`: reviewed and ready for billing handoff.
- `approved`: locked internally.
- `exported`: handed off to Billing or an accounting integration.
- `cancelled`: voided and no longer billable.

Order line amounts:

- Lines store amounts excluding VAT.
- Lines may have a VAT rate. If a generated line does not carry its own VAT rate, Economy uses the current default VAT rate.
- Orders show subtotal excluding VAT, VAT amount, and total including VAT from the effective line totals.
- Default VAT comes from existing economy settings when available, and otherwise defaults to 25%. Set the Economy default VAT to `0` when a site should generate no VAT by default.
- `manual_invoiced` marks an order as manually invoiced without claiming that an external accounting export happened.

Implemented first:

- Economy module, navigation, settings, orders, order lines, and manual `Generate orders`.
- Idempotent source references on order lines with `source_type` and `source_id`.
- Manual `Pick` action for reserved ticket cost entries that have enough on-hand stock.
- Pick consumes Storage stock, creates movement history, marks the ticket cost as picked, and runs Economy generation.
- Without-contract closed ticket time can become order lines.
- Contract-backed time is calculated into `ticket_time_entry_allocations`.
- Covered time does not appear on orders. Partly covered time creates an order line for the remaining billable minutes.
- Pending time is processed by work date, creation time, and id so the earliest work consumes the timebank first.
- Yearly timebanks are calculated against the contract anniversary period.
- Ticket close queues Economy generation for the ticket's billing period.
- A daily scheduled Economy generation job catches up pending records.
- Deleting a draft order line unlocks the source record for recalculation.
- Ready orders can be moved back to draft before approval/export.
- Empty draft or ready orders can be deleted, and empty draft orders are removed automatically after their last line is deleted.

Important deferred work:

- Full timebank drawdown.
- Contract coverage reports for time that does not appear on orders.
- Billing runs and invoice drafts.
- Tripletex, PowerOffice, or other accounting exports.
- Customer-facing invoice rendering.
