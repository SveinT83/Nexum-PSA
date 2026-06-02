# Economy API

The Economy API exposes internal order preparation for external integrations and AI agents.

It does not create invoices, send invoices, or export accounting data. The API works with the same
draft order model that the technician Economy UI uses.

## Abilities

API tokens must be granted explicit Economy abilities.

- `economy.read` lists and views economy orders.
- `economy.create` generates draft economy orders from billable ticket time and picked ticket costs.
- `economy.update` moves draft orders to ready and ready orders back to draft.
- `economy.delete` deletes empty draft or ready orders and deletes draft order lines.

## Endpoints

- `GET /api/v1/economy/orders`
- `POST /api/v1/economy/orders/generate`
- `GET /api/v1/economy/orders/{order}`
- `POST /api/v1/economy/orders/{order}/ready`
- `POST /api/v1/economy/orders/{order}/draft`
- `DELETE /api/v1/economy/orders/{order}`
- `DELETE /api/v1/economy/orders/{order}/lines/{line}`

## Generate Orders

`POST /api/v1/economy/orders/generate` accepts optional `period_start` and `period_end` dates.

The endpoint calls the shared Economy generation action. It reads current Economy settings and may
create order lines from:

- closed or resolved billable ticket time, depending on settings
- picked ticket cost entries, depending on settings
- contract timebank overage, when covered time is exhausted

The response contains a summary with counts for touched orders, created lines, seen time entries,
ordered time entries, waiting contract entries, seen cost entries, and ordered cost entries.

## State Rules

Only draft orders can be marked ready.

Only ready orders can be moved back to draft.

Only draft or ready orders without lines can be deleted.

Only draft order lines can be deleted. Deleting a generated line unlocks the source ticket time or
ticket cost record so it can be recalculated by a later generation run.

## Search And Filters

The order list supports:

- `q`
- `status`
- `client_id`
- `period_start`
- `period_end`
- `per_page`

`q` searches order number and client name.
