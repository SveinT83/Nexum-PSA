# Workday API Consumer Guide

## Approved upcoming contract change - 2026-10-05

The [automatic Workday/Tripletex RFC](../rfc/2026-10-05-workday-tripletex-automatic-time-sync.md)
will introduce effective Save and duration-only entries without separate employee confirmation.
Implementation and API compatibility work have not started. This guide continues documenting
the currently implemented API; do not omit its current confirmation steps until the new contract
is implemented, versioned/documented and activated.

Scope approved by Svein Tore on 2026-10-02: PSA delivers the APIs needed for later tooling.
LiteLLM replaces NexumMCP as the intended external direction. MCP/gateway adapters and live
tool testing are deferred. This guide describes the PSA contract, with no dependency on a
particular gateway, model, SDK or MCP server.

## Contract and availability

Canonical generated OpenAPI: https://dev.nexumpsa.eu/docs (JSON).
Interactive documentation: use the installation's existing API documentation entry point.
Base origin on Dev: https://dev.nexumpsa.eu. Paths below already include /api/v1.

The contract contains 35 operations across Workday, UserManagement and Calendar.
Every operation has a stable operationId, bearerAuth, explicit x-required-scopes and a
success schema. All scopes listed for an operation are required together; user permissions
and ownership remain additional server checks. Generate payloads from request schemas,
including referenced schemas; do not invent fields from UI labels.

The Dev employee pilot is active. Production activation remains a separate review/deployment decision.
Read-only settings are available to an authorized administrator even when the workflow is off.
A 404 on an employee operation can mean the feature is disabled, not that its API is missing.
Current work plans use the Workday deployment switch. Daily work, absence and reminders also
require the stored workflow setting. Cleanup has its own independent activation switch.

## Identity and grants

Use Authorization: Bearer with a personal token for the actual active employee.
There is no acting_user_id, user_id delegation parameter or administrator impersonation flow.
Existing coordinator/workload-bound tokens and system actors cannot enter the employee
Workday or work-plan APIs, including wildcard credentials. A future gateway must preserve
this employee identity; its connection design and credential storage are separate work.

Own Workday/absence data stays owner-scoped even for Superuser. Confirmed oversight requires
workdays.read-all and workday.view_all. It grants neither foreign drafts/absence reasons nor
permission to edit or confirm another employee's time.

Start with the abilities needed for the selected workflow; do not automatically add settings,
oversight or Task creation to every employee connection. Token abilities never replace current
permissions. No existing token was upgraded by this delivery.

## Operation inventory

All paths below are relative to /api/v1. IDs for Workday/absence/reminders are UUIDs;
Calendar plan-block {event} is its numeric data.id, not its UUID.

| Method | Path | Required scopes |
| --- | --- | --- |
| GET | users/me/work-plan | users.work-plan.read |
| PATCH | users/me/work-plan | users.work-plan.update |
| GET | calendar/work-plan/blocks | calendar.work-plan.read |
| POST | calendar/work-plan/blocks | calendar.work-plan.write |
| PATCH | calendar/work-plan/blocks/{event} | calendar.work-plan.write |
| DELETE | calendar/work-plan/blocks/{event} | calendar.work-plan.write |
| GET | workdays | workdays.read |
| GET | workdays/{work_date}/entry | workdays.read |
| GET | workdays/{id} | workdays.read |
| GET | workdays/{id}/history | workdays.read |
| PUT | workdays/{work_date}/draft | workdays.write |
| POST | workdays/{id}/preview | workdays.read |
| POST | workdays/{id}/confirm | workdays.confirm |
| POST | workdays/{id}/corrections | workdays.write |
| GET | workdays/{id}/sources | workdays.read plus selected source read scopes |
| PUT | workdays/{id}/allocations | workdays.write plus referenced source read scopes |
| GET | workdays/overview | workdays.read-all |
| GET | workdays/overview/{id} | workdays.read-all |
| GET | workdays/overview/{id}/history | workdays.read-all |
| GET | workday-absences | workday-absences.read |
| POST | workday-absences | workday-absences.write |
| GET | workday-absences/{id} | workday-absences.read |
| GET | workday-absences/{id}/history | workday-absences.read |
| PATCH | workday-absences/{id} | workday-absences.write |
| POST | workday-absences/{id}/cancel | workday-absences.write |
| GET | workday-reminders | workday-reminders.read |
| POST | workday-reminders/{id}/snooze | workday-reminders.write |
| GET | workday-reminder-preferences | workday-reminders.read |
| PUT | workday-reminder-preferences | workday-reminders.write |
| POST | workdays/{id}/task-conversions/preview | workday-task-conversion.write, tasks.read, tasks.create, tasks.update |
| POST | workdays/{id}/task-conversions | workday-task-conversion.write, tasks.read, tasks.create, tasks.update |
| GET | workdays/{id}/task-conversions/{token} | workday-task-conversion.write, tasks.read, tasks.create, tasks.update |
| GET | workday-settings | workdays.settings |
| PATCH | workday-settings | workdays.settings |
| POST | workday-settings/retention-preview | workdays.settings |

