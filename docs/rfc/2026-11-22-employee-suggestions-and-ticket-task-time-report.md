# RFC: Employee suggestions and Ticket/Task time reporting

Status: Approved
Date: 2026-11-22
Owner: Codex; product approver: Svein Tore

## Context

Svein requested clickable employee suggestions in the Workday report that narrow while typing,
and a Ticket/Task time report filterable by employee, Client, additional parameters and dates.
This improves existing Report, Workday, Ticket and Task capabilities rather than introducing a
report builder or another time-registration system.

Read-only inspection was performed on the authoritative Dev checkout /var/Projects/tdPSA,
branch Dev. The checkout contains extensive unrelated staged and unstaged work; preserve it.
After fetch, HEAD is six commits behind origin/Dev; origin/main has one unique commit and
origin/Dev has six unique commits. Do not reset, bulk-stage, or infer delivery from disk state.
The existing 2026-11-22 login-session implementation remains separately registered and is not
reprioritized or approved for additional actions by this reporting request.

Open GitHub Issue searches for Workday/report titles found no matching reporting task.
Existing Client reporting and its API have approved RFCs dated 2026-11-05; reuse their source
authority and regression cases. Implementation and scoped Main merge were later explicitly approved; production deployment remains separate.

## Goals

- Show every eligible employee as a selectable suggestion when the Workday employee field opens.
- Narrow suggestions immediately as the user types; support mouse and keyboard selection.
- Report registered Ticket and Task time across employees and Clients, with combinable filters.
- Show totals and grouped summaries alongside paginated individual time entries.
- Preserve authorization, historical provenance and the difference between work and billing.

## Non-Goals

A generic report builder, saved/scheduled reports, automatic customer delivery, payroll approval,
new time entry workflows, financial recalculation, source mutations, provider/runtime activation,
or widening external coordinator/AI data access.

## Current Behavior

- Workday's Confirmed workdays view has an ordinary Employee name search input and From/To.
  ConfirmedWorkdays already supports worker_id plus literal worker name search; both intersect.
  Dates default to the current Oslo month through today and allow at most 93 inclusive dates.
  The projection includes recorded/confirmed revisions and excludes private drafts.
- Workday oversight requires an active human viewer, enabled Workday and explicit
  workday.view_all. Report hub navigation additionally uses report.view.
- Client time consumption already has browser/API reporting, date filters, object/entry details
  and Excel/PDF exports. Its browser report requires selecting one Client and has no employee
  filter or cross-Client employee summary.
- ClientTimeReportQuery separates registered work, billing basis, processed allocations,
  unresolved billing and customer-charge reversals. It counts Task work once and gives linked
  Ticket billing projections zero registered-work minutes.
- The existing coordinator Worklog API is policy-bound and pseudonymized. It is not an
  identified employee-report API and must not be repurposed by bypassing its policy gates.

## Proposed Change

### 1. Workday employee selection

Use an accessible Bootstrap-compatible combobox/listbox beneath Employee name.

1. Opening the field shows the eligible employee directory in name order, including employees
   with no Workday records in the selected period. Results must not depend on matching workdays.
2. Typing filters names case-insensitively, including Norwegian names, while keeping the list open.
   Large directories may be paginated or scroll-loaded; every eligible employee remains reachable
   without requiring a minimum character count. Never silently show only the first fixed subset.
3. Clicking a result or pressing Enter on a highlighted result selects the employee's stable ID.
   Arrow keys navigate, Escape closes, and the selected result remains visible in the input.
4. Selecting a person uses worker_id as the exact filter. Editing or clearing the visible name
   clears a previously selected ID, preventing a stale hidden ID from intersecting a new name.
   Preserve existing literal free-text filtering for users who type without selecting.
5. Filter and Reset retain their existing purpose; changing names does not discard date choices.
6. Return only employee display/selection metadata. Exclude customer identities and system actors.
   Include former/inactive staff needed for historical reporting with a clear status label.
   Reuse the existing authoritative internal-employee classification; do not guess from role names.

Directory access uses the same Workday feature/identity/explicit oversight authorization as the
report, plus the appropriate personal API ability. Suggestions must not become a public directory.

### 2. Ticket and Task time report

Register a new report named **Ticket and Task time** under Reports. Keep Client time consumption
available for its existing customer/Commercial history purpose.

All filters combine with AND; leaving Employee or Client empty means all authorized matching
employees or Clients, so an employee report never requires choosing a Client first.

| Filter | Proposed behavior |
| --- | --- |
| Employee | Searchable clickable employee selector; empty means all eligible staff |
| Client | Searchable clickable name/number selector; empty means all visible Clients |
| Work date from / to | Inclusive original work_date, default current Oslo month through today |
| Source | All, Ticket, Task |
| Ticket / Task | Search/select a permitted record by reference/title |
| Work context | All permitted, Client work, Internal work; Client selection restricts to that Client |
| Billable | All, Yes, No, using the source record's existing meaning |
| Record status | Existing Ticket/Task statuses, interpreted within the selected source |
| Group by | Employee, Client, Ticket/Task, work date, or employee plus Client |

Include common date shortcuts (today, this week, this month, previous month, custom).
Offer all history subject to explicit size limits rather than changing Workday's separate 93-day rule.
Show internal work as Internal, without inventing a Client. A selected Ticket may include its Task
work in a clearly labelled breakdown; each source entry contributes to registered totals once.
A selected Task applies to that Task unless an explicit supported descendants option is provided.

Display date, employee, Client/internal context, Ticket/Task reference, permitted title/note,
registered duration and registration provenance. Links open the existing permitted detail pages.
Show full-filter totals and grouped subtotals independently of pagination. Use stable ordering.
Preserve historical/archived labels and former employee attribution where the existing domain
allows historical reads; never restore records as a side effect.

