# Report API

The Report API exposes report discovery for integrations and AI agents.

The Report domain owns the shared report hub and registry. Individual domains still own their own
report calculations and report-specific filters.

## Ability

API tokens need:

- `report.read`

## Endpoints

- `GET /api/v1/reports`
- `GET /api/v1/reports/{reportKey}`

## List Reports

`GET /api/v1/reports` returns reports visible to the authenticated API user.

Supported filters:

- `domain`
- `q`

`q` searches report key, title, description, domain, and tags.

## Report Metadata

Each report entry includes:

- stable report key
- title
- description
- owning domain
- required permission
- tags
- UI route name and URL

## Scope Boundary

This API does not calculate report results yet.

Report result APIs should be added when a shared runnable report contract exists or when the owning
domain exposes its own report-result API. This keeps the Report domain decoupled from Ticket, Asset,
Commercial, and future reporting queries.

Work Context filters are exposed by adopted domain APIs instead of the Report API. Current adopted
domain list endpoints use `work_context_id` and `context_type` where the domain owns the underlying
records, including Ticket, Task, Asset, Documentation, Risk, and Calendar.

## Coordinator Worklog API

Approved workload-bound coordinator tokens can use:

- `GET /api/v1/worklog/technicians` with `worklog.read`.
- `GET /api/v1/worklog/time-entries` with `time-entries.read`.

Both accept `date_from` and `date_to`. Time entries also accept `page` and `per_page`. Policy sets
maximum date range, page size, result count, and request rate.

The technician endpoint returns aliases and aggregate minutes, billable minutes, entry count, and
active days. The time-entry endpoint returns aliases, source type, work date, minutes, and billable
state. They omit names, contact details, customer names, titles, descriptions, messages, notes,
invoice text, attachments, rankings, credentials, and secrets.

Ticket owns `GET /api/v1/tickets/stale`, and Task owns `GET /api/v1/tasks/stale`. Those endpoints use
the same workload policy and audit path and accept `stale_days`, `page`, and `per_page`.

Every allowed or denied request creates a metadata-only Integration audit event with a stable reason
code. See the AI Privacy & Coordinator Governance article for policy, token, and retention details.
