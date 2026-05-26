Tasks have their own time entries for internal effort and workload reporting.

Manual time entries can be added when actual work time is known.

Estimated time can be used for fast completion. If a task is completed and no
actual time exists, the system creates a time entry from the estimate. This makes
small delegated tasks quick to close while still keeping reporting useful.

Ticket-owned tasks are different because customer work must stay connected to
ticket billing, contract rates, and timebank handling. When a task belongs to a
ticket, completion requires work date, minutes, rate, and invoice text. The
system creates a pending ticket time entry, writes ticket activity, and mirrors
the minutes back to the task as actual time.

This makes the ticket the billing source of truth while still allowing tasks to
split and assign the work.

Activity is internal in beta. The task activity stream records important events:

- task created
- status changed
- task completed
- template applied
- assignment changed
- dependency events
- internal notes

The activity table includes visibility so customer-visible task activity can be
added later without redesigning the module.
