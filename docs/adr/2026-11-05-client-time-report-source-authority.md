# ADR: Keep registered customer work separate from billing projections

Status: Accepted
Date: 2026-11-05
Decision Makers: Codex, under Svein Tore's approved customer report scope

## Context
Task actual/registered entries and Ticket-owned Task billing deltas can differ because the existing
Task policy applies a cumulative estimate minimum. The Client Time editor also hides ordered time.

## Decision
Implement a separate read-only Clients-owned report, discovered through Report. Materialize its
source queries within a read transaction; never call billing/allocation actions or the editing list.
Count direct Ticket, Task and direct Commercial minutes as registered work; count Ticket billing
projections only in customer billing basis. Show stored processed allocation metrics separately.
Use the same authorized dataset for page, XLSX and PDF; keep exports internal. Reuse existing
permissions as a conjunction and existing Task privacy checks; add no grants, packages or tables.
Legacy customer-owned Tasks without denormalized Client/context may be included by verified owner.
Explicit inconsistent/internal contexts are excluded. Archived history stays labelled and read-only.

## Rationale
This preserves source authority, historical orders, privacy and accounting semantics while adding a
usable report. Registered entries may themselves have estimate-completion provenance, so the report
must not claim employee-confirmed payroll or guaranteed clock-measured work.

## Consequences
No financial mutation or migration. Unallocated billable Ticket time stays unresolved instead of
inventing timebank coverage. Object rows are not inclusive parent rollups; Task parent is shown.
Report limits fail explicitly; exports cannot silently truncate. Identified customer delivery and
external AI exports need their own approved audience/governance policy.

## Alternatives Considered
Summing the editable Client Time list loses ordered rows and double-counts Task projections.
Recalculating historical allocation changes financial history. A generic report builder is already
post-beta backlog and unnecessary for this scoped report.

## Follow-Up
RFC 2026-11-05-client-time-report and HR-2026-11-05-CLIENT-TIME-REPORT. Production/Main are separate.