Own day reads require workday.view_own; draft/allocation/correction/Task conversion requires
workday.manage_own; confirmation requires workday.confirm_own. Absence uses
workday.absence_view_own / workday.absence_manage_own. Reminders require all three own-day
permissions. Settings require workday.manage_settings; Task conversion also requires
task.view/create/update. Work-plan profile operations require an active internal human;
Calendar plan blocks additionally require calendar.view/create/update/delete as appropriate
and personal-calendar ownership.

Sources keep their own grants: tasks.read/task.view, tickets.read/ticket.view and
calendar.read/calendar.view, as applicable. The exact source kind, visibility and ownership
are rechecked at save, preview and confirmation; merely holding workdays.write is insufficient.

## Read, write and retry conventions

| Workflow | Concurrency / retry rule |
| --- | --- |
| Workday draft, preview, confirmation, correction, allocations and Task preview/create | Current integer version (0 for first draft) and Idempotency-Key. Retry the identical method/path/body/key after a timeout. |
| Absence create/update/cancel | Same header rule; version 0 to create, current version to change/cancel. |
| Settings PATCH | Current settings version and Idempotency-Key. Only enabled is configurable here; retention remains three years. |
| Weekly plan PATCH | GET returns an opaque revision. Send revision, timezone, all seven working_hours days, and optional accept_calendar_conflicts. A stale revision returns 409. After a timeout, GET and compare before retrying; there is no mutation receipt header for this operation. |
| Plan block POST | request_id is a UUID in the body. An identical create retry returns the existing block; conflicting reuse returns 409. |
| Plan block PATCH/DELETE | Current version and scope (event/series); one occurrence needs occurrence_starts_at including numeric offset. PATCH also sends complete block fields/request_id. DELETE cancels rather than erasing history. After timeout, read the master list/current state before resubmission. |
| Reminder preferences PUT | Replace all three channel booleans; repeating the same replacement is safe. |
| Reminder snooze POST | Send generation. Immediate previous-generation retry returns the current receipt without extending snooze; other stale generations return 409. |
| Retention preview POST | No body. Read-only counts; no remote purge operation. |

Idempotency-Key is 8-100 letters, digits, dots, underscores, colons or hyphens.
A stored receipt is the original result, even after later revisions; GET returns current state.
Do not blindly retry 409 with a new key or replace a current version with a guessed one.

## Calendar entry and exact-minute editing

GET /api/v1/workdays/2026-10-05/entry opens a retained date without creating a workday.
It accepts only the date path parameter, with no query/body or acting-user override.
Use workdays.read plus workday.view_own; reading context does not require a write grant.
The read result is private/no-store. Existing day timezone wins over later profile changes.

- data.day is the saved Workday object or null; version is 0 until the first explicit save.
- planned_intervals and planned_minutes are effective plan after dated availability/absence.
- available_intervals subtract saved work and adjacent own reservations from that plan.
  Empty or unknown plans do not prevent manually recording actual work outside the plan.
