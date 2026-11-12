Tasks are internal work items that can be attached to any operational record in
Nexum PSA. A task can belong to a ticket, asset, client, site, storage workflow,
sales workflow, or directly to the user who created it.

Use tasks when work needs to be assigned, tracked, repeated, or split into
smaller pieces without creating a full ticket.

Core task concepts:

- Owner: the record or user that owns the task.
- Work Context: whether the task belongs to the owning organization or an external Client.
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
technician. When no Client is selected, standalone tasks are internal work for
the owning organization. This keeps personal or delegated work visible without
forcing it into a ticket.

By default, the technician task list shows only genuinely open work (Open, In
Progress, or Blocked) assigned to the authenticated user. The default ordering
prioritizes the most important work: tasks are sorted by priority level (P1 first),
then by due date (earlier dates first), with a final tie-breaker on the task ID.
Technicians can explicitly change these filters or sort options to see all tasks
or different priorities.

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
assignee context from the Ticket unless the payload overrides those fields. It also inherits the
Ticket Work Context. API responses expose `work_context_id` and `work_context` alongside the
existing `client_id`. The list endpoint supports `work_context_id` and `context_type` filters for
`internal` and `client`.

## Local timestamp display - production update, 30 October 2026

Merged to main in PR #387 and installed as a scoped production patch on 30 October 2026.
Production source/hash and formatter checks passed. Employee browser acceptance remains Pending.

The Task list, details and quick-view modal show deadlines in the viewing employee's existing
profile timezone.
The due column/detail identifies the timezone. Task completion and activity timestamps use
the same conversion. With Europe/Oslo, 19:58 UTC displays as 20:58 in winter or 21:58 in summer.
Legacy preferences are used only without a profile timezone, then the application timezone.
Check **Profile > Work plan** if those settings disagree.

Stored UTC instants, sort order, date-only values, registered minutes, estimates and billing
totals stay unchanged. API timestamps keep their explicit offset. This display fix does not
reinterpret stored deadlines or change Task scheduling/input semantics.

## Creator and last editor (Production, 12 November 2026)

Availability: merged to Main in PR #403 and installed and verified in production
on 12 November 2026. Ticket and Task pages and API read-back passed authorized
server checks. Svein Tore approved HR-2026-11-12-TICKET-TASK-ATTRIBUTION and the
production rollout. Retrieval through an employee assistant is a separate check.

You need ordinary permission to view the Ticket or Task. This adds no new access
to records or another user's private Tasks.

1. Open **Tickets** or **Tasks** and open the record.
2. Read the small **Created by** and **Updated by** line below the subject/title.
3. **Created by** identifies the original creator. Assignment changes do not
   replace the creator.
4. **Updated by** identifies the verified actor for the last recorded domain
   change, such as an edit, status/assignment change, tag/custom-field update,
   or a change to the Task's own checklist. The displayed time uses your Nexum
   date/time preferences.
5. Reading, marking a Ticket read, unchanged submissions and technical timestamp
   touches do not replace the last editor. Comments and time entries retain
   their own authors; a parent workflow/field change is attributed separately.

**Unknown** means attribution is absent or the user reference is unavailable.
Existing history is not guessed from the assignee or email sender. An older
record may have a known name but no verified edit time. Automation with a
verified managed actor shows that actor's name; unattributed background changes
do not impersonate the previous employee.

### API read-back

Authorized list, detail and record mutation responses include:

- `created_by` and `updated_by`: nullable user IDs, set by the server.
- `creator` and `updater`: nullable objects containing only `id` and `name`.
- `created_at`: the original creation time.
- `actor_updated_at`: the last tracked domain change time; null for older
  records without that evidence.
- `updated_at`: the existing general timestamp, which may also change for
  technical touches. Use `actor_updated_at` with `updater` for last-editor display.

Do not send actor IDs or actor timestamps as write inputs. The authenticated
operation supplies the actor, and unsupported attribution inputs are rejected.
A null actor reference is an unknown/unavailable identity, not an authorization
to assume a particular employee. Existing token scopes, record permissions and
private Task restrictions still apply.

If the line is absent, check which environment/version you are using. If an old
record shows Unknown, that is expected until a real supported change supplies
new evidence. Do not rewrite historical creator identities to make the line
look complete.
