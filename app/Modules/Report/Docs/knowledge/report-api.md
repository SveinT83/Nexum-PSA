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
