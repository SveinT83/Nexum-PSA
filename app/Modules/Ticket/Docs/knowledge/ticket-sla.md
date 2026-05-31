Ticket SLA tracks response and resolution commitments for ticket work.

SLA policy comes from the Commercial SLA system, but Ticket stores the selected policy and due timestamps so technicians can act on SLA risk directly from the ticket workflow.

## SLA Resolution Order

When a ticket is created, SLA is resolved in this order:

1. Ticket Rule override.
2. Active Contract SLA for the client.
3. Global default SLA.

This order allows explicit routing rules to override customer contract behavior when needed, while contracts remain the normal customer-specific source of truth.

## Stored SLA Fields

Tickets store:

- `sla_id`
- `sla_source`
- `sla_source_id`
- `sla_snapshot`
- `first_response_due_at`
- `resolve_due_at`
- `first_responded_at`

The SLA snapshot protects historical tickets from later policy edits.

## First Response

The first public technician reply stamps `first_responded_at`.

This timestamp is used to determine whether the first response target was met. Internal notes do not count as first response to the customer.

## Resolution Target

The resolve due timestamp tracks when the ticket should be resolved.

`resolved_at` and `closed_at` are lifecycle timestamps owned by status changes through `ChangeTicketStatus`.

## Ticket Index SLA Signals

The Ticket list shows compact SLA risk badges.

The list can show:

- Response overdue.
- Resolve overdue.
- Upcoming target.
- No SLA.
- SLA policy name.

Ticket index can also sort by SLA risk so urgent work floats upward.

## Ticket Show SLA Details

Ticket show displays SLA context in the right-side details area.

Technicians can see:

- SLA policy.
- SLA source.
- First response target.
- Resolve target.
- First response completion.

## Applying SLA Manually

`ApplyTicketSla` provides a reusable backend action for applying an SLA policy and writing an audit event.

This is useful for future workflow actions, AI tools, or admin operations, but visible UI should only expose it when the behavior is complete and tested.

## Reporting

Current SLA fields are enough to build basic reporting for:

- Response overdue.
- Resolve overdue.
- Responded within SLA.
- Resolved within SLA.

Business-hours calculations are not part of the first reporting foundation unless explicitly added later.

## Implementation References

Important files:

- `app/Modules/Ticket/Services/TicketSlaResolver.php`
- `app/Modules/Ticket/Actions/ApplyTicketSla.php`
- `app/Modules/Ticket/Actions/StoreTicket.php`
- `app/Modules/Ticket/Actions/AddTicketMessage.php`
- `app/Modules/Ticket/Queries/TicketIndexQuery.php`