- suggested_interval is at most 60 minutes in record-local time with an explicit offset.
  Its length is only a default: a meeting can be 45 minutes or any supported whole-minute duration.
  It is null without write capability, when no free planned time exists, or at the interval limit.
- can_edit reflects current draft state, the employee manage permission and workdays.write ability.
  requires_correction identifies a confirmed day. Every save still rechecks permissions/version.
- reserved_intervals includes only time boundaries from the same employee's retained neighboring
  work dates (four dates on either side), including a confirmed revision while correction is pending.
  It contains no other employee data or absence reasons.
- A malformed date/unexpected parameter returns 422; an expired date returns 404, including
  a date whose original record has already been purged. No expired history is recreated.

### Save one meeting

Read the date context first. If no day exists, a first PUT workdays/2026-10-05/draft can be:

~~~json
{
  "version": 0,
  "timezone": "Europe/Oslo",
  "description": "Work - unspecified",
  "intervals": [
    {"start": "2026-10-05T09:00+02:00", "end": "2026-10-05T09:45+02:00", "description": "Meeting"}
  ],
  "breaks": []
}
~~~

Send a fresh Idempotency-Key. Read back data.id, data.version and actual_minutes (45),
then GET the date context again. Never save suggested_interval as actual work without user intent.

### Add, edit or remove one interval

The existing PUT draft operation replaces the COMPLETE intervals and breaks arrays, just as the
modal submits the current day snapshot. It is not an append or a single-block PATCH.

1. GET the latest context/day and copy the currently saved intervals and breaks.
2. Keep only writable fields: intervals use start/end/description, breaks use start/end/included.
   Do not send response-only minutes, totals, revision IDs, state or UI editor_index.
3. Add a new interval, replace the intended interval, or omit the intended interval to remove it.
   Preserve every other interval and break. Removing the last interval is invalid.
4. Send the current version and a new Idempotency-Key. Intervals support whole minutes and
   touching boundaries. Overlap within the day or with adjacent records returns 422.
5. GET the day again to verify exactly what persisted. After 409, reread and review the changes;
   do not blindly retry a stale replacement. Identical timeout retries reuse the original key.
6. The response intervals are chronologically sorted. Array indexes belong to that returned
   version, not stable interval identities; reread before selecting a block for a later update.

allocations can be omitted to preserve existing source attribution, or explicitly replaced.
Preserved allocations/breaks must still fit the new intervals. Confirmed days must first use
workdayCorrection, then save/preview/explicitly confirm; prior confirmed history is preserved.
Saving, editing or removing intervals never implies confirmation, Task creation or billing.

### Later MCP tool mapping

Use generated operationId and request/response schemas as the adapter contract:

| Intended tool action | API operationId |
| --- | --- |
| Read a date, plan and available time | workdayEntry |
| List or inspect saved actual time | workdayIndex / workdayShow / workdayHistory |
| Add/edit/remove minute intervals and breaks | workdayDraft |
| Preview the exact saved revision | workdayPreview |
| Explicitly confirm own work | workdayConfirm |
| Start a traceable correction | workdayCorrection |

Each connection must preserve the actual employee identity. The adapter must obtain explicit
confirmation before workdayConfirm and handle version conflicts/retries using the rules above.
This is a handoff for later MCP development; no MCP server or gateway connection is implemented.

## Employee confirmation sequence

1. GET workdays/{work_date}/entry for the selected date, current version and free-time context.
   Retrieve additional plan/source details only when needed. Planned hours, Calendar evidence and AI suggestions
   do not establish actual work or authorize confirmation.
2. PUT workdays/{work_date}/draft with explicit actual intervals, break treatment and description.
   Work - unspecified is valid. Read data.id, data.version and persisted totals.
3. If useful, discover and explicitly allocate existing source minutes. The actual total does
   not increase when source minutes are attributed.
4. POST preview with the latest version and a fresh idempotency key. Present the returned exact
   snapshot, total, date, timezone and absence warnings to the employee.
