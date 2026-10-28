Availability: implemented and verified on Dev; source merged to Main in PR #379 on 2026-10-28. Production deployment and exact BookStack article/access read-back are not verified for this delivery. HR-2026-10-20-TICKET-TASK-API remains Pending for operational human checks.

## Prerequisites

Use the active user's own API identity with the same permissions as the browser. In **Admin > System > Integrations > API Management**, select the required scopes. Use **Task Templates** at /tech/admin/task-templates and **Task Settings** at /tech/admin/settings/tasks to compare the corresponding browser results. The capabilities endpoints and Swagger list the exact grants. Per-page limits are 1-100, default 25.

## Manage a template and its graph

1. GET /api/v1/task-templates and /task-templates/{template}. Select tasks.templates.read. Templates and existing generated Tasks are independent records.
2. POST /task-templates or PUT /task-templates/{template} with name, optional slug/description and explicit is_active. Managing templates requires tasks.templates.manage and task.manage_templates.
3. POST /task-templates/{template}/items or PUT /task-templates/{template}/items/{item}. Set the title, parent_id, assignment/status/queue/priority, estimate, offsets and completion-blocking flag. checklist_text contains one line per checklist entry. The template editor's tag_names value is a comma-separated string; ordinary Task tag_names is an array.
4. Set depends_on_ids to item IDs in the same template. Parent/dependency cycles and foreign IDs are rejected atomically. DELETE an item or template only when native reference/delete guards permit it.
5. GET /task-templates/{template}/history to verify the actor, event and changed-field names. Audit excludes raw descriptions, checklists and payload content. Configuration writes fail when the required audit journal is unavailable.

## Preview and apply

1. GET /api/v1/task-template-targets and choose a permitted user, Client or Ticket owner. Target permissions and private visibility are checked separately from the template scope.
2. GET /task-templates/{template}/preview with the selected owner context. Read the template snapshot and generation preview returned by the server.
3. POST /task-templates/{template}/apply using the documented owner fields, expected snapshot and a new idempotency_key for this intended generation. The exact Swagger request names are authoritative.
4. GET the returned generation run and its Tasks. Repeating the same actor/key/payload returns that generation; a conflicting payload or stale snapshot fails. Editing a template does not rewrite Tasks previously generated from it.

## Manage recurring generation

1. GET /task-templates/{template}/schedules, then GET the selected schedule. Use tasks.schedules.read and task.manage_templates.
2. POST or PUT the documented schedule payload with owner, schedule_name, interval, timezone and next_run_at. Supported intervals are daily, weekly, monthly and quarterly. The native configured creator and target remain authoritative.
3. PATCH the schedule's /active endpoint with an explicit is_active value. This setter is safe to repeat. DELETE uses the native schedule reference rules.
4. GET /run-preview before an explicit POST /run. Manual generation requires tasks.schedules.run, the documented expected snapshot and a stable idempotency_key. Read back /runs and the generated Task IDs.
5. Manual generation does not advance next_run_at. Scheduled processing retains bounded catch-up behavior and independent occurrence identities. A failed generation remains visible in history.
6. Verify the installation's external once-per-minute Laravel scheduler and queue operation separately before relying on unattended generation. API availability does not prove that the operating-system scheduler is running.

## Defaults and billing settings

GET/PUT /api/v1/task-settings use tasks.settings.read/update with task.manage_settings. GET /task-settings/context/{catalog} returns existing supported choices. POST /task-settings/initialize explicitly initializes supported defaults; GET never initializes them. GET /task-settings/history verifies configuration changes. Preserve the selected actual-time versus estimate-minimum billing policy; registering Task time does not change this policy.

## Troubleshooting

401 means authentication is missing. 403 means a token grant or current actor right is missing. 404 can mean a foreign nested ID or private target. 409/422 can indicate a stale snapshot, reused request key, graph/reference rule or disabled capability. Read the exact response and current run before retrying a failed generation. Use Swagger's complete payload and keep omitted/null semantics explicit.
