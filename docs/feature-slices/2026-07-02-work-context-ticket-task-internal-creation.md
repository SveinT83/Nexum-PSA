# Feature Slice: Work Context Ticket And Task Internal Creation

Status: Done
Date: 2026-07-02
Parent: `docs/rfc/2026-07-01-work-context-organization-scope.md`
Owner: Codex

## Goal

Adopt WorkContext for new Ticket and Task records so no Client selected becomes explicit internal
work, while preserving current client-scoped behavior and billing safety.

## User-Visible Behavior

- New Tickets created without a Client are internal work.
- New Tasks created without a Client are internal work.
- Tickets and Tasks with a selected Client keep client-scoped behavior.
- Ticket and Task UI should label missing Client context as Internal instead of looking like missing
  data.
- Internal Ticket time and costs must not become Economy order lines.

## Scope

- Add nullable `work_context_id` columns to `tickets` and `tasks`.
- Backfill records with real `client_id` values to the matching client WorkContext.
- Leave existing `client_id = null` Ticket and Task records with `work_context_id = null` because
  historical nulls are ambiguous.
- Set WorkContext on new Ticket creation through module actions and API paths.
- Set WorkContext on new Task creation through module actions and API paths.
- Preserve existing `client_id` columns as reporting/API/billing compatibility fields.
- Update Ticket and Task model relations and API resources where useful.
- Update list/show/create UI wording where the absence of a Client now means internal work for new
  records.
- Add focused tests for internal/client context creation and Economy billing guardrails.
- Update Ticket, Task, and WorkContext Knowledge documentation.

## Out Of Scope

- No migration, hiding, deletion, or automatic conversion of the legacy self-client.
- No Asset, Documentation, Knowledge, Risk, Calendar, Report, Commercial, Economy, or Sales
  WorkContext adoption beyond preserving guardrails.
- No new permissions in this slice.
- No generic API context filter yet.
- No NexumRelationship routes, sync, or UI controls.
- No change that makes internal work customer invoiceable.

## Data Touched

- `tickets.work_context_id`
- `tasks.work_context_id`
- Existing `tickets.client_id` and `tasks.client_id` remain unchanged.
- Existing `work_contexts` client rows may be resolved by the WorkContext foundation action.

## Permissions

Existing Ticket and Task route/API permissions continue to apply. Separate internal-work permissions
remain a later decision when client-facing or restricted internal surfaces are introduced.

## Tests

- Ticket feature test: no Client creates internal WorkContext.
- Ticket feature/API test: selected Client creates client WorkContext and keeps `client_id`.
- Task feature/API test: standalone task without Client creates internal WorkContext.
- Task feature/API test: Client-owned or Ticket-owned task creates matching client WorkContext.
- Economy regression test: billable internal Ticket time/cost is not converted to an Economy order.
- Migration/backfill behavior covered through feature tests where practical and verified on dev DB.

Verification on 2026-07-02:

- `HOME=/tmp php artisan test app/Modules/Task/Tests/Feature/TaskModuleTest.php`
- `HOME=/tmp php artisan test app/Modules/Economy/Tests/Feature/EconomyModuleTest.php`
- `HOME=/tmp php artisan test app/Modules/WorkContext/Tests/Feature/WorkContextModuleTest.php`
- `HOME=/tmp php artisan test app/Modules/Knowledge/Tests/Feature/KnowledgeArticleTest.php --filter=repository_documentation_sync_includes_work_context_docs`
- `HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketModuleTest.php`
- `HOME=/tmp php artisan test` passed with 671 tests and 4955 assertions.
- `HOME=/tmp php artisan knowledge:sync-docs --module=WorkContext --module=Ticket --module=Task --module=Asset --module=Documentation --module=Risk --module=Calendar --module=Knowledge --module=Report --module=Economy --module=Commercial --module=Sales --push` synced 12 chapters and 39 articles with `skipped = 0`.
- Dev queue verified after Knowledge/BookStack and Economy jobs: `pending_jobs = 0`, `failed_jobs = 0`.

## Documentation

- Update Ticket Knowledge docs for internal/client context.
- Update Task Knowledge docs for internal/client context.
- Update WorkContext Knowledge docs with the Ticket/Task adoption state.
- Sync affected Knowledge documentation after implementation.

## Done Criteria

- New Tickets and Tasks store explicit WorkContext.
- Client-scoped Tickets and Tasks retain `client_id` compatibility.
- Existing ambiguous null-client Ticket and Task records are not reclassified.
- Internal Ticket billing guardrail is tested.
- Ticket/Task docs and Knowledge docs are updated.
- Narrow Ticket, Task, Economy, and Knowledge tests pass.
