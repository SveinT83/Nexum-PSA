Tasks are internal work items that can be attached to any operational record in
Nexum PSA. A task can belong to a ticket, asset, client, site, storage workflow,
sales workflow, or directly to the user who created it.

Use tasks when work needs to be assigned, tracked, repeated, or split into
smaller pieces without creating a full ticket.

Core task concepts:

- Owner: the record or user that owns the task.
- Assignee: the person expected to do the work.
- Queue: the operational team or work queue.
- Priority: the urgency of the task.
- Status: the current task state.
- Due date: when the task should be finished.
- Estimate: the expected time to complete the task.
- Checklist: small steps inside the task.
- Child tasks: real subtasks with their own assignee, status, queue, and time.
- Dependencies: rules that block start or completion until another task is done.

Standalone tasks are owned by the creator. They can still be assigned to another
technician. This keeps personal or delegated work visible without forcing it into
a ticket.

Tasks are internal in the beta version. They are not sent to customers and do not
replace ticket replies.

## API

Task API routes are available under `/api/v1/tasks`.

Scopes:

- `tasks.read`
- `tasks.create`
- `tasks.update`

Routes:

- `GET /api/v1/tasks`
- `GET /api/v1/tasks/{task}`
- `POST /api/v1/tasks`
- `PUT /api/v1/tasks/{task}`
- `PATCH /api/v1/tasks/{task}`

`POST /api/v1/tasks` uses `StoreTask`, so task defaults, owner context, checklist items, and
creation activity remain consistent with the Tech UI.

Supported API owner context:

- `owner_type: client` with `owner_id`.
- `owner_type: ticket` with `owner_id`.

When a task is created for a Ticket, the API inherits queue, priority, category, client, site, and
assignee context from the Ticket unless the payload overrides those fields.
