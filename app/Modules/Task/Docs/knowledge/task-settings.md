Task settings are managed from Admin -> Task Settings.

The settings page controls defaults used when a technician creates a manual task:

- Default status.
- Default priority.
- Default estimated minutes.
- Customer billing minutes for Ticket-owned Tasks:
  - actual Task time, or
  - Task estimate as a minimum and actual time when it becomes higher.

Task statuses remain table-driven in `task_statuses`. The default status is stored on the
status row itself with `is_default`, so task creation and the task list use the same source of truth.

Task priorities currently reuse active Ticket priorities. This keeps urgency labels consistent while
Tasks and Tickets share operational queues and work planning behavior.

Ticket-owned tasks may still inherit values from the owning Ticket. Ticket context takes priority over
general Task defaults for queue, priority, category, assignee, and tags because those tasks are part
of an existing ticket workflow.

The default estimate is optional. When set, it is applied to new manual tasks unless another estimate
is supplied. Completion may use the estimate to create a task time entry when no actual time has been
registered.

The Ticket-owned Task billing mode never changes the technician's tracked time. In estimate-minimum
mode, a Task estimated at 30 minutes with 5 actual minutes produces 5 minutes of technician time and
30 customer-billable minutes. If cumulative actual time later reaches 60 minutes, customer billing is
increased to 60 minutes. The minimum is applied once to the cumulative Task total, not once per timer
session.

Future task template work should reuse these defaults rather than introducing separate hardcoded
values.