5. Only after the employee's explicit instruction, POST confirm with the returned preview_token,
   version and confirmed=true. Include accept_absence_conflicts=true only after the warning
   was reviewed and acknowledged. The preview lasts at most 30 minutes and cannot confirm
   a changed revision.
6. GET the workday and history to verify the persisted result. A correction uses POST corrections
   with version/reason, then draft edits, a new preview and explicit confirmation.

Example first draft (local date/times; no user identity in the body):

~~~json
{
  "version": 0,
  "timezone": "Europe/Oslo",
  "description": "Work - unspecified",
  "intervals": [{"start": "2026-10-01T08:00", "end": "2026-10-01T16:00"}],
  "breaks": [{"start": "2026-10-01T12:00", "end": "2026-10-01T12:30", "included": false}]
}
~~~

These values produce 450 actual minutes. Dates are illustrative; use a retained actual work date.
Unpaid breaks are subtracted; billing increments and estimates cannot replace actual elapsed time.
Workday timestamps allow explicit UTC offsets/Z; ambiguous local times require an offset.
Plan blocks instead require local YYYY-MM-DDTHH:mm and reject ambiguous clock times.

Task conversion has a separate saved-activity preview and explicit create_task=true command.
Read conversion.task_id/task_time_entry_id and GET the Task, Workday and conversion receipt.
It creates a standalone internal non-billable Task and actual time, preserves Workday total,
and does not complete the Task or confirm the day. Source-domain visibility applies to the
description shown in the preview.

## Pagination, completeness and errors

Workday and absence lists/history use bounded pagination, normally 20 per page with maximum 100.
Calendar plan-block masters use 30 per page and include cancellation state. Follow next_page_url
only on the configured PSA origin and continue until null. A master list is not occurrence
expansion; use the documented Calendar event API when actual recurring instances are needed.
Oversight uses bounded date ranges and confirmed-only totals. Source discovery exposes limits/
completeness; missing or unavailable evidence must not be represented as zero or complete.
Pending reminders are a bounded personal inbox: up to ten eligible unread items among the fifty
most recent retained unread receipts, not an exhaustive reminder-history endpoint.

| HTTP status | Meaning / consumer action |
| --- | --- |
| 401 | Authenticate; do not prompt for a different employee ID. |
| 403 | Scope, permission or actor is denied. Do not retry through a privileged shared credential. |
| 404 | Disabled feature or missing/foreign/expired record; read current settings only if authorized. |
| 409 | Stale version, changed source, preview or conflicting retry. Reread and obtain renewed intent. |
| 410 | The original mutation receipt has expired; retention cannot be bypassed with a new key. |
| 422 | Input validation or required acknowledgement. Show field errors; do not silently invent values. |
| 429 / 5xx / network failure | Respect installation throttling; retry safe reads or the identical receipt-protected mutation. Use the workflow-specific rules above for writes without receipts. |

Time and absence copies expire at the original three-year deadline. Do not persist an independent
indefinite history in a future gateway. Remote purge, manager approval, annual leave balances,
advanced rota, Tripletex synchronization and phone-provider queue changes are not operations in
this contract.

## Evidence and remaining work

Latest: [date-entry API parity verification](../plans/2026-10-05-workday-entry-api-verification.md).
Earlier: [Slice 09 verification](../plans/2026-10-02-workday-slice-09-api-verification.md).
Tests use actual bearer authentication through Laravel's HTTP pipeline and isolated synthetic
records. Published OpenAPI is separately read back over trusted Dev HTTPS. These checks prove
PSA contracts; they do not claim a LiteLLM adapter, MCP session or production deployment.

Human-review entry HR-2026-10-01-WORKDAY remains In Review for the combined pilot.
Svein Tore approved the calendar/modal UX on 2026-10-05. Remaining explicit device, API identity,
real opted-in channel receipt and operational backup/restore checks are tracked there.
MCP/LiteLLM work is explicitly deferred by Svein and is not a blocker for this API handoff.