Main total = direct Ticket registered minutes plus Task registered minutes.
Task-linked Ticket billing projections must not add to that total. Estimated/completion-derived
Task entries remain labelled as registered/estimated provenance rather than payroll-approved work.
Workday actual time, Calendar plans and direct Commercial quick consumption are outside this
Ticket/Task work total. Existing financial details remain in Client time consumption; do not
mislabel a work-only billable filter as an invoice or timebank balance.
Customer-charge reversal does not erase registered work already performed.

Excel/PDF exports use the same authorized filtered dataset and grouping. Label exports internal.
Use explicit large-result errors asking for a narrower period; no silent truncation or page-only
export. Use existing export libraries and formula-safe spreadsheet cell handling.

### 3. Ownership and API parity

Report owns discovery, shared filters/presentation and orchestration. Ticket and Task own source
selection/projection rules. Workday owns its employee oversight suggestions and current filters.
Reuse established source-authority rules and common primitives where practical; avoid changing
the existing single-Client financial query into an unbounded loop over all Clients.

Browser and official typed API must support equivalent suggestions, filters, grouped totals,
paging and downloads. Use domain routes under app/Modules/{Domain}/routes.php and existing API
registration conventions; inspect the active ability catalog before selecting the narrow scopes.
Do not add a privileged identified mode to the pseudonymized coordinator endpoints.

## Impact Analysis

Affected: Report, Workday, Ticket, Task, Clients, Integration API contracts and Knowledge.
The report is read-only. Source reads and links must enforce current actor/record/context access,
including TaskApiScope private visibility. Suggestions cannot reveal inaccessible Clients, Tasks
or Tickets. Use existing report.view and source permissions, and client.view where customer data
is returned; do not seed additional grants or infer Workday oversight from report.view.

Existing Workday routes, recorded/confirmed semantics, retention and 93-day validation remain.
Existing Client browser/API reporting and Commercial correction semantics remain.
Identified personal reporting must not widen governed coordinator/provider permissions.
No queue, scheduler, mail, Tripletex or other provider effects are expected.

Risks: stale selection IDs, duplicate names, accidental customer directory exposure, private Task
leaks through parent/billing projections, Task double counting, misleading billable totals,
date-boundary drift, incomplete grouped totals, export mismatch and unbounded aggregation.

## Data And Migration Plan

No new tables, data backfill or source changes are proposed.
Inspect query plans during implementation; add an index only if evidence warrants a separately
documented additive migration under this RFC. An index is not implicitly authorized by this draft.
Rollback removes the new report registration/routes and employee combobox additions; original
time records and existing reports remain untouched.

After approved delivery, normal route/view/config cache handling, PHP-FPM cache refresh where
needed, and API contract generation apply. Document the exact commands at implementation handoff.
No worker/scheduler restart or runtime capability activation is expected.

## Testing Plan

- Feature tests for directory eligibility, empty input/all staff, partial names, Unicode,
  duplicates/stable IDs, inactive staff, no-period-record employees and authorization denial.
- Browser tests for immediate narrowing, clicks, Arrow/Enter/Escape, clearing/retyping after
  selection, date preservation, empty results and stale async response handling.
- API/browser parity tests for all filters, validation, private/no-store responses and denials.
- Ticket/Task source tests: actual 5 versus billing projection 30 counts 5 once; direct Ticket
  time, Task manual/estimated provenance, linked/unlinked/internal work, private Tasks,
  former/deleted employee references, archive history and customer-charge reversal.
- Date-boundary and combined-filter cases; grouped/full-filter totals across multiple pages;
  explicit oversized-result failure; no unauthorized identifiers in lookups/exports.
- XLSX/PDF filter parity and spreadsheet string safety; existing Client report regressions.
- Run narrow relevant Laravel suites on authoritative Dev with umask 0002 before handoff.
  No application tests are run during this planning-only task.
- Add a human-review checklist on implementation, covering click/keyboard selection, employee
  across Clients, Client across staff, combined filters, dates and matching downloads. It stays
  open until Svein Tore or another named reviewer explicitly confirms the listed checks.

## Documentation Plan

Update Workday confirmed-oversight, Report domain/API and relevant Ticket/Task/Client Knowledge
sources with exact menu/filter names, examples, totals semantics and Dev/production status.
Publish affected handbook sections through the supported BookStack integration and read back the
articles and existing audience scope after verified implementation. Employee-assistant retrieval
is a separate claim. Register publication blockers with owner in TODO.
Maintain the TODO register and human-review record; add a public-safe website handoff only when
there is an implemented verified feature suitable for editorial review.

## Open Questions

Svein Tore approved the behavior and scope on 2026-11-22. Additional parameters can follow a
separately approved extension; the listed filters are the concrete recommended scope.

## Approval

Svein Tore, 2026-11-22: Godkjenner. Implementer dette på Dev og så merge til main.
This authorizes scoped implementation, Dev publication and Main merge after verification.
Production deployment and runtime activation remain separate.

## Feature Slices

1. Employee lookup (Done On Dev): stable-ID Unicode directory, current authority, Workday combobox,
   mouse/keyboard and stale-result regressions. No source time changes.
2. Registered-time report (Done On Dev): Ticket/Task source adapters, combined filters, totals/groups,
   paged detail, XLSX/PDF, personal typed API parity and privacy/duplicate-count regressions.
3. Delivery (In Progress): affected regression matrix, synthetic browser QA, Knowledge/BookStack read-back,
   HR-2026-11-22-TIME-REPORTS and scoped Dev-to-Main promotion.
Each slice uses this RFC and ADR 2026-11-22-identified-registered-time-reporting.
Done requires Dev verification, source documentation and honest delivery/review status.
