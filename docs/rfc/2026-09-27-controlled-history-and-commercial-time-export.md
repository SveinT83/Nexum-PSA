# RFC: Controlled History And Commercial Time Export

Status: Approved
Date: 2026-09-27
Owner: Codex / Svein

## Context

Paperclip through NexumMCP needs a complete, checkable time basis for 2025-09-01 through 2026-08-31.
Existing coordinator worklog is approved under the 2026-07-14 AI data access RFC. Its 2026-09-27
maintenance repair documents existing endpoints, exposes true counts/truncation, retains the result
ceiling and fixes inclusive date validation. Those changes do not approve new data sources or joins.

Inspection found two real extensions and a documentation/implementation discrepancy: Commercial
quick timebank usage is absent, ordinary Commercial IDs cannot be joined to workload aliases, and
the installation context_scope is not applied by the current coordinator query/evaluator. Current
Dev policy is off and no coordinator_api workload exists. NexumMCP's production package rejects
worklog/reports before contacting the provider. No production export has been verified.

## Goals

- Full target: an auditable history of actual Ticket/Task time AND separately classified Commercial
  direct timebank registrations, with permitted pseudonymous contract linkage.
- Preserve all installation, workload, recipient/model, token, expiry, rate, audit and privacy gates.
- Prove no missing rows and no multiplication of actual time by billing/coverage projections.
- Block external activation until implemented context boundaries match approved policy.

## Non-Goals

No identified employee/client export, free text, payroll approval, employee ranking, generic report
builder, automatic policy widening, production activation, catalog update or Main deployment.
No conversion of Task estimates/call durations into actual time. No guessed historical contract match.

## Pre-Repair Behavior (Inspected 2026-09-27)

Report worklog reads TicketTimeEntry excluding task_id rows, plus TaskTimeEntry. It excludes direct
Commercial ClientContractTimeConsumption. Ticket billing projections and allocation rows represent
billing/coverage, not additional actual work. Aliases are workload/type/key scoped; ordinary
Commercial contracts return raw IDs, customer/business details and document readiness.

The current installation context_scope options are internal_only, selected_clients and
selected_work_contexts. Workload allowlists alone currently narrow worklog. Empty lists do not narrow.
The endpoint checks report.view but does not invoke granular source-record access policies.

## Proposed Change

Recommended disclosure decision: allow the same approved coordinator to read actual time and direct
Commercial timebank registrations as distinct fact types, with workload-scoped aliases only. Keep
billing/coverage amounts separate from actual-minute totals and expose no inverse identity map.

After this decision is approved, implement in ordered, testable slices:

1. Integration + Report/Ticket/Task context enforcement: apply the installation maximum and workload
   intersection consistently. internal_only permits only explicit internal Work Context; selected
   modes require non-empty corresponding allowlists and fail closed otherwise. Reuse authoritative
   source visibility rather than granting access through a report permission alone. Preserve current
   recipient/model approval. Add denial/filter tests before enabling any external workload.
2. Commercial-owned read-only quick consumption endpoint, proposed
   `/api/v1/commercial/worklog/time-consumptions`, with a new explicit read ability, same workload
   gate, dates/paging/truncation contract, source `quick_client`, entry/client/contract/item/user
   aliases, work_date and minutes. It is not a billable boolean or payroll record. No price/note/name.
3. Commercial-owned pseudonymous contract/coverage projection for the same workload. Direct Ticket
   contract links and persisted allocation evidence may be exposed with opaque entry/contract/item
   aliases and explicitly labelled covered/billable quantities. A Task billing projection may relate
   several actual entries to a rounded cumulative delta; never distribute that delta across actual
   entries without authoritative evidence. Unlinked/ambiguous evidence must remain explicit. Final
   route/ability names and cardinality are frozen in the approved slice before implementation.
4. Complete export manifest and reconciled fixture across all approved source types. Keep the
   existing per-window maximum_results ceiling. Split dates when possible; a single overflowing day
   remains a visible blocker requiring an approved finite policy change. Do not silently reinterpret
   maximum_results as a per-page allowance. A snapshot/bulk export is separate approval if required.

This Level 3 expansion and context-boundary clarification was approved by Svein on 2026-09-27. It does not authorize creating production credentials or assigning new recipients.

## Impact Analysis

Integration owns policy and new ability metadata; Report keeps actual-time projection; Commercial
owns quick consumption, contract and coverage semantics; Ticket/Task retain source authority.
No ordinary Commercial identified API is automatically exposed to this workload. Tests must cover
both API routes and underlying queries. Existing external users may lose overbroad access after
context enforcement; fail-closed behavior needs explicit operator rollout and human review.
NexumMCP receives only the verified deployed contract/package delta. Package registration, report
catalog decisions and Paperclip tool selection stay in that project.

