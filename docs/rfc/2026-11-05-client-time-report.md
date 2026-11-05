# RFC: Client time consumption report

Status: Approved
Date: 2026-11-05
Owner: Codex; reviewer: Svein Tore

## Context
Svein requested all Tickets, Tasks and time for a customer, for example tronderservice.no.
The existing Client Time tab excludes ordered entries and cannot serve as a historical report.

## Goals
One internal report from Client Time and Reports, inclusive work-date filters or all history,
all customer Tickets/Tasks including zero-time and archived records, detailed entries, totals,
Excel and PDF downloads. Preserve source attribution and prevent Task billing double counting.

## Non-Goals
Customer portal/public links, automatic sending, report builder, API/MCP expansion, payroll
approval, pricing changes or timebank pooling (#391) and agreement amendment (#390).

## Current Behavior
BuildClientTimeUsageEntries concatenates quick, Ticket and Task rows, then removes ordered rows.
TaskTimeEntry is registered effort; linked TicketTimeEntry is customer billing, possibly an
estimate minimum. Commercial allocation and quick-consumption snapshots are authoritative.

## Proposed Change
Clients owns report query/UI/export using existing plural legacy module; Report registry owns
discovery. Internal use is the conservative default because customer sharing was not answered.
Require report.view, client.view, ticket.view, task.view, commercial.timebank.view together.
Apply Task private visibility to Task rows and linked billing rows. Exclude inconsistent/internal
work contexts. Include archived history with labels; never restore deleted records.
Registered work = direct Ticket minutes + Task minutes + direct quick minutes.
Billing basis = billable Ticket minutes + quick minutes, separately from registered work.
Processed covered/billable minutes come only from stored allocations/quick snapshots.
Unallocated billable Ticket minutes remain explicitly unresolved, not guessed as overuse.
Date filters apply to entry work_date, not creation/closure; all objects remain listed.
Use same immutable in-request result for tables and downloads. Bound large queries with explicit
validation errors requiring a narrower period; no silent export truncation.
Exports contain internal notes and names; label internal and do not send automatically.

## Impact Analysis
Read-only Clients/Ticket/Task/Commercial/Economy projections; existing libraries for XLSX/PDF.
No source edits to financial workflows, grants, queue/scheduler/provider or database.
Client permission middleware and report-specific checks both apply. Private Tasks remain private.
Existing Dev dirty changes preserved. Related open #290 concerns external Worklog governance and
does not authorize identified external data export. This is an employee browser report only.

## Data And Migration Plan
No migration/backfill. Deploy source through ordinary approved promotion. Remove report routes,
registry entry and UI link to roll back; source records are never changed. No runtime activation.

## Testing Plan
Isolated Dev SQLite feature tests: date boundaries, zero-time/closed/archive, other client and
internal mismatch, privacy including billing projections, permission conjunction, ordered history,
actual 5 versus billing 30, quick/allocated/unresolved time, XLSX string safety, PDF output, discovery.
Run relevant existing Clients/Report tests. Human checklist HR-2026-11-05-CLIENT-TIME-REPORT.

## Documentation Plan
Client and Report Knowledge source plus actual BookStack publication/read-back, TODO and human
review. Record any blocked handbook publication with owner. Public website handoff Dev-only.

## Feature Slices
1. Done On Dev: read-only query, route, UI and report discovery with source-aware totals.
2. Done On Dev: Excel/PDF using identical dataset, regression matrix and handbook/read-back.
Each slice uses the same permissions and has no standalone financial mutation.

## Open Questions
Customer-safe sharing needs a separately reviewed audience/content policy; internal reports are
the default. Historical registered Task time can include estimate-completion provenance and is
not approved payroll or a guarantee of clock-measured actual work.

## Approval
Svein Tore, 2026-11-05: "Godkjenner" following the proposed report scope and required RFC.
Implementation authorized on Dev. No commit, push, Main merge or production deployment requested.

## Implementation evidence

Done on Dev. See plans/2026-11-05-client-time-report-verification.md. Human browser acceptance
and actual production promotion remain separate; no manual checkbox was marked Reviewed.

Source promotion approved by Svein Tore on 2026-11-05: "Godkjent. Fix å merge til main".
This permits the scoped commit/push/PR merge; production deployment and unperformed operational
checks remain separate.
