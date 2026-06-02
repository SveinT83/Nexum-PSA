# Sales API

The Sales API exposes sales opportunities and their activity stream for trusted integrations,
automation, and future AI agents.

All routes live under `/api/v1/sales` and use Sanctum bearer tokens.

Required scopes:

- `sales.read`: list and view opportunities.
- `sales.create`: create opportunities through the Sales opportunity engine.
- `sales.update`: update opportunities, add activities, and mark inbound activity as read.

## Opportunities

`GET /api/v1/sales/opportunities` lists opportunities.

Supported filters:

- `q`: searches opportunity title, opportunity key, and client name.
- `status`: filters by opportunity status.
- `client_id`: filters by client.
- `owner_id`: filters by assigned owner.
- `per_page`: controls pagination size.

`GET /api/v1/sales/opportunities/{opportunity}` returns one opportunity. The route parameter is the
public opportunity key, for example `SO-2026-ABCDEF`.

`POST /api/v1/sales/opportunities` creates a new opportunity. The endpoint uses the same
`StoreSalesOpportunity` action as the Tech UI, so opportunity keys, defaults, weighted value, initial
activity, and follow-up calendar behavior stay consistent.

Common create fields:

- `client_id`
- `primary_contact_id`
- `owner_id`
- `title`
- `type`
- `status`
- `summary`
- `needs`
- `estimated_value_ex_vat`
- `probability_percent`
- `expected_close_date`
- `next_follow_up_at`
- `next_follow_up_type`
- `next_follow_up_note`

`PUT` and `PATCH /api/v1/sales/opportunities/{opportunity}` update an opportunity and recalculate the
weighted value from estimated value and probability.

If `primary_contact_id` is supplied, the contact must belong to the selected client.

## Activities

`POST /api/v1/sales/opportunities/{opportunity}/activities` adds a sales activity.

Supported API activity types:

- `journal`
- `internal_note`
- `email_in`

`email_in` marks the opportunity as unread so technicians can see new customer communication.

The API intentionally does not send outbound sales emails in this slice. Outbound quote and email
delivery stays with the existing Sales UI and queued mail jobs until the email composition API is
designed and documented.

`POST /api/v1/sales/opportunities/{opportunity}/read` marks unread activities on the opportunity as
read.

## Operational Notes

Use the Sales API when an external workflow needs to create or update opportunities, register inbound
sales communication, or let an AI agent operate inside the Sales workflow with scoped access.

Use the Tech UI for quote versioning, quote approval, outbound quote sending, and sales-specific email
composition until those workflows receive their own API slices.
