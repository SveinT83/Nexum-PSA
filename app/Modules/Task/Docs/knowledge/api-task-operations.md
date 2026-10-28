Availability: Dev implementation verified on 2026-10-20; scoped Main merge approved by Svein Tore on 2026-10-28. Production deployment and BookStack publication are separate, unverified steps for this API delivery. HR-2026-10-20-TICKET-TASK-API remains Pending for operational human checks; Main approval does not mark them performed.
Pending. This article covers ordinary Task operations; template, schedule and settings APIs are documented in api-templates-and-schedules.md. Production availability is not claimed.

## Prerequisites and scopes

Use an active internal user's own API identity. In **Admin > System > Integrations > API Management**,
choose the operation's ability and retain the same ordinary user permissions as browser work.
Private Tasks are restricted to the permitted creator/assignee/user-owner projection; a wildcard
token cannot reveal another user's private Task. Mismatched owner/Client/Work Context is rejected.

| Operation | Token ability | User permission |
|---|---|---|
| List/detail/form references | tasks.read | task.view (create/update also permits global form references) |
| Create/update fields | tasks.create / tasks.update | task.create / task.update |
| Assign | tasks.assign | task.assign |
| Set lifecycle status | tasks.status.update | task.update |
| Set checklist state | tasks.checklist.update | task.update |
| Complete through the domain action | tasks.complete | task.complete |
| Register actual time | tasks.time.create | task.update; ticket.register_time for a Ticket owner |
| Read actual time | tasks.time.read | task.view |
| AI field suggestions | tasks.ai.suggest | task.view AND ticket.view; additional context grants below |

## Create and edit the full Task form

1. POST /api/v1/tasks with title and the supported optional ordinary fields. Set owner_type to
   client or ticket with owner_id when the Task belongs to that record. A Ticket owner additionally
   requires tickets.read and ticket.view. Client/Site/parent selections must use the verified context.
2. Supply tag_names as an array of names. checklist_text contains one checklist item per line.
   For a Ticket-owned Task, ticket_rate_key selects a current eligible Ticket rate; the server
   stores the matching key/label. Raw metadata cannot substitute for rate validation.
3. PATCH /api/v1/tasks/{task} changes submitted fields. Omitting tag_names/checklist_text retains
   them; tag_names:[] and checklist_text:null explicitly clear them. Replacing checklist text replaces
   its item rows, as the browser editor does. Use the separate checklist state API for check marks.
4. GET /api/v1/tasks/{task} to verify fields, tags, rate metadata and checklist items. Parent cycles,
   private parents, foreign site/client combinations and inverted partial schedule dates are rejected.
5. GET /api/v1/tasks with optional q, mine, include_done, assigned_to, status/queue/priority, Client/Site,
   Work Context and supported browser sort/direction. Per_page defaults to 25 with a maximum of 100.
   API callers retain the existing all-record default unless they explicitly request mine/open filters.

## Assignment, status and checklist

1. PATCH /api/v1/tasks/{task}/assignee with assigned_to (active user ID or null).
2. PATCH /api/v1/tasks/{task}/status with an active status_id. This changes lifecycle status and
   records activity. A status value by itself is not evidence of domain completion or registered time.
3. PUT /api/v1/tasks/{task}/checklist/{item} with is_checked:true or false. This is a state setter:
   retrying true never toggles a completed check off. Foreign item IDs return 404.
4. Read the Task and its activity API to verify persisted state. Identical setters avoid duplicate
   activity in an ordinary replay. Browser and API share the same assignment/status/checklist actions.

## Register actual time and complete

1. POST /api/v1/tasks/{task}/time-entries with idempotency_key, work_date, minutes (1-1440) and
   optional note. For a Ticket owner, also provide rate_key and invoice_text. These are documented
   actual technician minutes; the server cannot silently approve an AI suggestion as actual work.
2. Reuse the same key/payload on uncertain retries. Created entries return 201/created:true;
   identical retries return 200/created:false. Changed actor/payload or a deleted result returns 409.
   Completed Tasks reject new time. Read the actual record list with tasks.time.read afterward.
3. Ticket-owned Tasks use the existing cumulative billing policy. Actual Task time and the Ticket's
   task_billing projection serve different purposes. An estimate minimum is applied cumulatively,
   rather than once per submission. Do not sum both ledgers as employee actual time.
4. POST /api/v1/tasks/{task}/complete runs dependency, child, checklist and time completion guards.
   For a Ticket Task without actual time, provide the same work_date/minutes/rate_key/invoice_text
   values and tasks.time.create plus task.update/ticket.register_time. Internal Tasks may use the
   existing estimated-time completion behavior. An existing actual-time record is reused.
5. Repeat completion safely to read the same completed result. Verify completed_at/completed_by
   and read back actual/billing entries separately. Pending billing is not an issued invoice.

## AI suggestions

POST /api/v1/tasks/ai-suggestions with title, description and a selected client_id, site_id,
ticket_id or parent_id. Optional form fields match the ordinary editor. tickets.read/ticket.view
are required because suggestions include related Ticket context; selecting ticket_id also needs
tickets.messages.read. Private parent Tasks and mismatched client/site/Ticket context are rejected.
The configured Task AI agent returns editable data suggestions. No Task, checklist, time, billing
or lifecycle change is applied. Validate and deliberately save chosen suggestions afterward.
No agent, provider, runtime switch or broader credential is enabled by this operation.

## Errors and availability

401 requires authentication; 403 means missing token/domain rights; 404 means inaccessible or
foreign nested records; 409 means a retry conflict; 422 identifies invalid context, a blocked
completion requirement, unavailable rate or AI agent. Read the current result before choosing a
new retry identity. These APIs require no schema migration or frontend build. Main promotion,
deployment and served OpenAPI regeneration remain Svein's responsibility. Handbook publication
and employee-assistant retrieval remain pending the supported configured BookStack path.

## Legacy internal Ticket owners

Older internal Tickets may have no stored Work Context. A Task can still use its valid internal
context while belonging to that Ticket. API create/read/edit preserves that legacy owner rather
than silently rewriting the Ticket. Current non-null owner contexts, client mismatch and private
Task rules remain enforced.
