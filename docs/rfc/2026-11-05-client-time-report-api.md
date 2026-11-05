# RFC: Customer time reporting API

Status: Approved
Date: 2026-11-05
Owner: Codex; product approval owner: Svein Tore
Parent: 2026-11-05-client-time-report.md (approved browser report, Main PR #395; records #396)
Classification: Level 3 API and cross-domain authorization extension

## Context

Svein requested an API for reporting after approving and merging the customer time report.
The parent explicitly excluded API/MCP expansion, so that exclusion must be superseded for this
scoped API before implementation. The initial request authorized investigation/RFC preparation; the subsequent explicit approval
of implementation and scoped Main merge is recorded below.

Current Report API discovers reports but does not execute the new customer time calculation.
A caller needs to retrieve all customer Tickets/Tasks and time, for example the customer identified
by tronderservice.no, with the same source-aware calculations and financial-history boundaries
as the employee browser report.

## Goals

- Complete operational read parity for the implemented customer time report: customer resolution
  through the existing Clients API, filters, totals, object rows, source entries and XLSX/PDF.
- Reuse the exact ClientTimeReportQuery and ExportClientTimeReport authorities; no duplicate
  calculation or summing Task billing projections into registered effort.
- Expose typed JSON, documented minute units/provenance and discoverable execution links.
- Preserve existing actor permissions and private Task visibility on every request.
- Make completeness and paging explicit, with no silent truncation.
- Finish the existing report rather than create a report builder or scheduling system.

## Non-Goals

Customer portal/public links, sending reports, saved report definitions, scheduling, invoice/payroll
approval, timebank entitlement changes/pooling, production deployment, gateway activation and
automatic scope/token grants. Existing coordinator Worklog governance (#290), provider approvals,
pseudonymization and installation runtime gates are not relaxed or relabelled.

This supersedes only the parent's API exclusion for the customer time report. MCP/gateway
registration, new report types and changes to Ticket SLA/Workday calculations remain separate.

## Current Behavior And Coverage Inventory

| Report surface | Implemented browser | Implemented API | This change |
| --- | --- | --- | --- |
| Report library/discovery | /tech/reports | GET /api/v1/reports; GET /api/v1/reports/{reportKey} (metadata) | Add supported result/export link descriptors without changing metadata-only access |
| Customer time, key client.time | /tech/clients/time-report; Client Time shortcut | No report-result or report-export endpoint | Complete the missing JSON and export operations |
| Ticket SLA | /tech/reports/tickets/sla | GET /api/v1/tickets/reports/sla; tickets.reports.read and report.view | Reuse/link the existing official domain endpoint |
| Confirmed workdays | /tech/workdays/overview and detail/history | GET /api/v1/workdays/overview and detail/history; workdays.read-all | Reuse/link current routes; preserve Workday rights and default-off runtime gates |

Inspection: Report/api.php and ReportResource, Ticket/routes.php, Workday/routes.php,
ApiAbilityCatalog, ClientApiGuard, ClientTimeReportDefinition, browser controller and shared query.
Related source work is Done On Dev/Main, with independent manual browser acceptance still pending
under HR-2026-11-05-CLIENT-TIME-REPORT. That operational gate does not block preparation of this
API extension; it stays open independently. The separate Client gateway repair is Done On Dev;
its live human acceptance does not authorize new report tools or grants.

## Proposed Change

Clients owns data, authorization, routes, controllers, resources and export. Report owns discovery.
Keep the established legacy Clients directory; no module rename or new framework is introduced.

### Domain routes and payloads

Add these module-owned GET operations under app/Modules/Clients/routes.php, in its API branch:

1. GET /api/v1/clients/{client}/reports/time
   Returns customer identity, inclusive work-date filters, generated_at, integer-minute totals,
   object/entry counts, metric definitions and links to object rows, entries and downloads.
2. GET /api/v1/clients/{client}/reports/time/items
   Returns paginated Tickets, Tasks and direct-timebank groups, including zero-time and archived
   objects, status, source IDs, parent reference and per-object metrics.
3. GET /api/v1/clients/{client}/reports/time/entries
   Returns paginated source entries with stable source/id identity, work date, employee,
   description/invoice text, provenance, registered/billing/coverage/unresolved minutes and order state.
4. GET /api/v1/clients/{client}/reports/time/export?format=xlsx|pdf
   Returns the existing complete binary download and correct content type/disposition.

Use date_from/date_to (YYYY-MM-DD, independently optional, inclusive, validate reversed range).
Use ordinary page/per_page for item and entry lists, with an explicit bounded maximum.
Summary totals always describe the entire authorized period, not only the current page.
Return total, returned, page, per_page, last_page/next links and truncated:false for a successful
complete result set. A limit failure is HTTP422 with a concrete narrowing explanation, never a
successful partial export. Keep the existing explicit 10,000 ceiling per source/object collection.

Stable row ordering is documented. Each response materializes the authorized report within the
existing read transaction. generated_at and filters are returned on each response. Separate pages
are live reads: concurrent edits may change results; no cross-request snapshot guarantee is claimed.
XLSX/PDF are generated from one materialized dataset, preserving complete report totals.

Missing customer is 404; malformed filters/format/page and ceiling violations are 422.
Unauthenticated is 401; insufficient current rights or token abilities is 403.
Set private/no-store for identified report responses and downloads.

### Authorization

- Add dedicated read ability clients.reports.time.read for summary/items/entries.
- Add dedicated export ability clients.reports.time.export; export requires BOTH read and export.
- Existing report.read remains metadata-only and does not acquire report-data access.
- Require an active, freshly loaded native actor plus ALL five current browser permissions:
  report.view, client.view, ticket.view, task.view and commercial.timebank.view.
- Preserve TaskApiScope privacy, archived/history behavior and explicit customer-context checks.
- API scopes and native actor rights are independent; test revocation, inactive users and
  unrelated/private records. No permission widening, role migration or automatic token grant.
- Identified names/internal descriptions are intentional parity with the employee report.
  Document these fields and the purpose on scope selection and Swagger. Never treat this as a
  pseudonymized Worklog export or as approval for an external AI provider/workload.
- A coordinator token containing only its existing Worklog abilities cannot call this API.
  Preserve existing approved coordinator bindings, policies and deny tests; no new binding,
  integration, recipient, provider or model configuration is part of implementation.

### Discovery and documentation

Keep existing metadata fields compatible. Add domain-owned supported API-link descriptors for
client.time, ticket.sla and workday.confirmed, including parameter requirements and necessary
abilities. A report link is not an access grant. Direct requests still enforce each domain's
current policy and runtime switches. Do not build a generic execution controller or duplicate
existing Ticket/Workday endpoints.

Add typed OpenAPI schemas for summary, object, entry, pagination, validation and binary exports.
Operation IDs must remain stable and unique. Validate generated Swagger references and route
coverage. Document current report metadata versus data access clearly.

## Impact Analysis

Clients: API routes, guard/controller/resources, shared request validation where useful,
query/export reuse and feature tests.
Report: discovery metadata/link contract and compatibility tests.
Integration: native ability catalog, human-readable descriptions and API-management docs.
Ticket/Task/Commercial/Economy: read-only source dependencies; no changed calculation or mutation.
Workday/Ticket report endpoints: discovery mapping only; existing domain guards remain authoritative.
Knowledge/BookStack: exact employee/integration guide, native scope prerequisites, examples,
minute semantics, completeness, error handling and Dev/Main/production status.

Risks: confidential descriptions and employee identity, scope confusion, paging consistency,
archived/private records, duplicate effort, API payload/large-export cost, and browser/API drift.
Mitigations: dedicated opt-in abilities, current native rights, shared sources, explicit limits,
no-store and meaningful permission/parity tests.

## Data And Migration Plan

No database migration, historical backfill, financial reallocation, queue/scheduler or frontend
build. No credential copy, new service actor or provider registration. All implementation runs in
the authoritative Dev working copy. Preserve existing dirty work and unmerged gateway fixes.
Rollback removes only the new API routes/abilities/discovery links. Do not revoke shared tokens
or alter existing browser, Worklog, Ticket SLA or Workday behavior as part of rollback.

## Testing Plan

- Route ownership, Sanctum abilities, unauthenticated/inactive/revoked native rights.
- Every missing browser permission and each missing export ability denies direct calls.
- Existing report.read/worklog-only tokens cannot retrieve the new report data.
- JSON summary/object/entry and browser/query parity using the same synthetic customer.
- All-history and inclusive/one-sided date filters; invalid/reversed dates/pages.
- Zero-time, archived and ordered history; other customer, internal/mismatched contexts and
  private Tasks plus their linked Ticket billing projections.
- Registered5/billing30 scenario; stored quick/allocated/unresolved totals without financial writes.
- Pagination counts and deterministic ordering; period-wide totals remain independent of pages.
- Oversized reports return explicit 422 across JSON and download paths.
- XLSX/PDF match the authorized query; real XLSX numeric/literal cells and PDF smoke.
- OpenAPI paths, typed schema references, operations, ability catalog and discovery-link coverage.
- Focused Clients/Report and affected API-management/coordinator regressions on Dev.
- Human-review checklist HR-2026-11-05-CLIENT-TIME-REPORT-API at implementation handoff:
  real intended consumer, access scope, report comparison, download and handbook retrieval.
  Automated tests do not mark the checklist Reviewed.

## Documentation Plan

Update authoritative Client/Report/API-management Knowledge source first. Publish exact affected
sections through the supported BookStack integration, read back actual pages and verify inherited
employee audience without widening rights. If blocked, record exact blocker/owner in TODO.
Employee OpenWebUI retrieval and actual gateway tool execution are separate verification claims.
Maintain TODO, RFC, source/API coverage evidence and human-review state in the same session.

## Feature Slices

1. Done On Dev: native JSON summary/items/entries with dedicated scopes, privacy,
   typed Swagger and source-parity tests.
2. Done On Dev: binary exports, discovery descriptors for the three existing reports,
   affected regressions, handbook publication/read-back and human-review handoff.

Do not move to unrelated report types or a generic reporting platform before this coverage passes.

## Open Questions

No blocking technical choice: reuse established native API conventions and existing report rules.
Any later gateway rollout, customer audience, scheduled delivery or external AI recipient requires
its own actual scope/processing approval rather than an inferred extension of this API request.

## Approval

Approved by Svein Tore as recorded below.
Request received 2026-11-05: "Kan du ta å lage API for rapportering?"
The previous Main merge approval applied to the browser report and its records, not this API.

Approved by Svein Tore, 2026-11-05: "Godkjenner, kjør på og merge til main etterpå".
Authorizes scoped Dev implementation, verification and Main promotion; no production activation.

Source delivery: Dev verified and Main merged PR #397,5b9b862a1ef79162266602afb4ad8f320e3830e7.
Independent full-tree read-back matches tested candidate; production/gateway rollout remains separate.
