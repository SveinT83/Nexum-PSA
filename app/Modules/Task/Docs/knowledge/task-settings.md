Task settings are managed from Admin -> Task Settings.

The settings page controls defaults used when a technician creates a manual task:

- Default status.
- Default priority.
- Default estimated minutes.

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

Future task template work should reuse these defaults rather than introducing separate hardcoded
values.
