Availability: implemented and verified on Dev; source merged to Main in PR #379 on 2026-10-28. Production deployment and exact BookStack article/access read-back are not verified for this delivery. HR-2026-10-20-TICKET-TASK-API remains Pending for operational human checks.
HR-2026-10-20-TICKET-TASK-API. This article describes delivered read/context APIs. Implemented completion, actual-time and ordinary
form operations are documented in api-task-operations.md. Templates and schedules remain required.

## Prerequisites and scopes

Use an active internal account with task.view (global form context also allows task.create/update).
Create or deliberately update the API token through **Admin > System > Integrations > API Management**.
Select tasks.read for context, activities, children/dependencies and reference discovery; select
separate tasks.time.read for actual Task time. Existing tokens were not changed. Keep secrets out
of chat, documents, URLs and logs. Token scopes never replace the user's ordinary rights.

## Read Task context and work evidence

1. GET /api/v1/tasks/capabilities lists registered typed-parity operations and separate token/user
   eligibility. Object, private visibility and runtime checks remain required.
2. GET /api/v1/tasks/context returns defaults, supported owner types and reference catalog links.
   Follow /api/v1/tasks/context/{catalog} for bounded statuses, queues, priorities, categories,
   tags, Clients, Sites, active users, parent Tasks and Tickets. Use q, page and per_page.
3. Ticket lookup additionally requires tickets.read plus ticket.view. A Task grant alone never
   exposes Ticket search. Client/Site validation remains authoritative.
4. GET /api/v1/tasks/{task}/context identifies owner/Work Context and current completed/blocked
   state. If Ticket-owned, the rate URL still enforces its separate Ticket time/read permissions.
5. Read the required history using the endpoints below. GET does not complete Tasks, mark work
   read, register actual time or alter customer billing.

## Read endpoints

- GET /api/v1/tasks/{task}/activities
- GET /api/v1/tasks/{task}/time-entries
- GET /api/v1/tasks/{task}/children
- GET /api/v1/tasks/{task}/dependencies

Collections return data, meta and links with complete permitted totals. Default per_page is 25;
valid range is 1-100. Follow links.next to finish the result. Task time returns actual technician
minutes; it does not invent or duplicate the owning Ticket's billing delta or rate snapshot.
Private Tasks are visible only through the current permitted owner/creator/assignee projection.
A restricted dependency target has target:null and target_restricted:true, without its private ID
or title. Its existence as a required dependency is not a grant to read that Task.
Activity returns the permitted body and identity/timestamp but excludes arbitrary changes JSON.

## Troubleshooting

401 requires authentication; 403 means missing token ability or active-user rights; 404 means
unknown/inaccessible record/catalog; 422 means invalid bounds or mismatched owner/Work Context.
Do not work around denied access with a shared administrator token. PATCHing status is not proof
of domain completion; use the supported POST /api/v1/tasks/{task}/complete action.

No migration, frontend build, queue or scheduler change is required for these reads. Production
availability must be verified after Svein's separately authorized promotion/deployment.

## Task guide source

GET /api/v1/tasks/docs returns the same raw Markdown Task guide as the browser Docs link. It requires tasks.read and the active actor's current task.view permission. The response uses text/markdown and supports byte ranges; it does not create Tasks or activity. Authentication and permission failures remain JSON errors. This is the module's authoritative source guide, independent of any later BookStack publication.
