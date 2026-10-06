The Commercial worklog APIs provide direct timebank registrations and pseudonymous contract
linkage for the approved history-export workload. They complement Report's actual Ticket/Task
worklog; they do not replace it or expose the ordinary identified Commercial API.

## Operations and access

| Method/path | Workload and token ability | Actor permissions |
| --- | --- | --- |
| GET /api/v1/commercial/worklog/time-consumptions | commercial.worklog.read | report.view, commercial.view, commercial.timebank.view |
| GET /api/v1/commercial/worklog/contract-links | commercial.worklog-links.read | All above plus ticket.view, task.view |

Use a read-only Sanctum token bound to an approved coordinator_api workload, an approved recipient/
model route, fixed pseudonymized data profile and the installation/workload context intersection.
Wildcard/write tokens are denied. These abilities are explicitly marked read in ApiAbilityCatalog;
no permission migration, automatic grant or runtime activation is introduced.

Direct consumption is Client-owned. internal_only returns no direct consumption. selected_clients
requires explicit Client IDs. selected_work_contexts resolves only real client Work Context IDs,
then intersects any Client allowlist. Contract links filter their authoritative parent Ticket by
shared CoordinatorReadScope, and validate any Task relationship under the same scope. Current
source read authority is domain permissions; there is no additional granular source-view policy.

Both APIs accept date_from, date_to (inclusive YYYY-MM-DD work dates), page and per_page and return
`data` + `meta` using WorklogPageMeta. Defaults, result/window/page limits, true total, available_total,
returned_count, truncated, recovery and next_page are identical to the Report contract. A dense
capped single day remains incomplete until an explicitly reviewed finite policy change; no client
may auto-increase limits. There are no Client/contract/source query filters and no links.next.
Sort is descending work_date then entry_alias. Data can change between pages; no snapshot is promised.

## Direct consumption facts

Each ClientContractTimeConsumption with source quick_client becomes:

- entry_alias (commercial_consumption alias), client_alias and nullable technician_alias;
- fact_type=direct_timebank_consumption, source=quick_client;
- work_date and integer minutes;
- contract: the validated projection described below.

This is the deliberate no-ticket/no-task registration described by the approved 2026-06-08 quick
consumption RFC. Keep its total separate from actual Ticket/Task minutes. Do not treat overused
minutes as additional work or infer billable/payroll status; this API returns no billable flag.
Unknown/new source types are outside this version and require their own documented mapping.

## Contract and billing evidence

One TicketTimeEntry becomes one contract-links fact, including task_id billing projections:

- entry_alias (ticket_entry alias, matching Report only for direct Ticket entries);
- record_alias (Ticket alias for direct time, Task alias for consistent Task billing groups), or null;
- record_link_status=linked/inconsistent and nullable client_alias;
- fact_type=ticket_billing_basis;
- source=ticket/task_billing_projection, work_date, basis_minutes and the stored billable flag;
- contract: validated direct source contract projection;
- allocation: null when not allocated, otherwise persisted allocation evidence.

Task billing may round actual time or apply a minimum. Join a projection to the Task's record_alias
only; multiple actual Task entries can underlie one billing delta. Do not distribute that delta
across entries or add basis_minutes to actual minutes. Standalone Tasks may have no contract link.
Direct Ticket rows can join exactly by entry_alias. Different source facts have different alias
types even when raw database IDs happen to match.

An allocation contains link_status, nullable allocation_alias, status, covered_minutes,
billable_minutes and a separately validated contract projection. Allocation Client/Ticket
inconsistency returns null quantities/aliases. Allocation contract evidence may differ from the
entry's direct contract; preserve both, do not silently overwrite either. Coverage is accounting
distribution of billing basis, not extra actual time. Null allocation means no persisted allocation
was found, not zero billable/covered minutes.

## Safe contract projection

Every non-null projection has link_status=linked/unlinked/inconsistent, nullable contract_alias,
contract_item_alias, start_date, end_date and approval_status. A contract must belong to the fact's
already-authorized Client and an item must belong to that contract. Broken/missing/cross-client
references return inconsistent and null data; absent references return unlinked. A soft-deleted
contract is not reconstructed. No contract title, customer name, description, price, note, token,
raw ID, customer document or alias reversal is returned.

Client and contract aliases are comparable only under the same installation key and workload.
Ordinary Commercial client_id/contract_id cannot be matched to aliases. Contract metadata is the
current live row, not the contract's historic state or a verified historical customer document.

## Reconciliation and errors

For 2025-09-01 through 2026-08-31, use disjoint permitted date windows and the complete extraction
algorithm in Report Knowledge for each fact stream. Preserve separate counts/minute totals for
actual time, direct consumption, billing basis and allocations. Only the first two are independently
registered time facts, and should still be reported separately so their semantics stay explicit.
Record missing/inconsistent contract links as coverage gaps, not guessed associations.

401, 403, 422 and 429 follow the Report coordinator contract. Scope/expiry/network/provider/context
failure is not a reason to switch to an ordinary identified token. Authenticated middleware reads
and denials write metadata-only audit. The initial Dev policy remains disabled.

## Verification and deployment

Approved RFC: docs/rfc/2026-09-27-controlled-history-and-commercial-time-export.md.
ADR: docs/adr/2026-09-27-time-facts-and-pseudonymous-contract-links.md.
Tests exercise actual 45 minutes, direct 20 minutes and separate 70-minute billing basis; matching
opaque joins, excluded billing duplicates, invalid foreign contract references, scope denial and
result overflow/partition recovery. See the delivery manifest for final counts and file hashes.

No schema, seed, scheduler, queue, asset build or production setting change is required. Promote
reviewed source, generate OpenAPI and refresh normal app caches/opcache. Source Knowledge is ready
for BookStack sync. HR-2026-09-27-WORKLOG remains a manual review gate before production release.