## Data And Migration Plan

Prefer no schema change: use existing persisted source relations and aliases. Do not backfill guessed
contract links. A new ability may require the normal permission/catalog deployment; freeze that in
its slice. Reverting export code must not broaden policy or destroy audit/source data. Reissue tokens
only through reviewed workload setup. No production change is authorized by this draft.

## Testing Plan

Before/after context-boundary regressions, exact day/range boundaries, >maximum_results, dense single
day, multiple pages, stable alias scope, unauthorized source records, missing scope, broad token,
expired approval, denied network and model, metadata audit, actual-vs-billing deduplication, direct
Commercial rows and orphan/ambiguous relationships. Generate OpenAPI and compare every route,
field, scope, error and package mapping. Verify authenticated HTTPS on the chosen deployed target
with existing approved settings; isolated test data alone is not that evidence.

## Documentation Plan

Report and Commercial Knowledge contracts, Integration setup caveat, TODO, ADR for cross-source
semantics, implementation slices, human review and NexumMCP handoff. No public product claim until
shipping and activation are verified.

## Open Questions

Resolved by Svein: implement the full scope on Dev. Production deployment/activation and the
manual review checklist remain separate; no further product decision blocks the implemented scope.

## Approval

Approved by Svein in this conversation on 2026-09-27: entire proposed scope, including
Commercial direct consumption, pseudonymous contract linkage and context enforcement.
Approval covers Dev implementation, not production activation or deployment.

## Implementation Slices And Frozen Contract

1. Context gate: Done On Dev. CoordinatorReadScope applies installation mode, nonempty selections,
   workload intersections and consistent source Work Context. Source modules currently have no
   granular record-view policy beyond domain permissions; worklog now requires report.view,
   ticket.view and task.view together instead of silently returning a partial source set. Stale
   source endpoints reuse the same context predicate. 15 governance tests / 171 assertions pass.
2. Commercial reads: Done On Dev. GET /api/v1/commercial/worklog/time-consumptions requires
   commercial.worklog.read plus report.view, commercial.view and commercial.timebank.view.
   GET /api/v1/commercial/worklog/contract-links requires commercial.worklog-links.read plus those
   permissions and ticket.view/task.view. Both are workload-bound pseudonymized coordinator reads.
   Quick rows include entry/client/technician aliases, work_date, minutes, source, fact_type and
   a validated nested contract projection. Link rows represent one Ticket billing-basis row with
   entry/record/client aliases, source, work_date, basis_minutes, stored billable flag, a validated
   direct contract projection and a separately labelled optional persisted allocation. Contract
   projections contain link_status, contract/item aliases, start/end dates and approval_status;
   no title/name/price/raw ID. Invalid references return explicit inconsistent/null linkage.
   Task billing deltas relate at Task record level, never one-to-one to actual Task entry minutes.
   Both use the unchanged per-window ceiling and same dates/paging metadata as worklog.
3. Contract delivery and reconciliation: Done On Dev. Generated OpenAPI, complete
   fixture manifest, Knowledge, human-review record, Dev HTTPS contract readback and NexumMCP delta.

Common slice constraints: existing source tables are read only; no migrations or background jobs.
External activation and production remain separate. Done requires route, policy, overflow, alias,
source-deduplication and generated-contract tests plus exact target readback. Human review is
HR-2026-09-27-WORKLOG. Every implementation remains under this one TODO workstream.

### Verified registration-basis clarification

Existing CompleteTask can persist TaskTimeEntry source_type=estimated. Such rows are registered
history and must not be silently dropped or described as measured work. The worklog projection
therefore adds registration_basis=recorded/estimated/unknown from existing source facts. An estimate
field alone is not a time entry. This is a disclosure of existing time provenance inside the approved
minimal time profile, not a payroll-approval flow or conversion of planning evidence into new time.

## Final Dev Verification

98 distinct tests / 855 latest assertions pass; final focused contract run 28 / 307. Generated
OpenAPI with all four worklog operations and 13 schemas matches a trusted HTTPS read from Dev.
The exact base commit and working-copy file hashes are in verification-manifest.json in the
NexumMCP handoff. No migration, commit, push, Main promotion or production activation occurred.
Live Dev remains closed (AI off, no coordinator workload) with zero source rows for the requested
period. Positive reads are real Laravel HTTP feature tests using isolated synthetic data on Dev;
actual authenticated production history is not verified. HR-2026-09-27-WORKLOG remains Pending.
