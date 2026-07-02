# Feature Slice: Work Context Report And API Guardrails

Status: Done
Date: 2026-07-02
Parent: `docs/rfc/2026-07-01-work-context-organization-scope.md`
Owner: Codex

## Goal

Make adopted WorkContext modules discoverable through safe API filters and prevent client-oriented
reports from mixing internal work by default.

## User-Visible Behavior

Ticket SLA reporting defaults to client work. Technicians can explicitly switch the report to
internal work or all work when they need an operational internal view.

## Scope

- Add `work_context_id` and `context_type` filters to adopted module APIs.
- Keep legacy `client_id` filters for compatibility.
- Update Ticket SLA reporting to default to client work and expose explicit context selection.
- Document Commercial, Economy, and Sales as intentionally client-only under this RFC.

## Out Of Scope

- No generic global API context middleware.
- No new API abilities.
- No customer-facing report delivery.
- No internal invoicing.
- No legacy self-client migration or deletion.

## Data Touched

- Ticket, Task, Asset, Documentation, Risk, and Calendar API controllers/resources/queries.
- Ticket SLA report query/controller/view/tests.
- Report, WorkContext, Ticket, Task, Commercial, Economy, Sales, and module docs.

## Permissions

Existing domain API abilities and `report.view` remain unchanged.

## Tests

- Ticket and Task APIs filter by context type and Work Context id.
- Asset, Documentation, Risk, and Calendar APIs expose context filters/output where those domains
  now store Work Context.
- Ticket SLA report excludes internal records by default.
- Ticket SLA report includes internal records only when explicitly selected.
- Economy guardrails continue to exclude internal work from customer order generation.

Verification on 2026-07-02:

- `HOME=/tmp php artisan test app/Modules/WorkContext/Tests/Feature/WorkContextModuleTest.php app/Modules/Ticket/Tests/Feature/TicketModuleTest.php app/Modules/Task/Tests/Feature/TaskModuleTest.php app/Modules/Asset/Tests/Feature/AssetModuleTest.php app/Modules/Documentation/Tests/Feature/DocumentationModuleTest.php app/Modules/Risk/Tests/Feature/RiskSystemTest.php app/Modules/Calendar/Tests/Feature/CalendarModuleTest.php app/Modules/Knowledge/Tests/Feature/KnowledgeArticleTest.php app/Modules/Economy/Tests/Feature/EconomyModuleTest.php`
- Targeted suite result: 227 passing tests.
- `HOME=/tmp php artisan test` result: 671 passing tests and 4955 assertions.
- Knowledge docs were synced with the final Work Context batch: 12 chapters, 39 articles, `skipped = 0`.
- Dev queue verified after BookStack push: `pending_jobs = 0`, `failed_jobs = 0`.

## Documentation

Update WorkContext, Ticket, Task, Report, and billing guardrail docs, then sync to BookStack.

## Done Criteria

- Adopted module APIs can filter by WorkContext.
- Client-safe report defaults are enforced.
- Full test suite passes.
