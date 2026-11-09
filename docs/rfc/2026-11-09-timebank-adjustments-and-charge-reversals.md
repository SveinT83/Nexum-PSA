# RFC: Customer Timebank Adjustments And Charge Reversals

Status: Approved
Date: 2026-11-09
Owner: Codex; product approver and intended human reviewer: Svein Tore
Change level: Level 3 - financial corrections, permissions, data model and operational API
Implementation: Done On Dev, enabled and accepted by Svein Tore, 2026-11-09; BookStack explicitly deferred.
Related ADR: ../adr/2026-11-09-timebank-correction-ledger-and-employee-time.md
Human review: HR-2026-11-09-TIMEBANK-CORRECTIONS (Reviewed by Svein Tore, 2026-11-09).

## Context

Svein needs occasional customer timebank resets without losing time history, plus the ability
to open a mistaken time entry and reverse its customer charge. In conversation he confirmed:
flexible balance targets, retained original records, and employee time must remain intact.
These are agreed product requirements, not approval of an unseen implementation design.

This improves existing Commercial/Ticket/Task/Economy capabilities. It does not replace them
with a new time-registration system. This document describes the complete target, including
browser and operational API parity, before implementation slices are chosen.

## Goals

- Restore the agreed time allowance, set remaining balance to zero, or set a custom balance.
- Reverse one mistaken customer charge from its entry, retaining original and linked evidence.
- Preserve employee work minutes, dates, ownership, payroll/Workday evidence and time history.
- Keep actual work, customer-chargeable time, timebank coverage and financial corrections distinct.
- Share calculations, policies, previews, mutations and persisted read-back between UI and API.
- Preserve financial snapshots and prevent repeated deductions, reversals and regeneration.
- Keep future pooling compatible without implementing or deciding #391's eligibility rules here.

## Non-Goals

Automatic resets, bulk customer resets/reversals, payroll corrections, deletion of original time,
automatic rebooking to another customer, changing contractual allowances/prices, automatic credits,
invoice-provider writes, live customer corrections, Main merge/deployment or runtime activation.
Changing an agreement from 5 to 10 annual hours is #390, not a discretionary reset.

## Current Behavior

Pre-implementation baseline (retained for design context): read-only inspection of authoritative Dev /var/Projects/tdPSA on branch Dev, 2026-11-09:

- Commercial/Actions/CalculateClientTimebankBalances sums allocated covered + billable Ticket
  minutes, pending unallocated Ticket minutes and direct Commercial quick-consumption minutes.
  It derives allowance from service minutes and contract item quantity. Remaining and overuse
  are separate nonnegative projections. Monthly, contract-anniversary annual and one-time
  periods exist; there is no adjustment/reversal projection in this inspected action.
- CommercialTimebankController exposes context/balances, exact quick-consumption read-back and
  registration. New registration already requires a balance revision via If-Match and uses
  the Commercial command mechanism. TimebankRevision currently includes contract, period,
  allowance, use and policy, but no correction ledger.
- Clients/Actions/CorrectClientTimeUsage edits existing quick/Ticket/direct Client Task records.
  It rejects ordered sources and linked Ticket-owned Task edits. It is an edit operation,
  not the append-only customer-charge reversal proposed here; do not call it to reverse work.
- Economy/Actions/GenerateOrders owns allocation and quick overuse draft lines.
  DeleteOrderLine permits draft removal and unlocks sources for regeneration; it may delete
  Ticket allocation rows. That behavior alone cannot preserve reversal evidence or prevent rebilling.
- Existing guarded Ticket deletion is restricted to dependency-free manual pending entries.
  It cannot implement customer-charge reversal of retained employee work.
- ClientTimeReportQuery/report RFC distinguishes registered Task work from linked Ticket billing
  projections and retains ordered history. Both report API and binary exports need correction fields.
- The Dev tree has extensive unrelated changes; no baseline cleanup or broad staging is authorized.

Related active GitHub issues inspected: #390 legacy agreement amendments and #391 pooling/rates.
#391 is open with no comments at inspection. Neither provides an approved correction design.
This is a bounded requested planning task, not a replacement implementation of those issues.
Existing report/rollout human-review entries remain open and independent.

## Proposed Change

### 1. Adjust a customer timebank

From Client > Contracts, an authorized operator selects one exact contract item and period,
then chooses "Restore allowance", "Set remaining to zero" or "Set remaining balance".
Require a reason and effective date within the selected period, no later than today in the
installation timezone. Show original allowance, signed balance, remaining/overused minutes,
requested target, signed adjustment and all financial blockers before confirmation.

Use integer minutes. Custom remaining target is nonnegative; restoring uses the authoritative
allowance visible in the preview, without changing the contract or renewing its period.

For each period and effective cutoff:
signed balance = original allowance + effective adjustment minutes - net customer usage minutes.
remaining = max(0, signed balance); overused = max(0, -signed balance).
target adjustment = requested remaining target - signed balance.
Never subtract the clamped remaining value: at -120 minutes, setting remaining to zero needs +120,
not zero. At allowance 600 and usage 480, restoring to 600 adds 480. The 480 work minutes remain.

Adjustments do not count as worked time, new sales, invoice credits or contract amendments.
A reset does not retrospectively cancel earlier financial overuse. Pending unallocated charges
are evaluated chronologically using the correction's effective cutoff; they cannot consume a
later adjustment for earlier work. Dates within a day use a stored deterministic ordering.
Backdated adjustments preview affected unfinalized allocations and require atomic audited
reconciliation; finalized/unknown financial dependencies block apply until reconciled.
Closed/expired historical periods retain their identity and cannot transfer credit to the current
period. No implicit carryover or renewal. Future pooling must adjust named eligible contributions,
never a guessed customer-wide pool.

### 2. Reverse a mistaken customer charge

From Client time usage/report details and source Ticket/Task entry details, expose
"Reverse customer charge" for a supported customer billing source. Discover eligibility and
blockers even when apply is unavailable. Source types are direct quick consumption, direct
Ticket time and the customer billing projection of Task time.

Full-entry reversal is the supported action. Partial corrections use a separately reviewed
follow-up flow, not a caller-supplied arbitrary negative number. Never reverse Task work and its
Ticket billing projection twice. A Task without a customer billing projection has no charge to
reverse; return a clear ineligible reason while preserving that employee work.

- Keep original minutes, date, employee, rates, notes, source references and retained revisions.
- Append one reversal with actor, reason, recorded timestamp, original work period, customer,
  source identity and exact customer effects. Display a derived "Reversed" customer-charge badge,
  not a false claim that employee work was reversed.
- Release precisely the still-effective original customer usage, including its covered/overuse
  split. Never add covered minutes and full source minutes as two separate credits.
- Pending charges are suppressed from later allocation/billing. Existing allocation evidence gets
  a linked countereffect; retain originals. Do not allocate the original again after draft deletion,
  source editing, Task billing synchronization, Ticket movement/merge or Generate orders.
- Reversing a billable-only entry cancels pending billing but creates no timebank credit.
- Default effective period is the source's original period, with a new correction recorded now.
  Historical reports can show original/effective dates and recorded-at audit separately.
- A second distinct reversal is rejected. A retry of the same command returns its existing receipt.
- A mistaken reversal is not edited/deleted. Any later reinstatement requires a separate approved,
  linked corrective action; no generic undo endpoint is implied by this RFC.

Employee time and payroll remain unchanged for every source, including direct Ticket and quick
records counted by existing reports. No negative employee work entry is generated.

### 3. Financial states and reconciliation

Preview classifies unallocated, covered allocation, draft order, finalized/exported and unknown.
For unallocated or covered-only sources, apply can complete a linked local correction.
For draft lines, require Economy correction authority as well; preserve pre-correction line and
allocation evidence, correct draft totals atomically and suppress source regeneration. Existing
DeleteOrderLine must not be reused unmodified if it deletes the only financial evidence.

Finalized/exported/invoiced or uncertain provider state returns financial_correction_required.
Keep original charge and bank unchanged until an authorized Economy correction is verified.
Do not show "Reversed" for an incomplete request or claim that a customer refund was issued.
Preview returns an authorized reference/next step to Economy; if no supported provider correction
exists, report that blocker explicitly. This RFC does not invent or activate credit-note APIs.
No partial bank success plus silently failed financial cancellation.

### 4. Browser, reporting and documentation behavior

Use existing Bootstrap/shared components, English labels and responsive layouts. Confirmation shows
before/after, reason and the explicit statement "Employee work time will remain unchanged".
History lists original charges, adjustments and linked reversals; show gross registered work,
net customer usage, covered minutes, pending billable minutes and financial status separately.
A reset never erases genuine work or changes report registered-work totals.
Report summary/items/entries, XLSX and PDF must agree and avoid counting linked Task projections twice.
Historical as-of calculations exclude corrections not yet effective; audit views additionally
support recorded-at chronology. Read archived periods without reopening source objects.

### 5. Domain ownership and data

Commercial owns adjustment ledger, effective period balances and correction orchestration.
Ticket owns direct Ticket charge eligibility; Task owns projection identity and the invariant that
actual Task work persists. Economy owns financial transitions and serialization; Clients owns the
existing customer workspace/report projection. Integration owns API catalog/tool discovery.
All new routes belong in the owning app/Modules/{Domain}/routes.php, with controllers/views there.

Proposed additive Commercial correction tables: command/header plus per-source/period effects,
with distinct adjustment/reversal kinds. Store immutable customer/contract/item/period identities,
original-source morph identity, before/after and signed minute effects, covered/billable snapshots,
reason, actor, effective date, recorded timestamp, financial evidence references and command key.
Do not invent negative original time rows. A database unique reversal identity spans all entry
points and aliases (Task projection resolves to its canonical billing source).
Foreign keys/retention must not cascade away corrections or originals. Receipts survive audit cleanup.

Serialize correction, consumption, pending edits, Task projection writes, draft removal and generation
through the existing EconomyMutex with a documented common lock order before subordinate rows.
Re-read source/contract/period and actor rights in one transaction. Reuse domain command receipts;
do not introduce an independent lock/replay scheme that conflicts with existing Commercial commands.
Revision includes ledger sequence and dependency versions so offsetting edits cannot hide staleness.

### 6. Official API and MCP parity

The following are proposed paths, not claims of deployed endpoints. Prefix all with /api/v1.
Let C = /commercial/clients/{client}/timebank.

| Method/path | Purpose | Proposed token ability |
| --- | --- | --- |
| GET C (existing) | Period balances, revision, eligible actions and links | commercial.timebank.read |
| GET C/history | Paginated charges/corrections, date/source/period filters | commercial.timebank.read |
| POST C/adjustment-preview | Pure target calculation and blockers | commercial.timebank.adjust |
| POST C/adjustments | Confirm exact adjustment | commercial.timebank.adjust |
| POST C/reversal-preview | Pure canonical source/financial impact check | commercial.timebank.reverse |
| POST C/reversals | Reverse one exact customer charge | commercial.timebank.reverse |
| GET C/corrections/{correction} | Exact persisted correction and authorized effects | commercial.timebank.read |
| GET C/operations/{operation} | Actor-authorized command result/recovery read-back | commercial.timebank.read |

Existing exact consumption read-back and report endpoints remain supported and gain additive status/
correction references. Previews accept typed mode/target/effective_date/contract_item_id/period or
source_type/source_id, plus reason; no generic model update or negative-minute write endpoint.
Require source_type enums, strict integer minutes, explicit period ownership, bounded reason,
and stable pagination with full filter totals. A preview POST makes no database/business writes.
Return a signed actor/target/input/dependency-bound preview token with ten-minute expiry.

Mutations require Idempotency-Key, If-Match and preview_token; reauthorize before execution/replay.
Input must match the preview. Stable schema returns correction_id, operation_id, source identity,
before/after/net effects, preserved_employee_time=true and explicit financial outcome.
Read-back must show persisted truth and must not imply provider credit completion.
Same actor/key/canonical input returns the original result; different input returns 409.
Missing precondition 428; stale/expired/duplicate/financial conflict 409 with typed blocker;
invalid input 422; unauthorized 403; wrong parent/invisible source 404; unauthenticated 401.

Token abilities supplement current active-user/record rights. Propose distinct native
commercial.timebank.adjust and commercial.timebank.reverse permissions, never granted automatically.
Require client.view and commercial.timebank.view plus source Ticket/Task visibility, private Task
policy and financial-owner checks. Correcting another employee's customer charge needs explicit
reverse authority; own-entry edit rights are insufficient. Require Economy's current mutation
authority for draft correction. Receipt reads recheck current source privacy even after reversal.

Every browser surface/action must map to typed OpenAPI operations, ability/native policy, shared
action, contract schemas, tests and exact read-back in a coverage matrix. No UI-only admin bypass.
Update Swagger, Integration ability/catalog descriptors and native API tests in the same slice.
MCP/gateway must consume the official API, preserve headers/validation/receipts and publish the
supported reads AND actions. Tool publication, fresh-chat discovery and employee acceptance are
separate from Dev implementation; do not enable flags or widen rights to demonstrate a tool.

## Impact Analysis

Commercial balances/revisions/consumption; Ticket and Task billing projections; Economy allocation,
draft totals, source locks and regeneration; Clients time workspace/report/exports; Integration
catalog/OpenAPI/MCP; current ACLs and audit retention are affected. Workday/Worklog/payroll are
regression boundaries only: no revisions, approval or provider-time writes are permitted.
#391 must consume the same per-source ledger when implemented, preserving source periods.
#390 agreement amendments remain distinct. No additional queue, scheduler or provider calls are
needed for local corrections. Existing historical coverage and financial read models need one
shared correction-aware projection; do not patch only the balance card.

## Data And Migration Plan

Additive tables/indexes/permissions and schema-backed unique reversal keys. No destructive backfill,
mass zeroing, original-minute edits, recalculation of invoiced history or automatic grants.
Existing records have zero correction effects. Unknown legacy ownership/economics blocks apply;
do not reconstruct evidence from mutable catalogue prices.
Before implementation, inspect exact migration types/constraints and existing command transaction
order; record them in the slice rather than guessing SQL in this planning document.
Deploy migration before correction-aware readers/writers; refresh normal caches/Swagger/assets
only as required by the implemented changes. Feature exposure stays off until all competing
writers and report paths use the ledger and native concurrency tests pass.
Rollback disables mutations and preserves schema/receipts; old readers that ignore posted
corrections cannot safely serve balances. Roll forward or use a compatible read-only build.
Dev migration/exposure evidence and exact future deployment commands are recorded in
../plans/2026-11-09-timebank-corrections-verification.md; no production deployment.

## Testing Plan

Dev feature/API tests must cover:
- All targets, including negative signed balances, zero allowance, overuse, repeated legitimate
  resets, custom minutes, period/date/leap-year boundaries and backdated pending entries.
- Quick, direct Ticket and Task billing projection reversal, aliases, private Tasks and another
  employee's charge; unchanged original work/payroll evidence, rate snapshots and source links.
- Pending, covered, partially covered, billable-only, draft, finalized/exported and unknown states.
- Double reversal, same/different-key retries, stale/expired previews, revoked rights on replay,
  source editing/movement/merge, regeneration and Task projection synchronization.
- Native MySQL two-writer races against consumption, generation, draft deletion and correction;
  transaction rollback leaves no partial ledger, receipt, bank or financial change.
- Browser/API equivalence, strict errors/scopes/OpenAPI response validation and coverage inventory.
- Gross work/net usage/as-of history and XLSX/PDF totals, pagination and Task no-double-counting.
- Zero ledger effects preserve legacy results; migrations/rollback retain evidence and ACLs.

Run relevant Commercial, Clients, Ticket, Task, Economy, Workday/Worklog and Integration suites on
Dev. Concrete classes and measured results belong to implementation evidence; none are claimed now.
Human checklist HR-2026-11-09-TIMEBANK-CORRECTIONS was accepted by Svein Tore on 2026-11-09; BookStack is explicitly deferred.

## Documentation Plan

Update domain docs, API usage/OpenAPI, authoritative Knowledge source, TODO and human-review entry
with implementation status. Publish/read back employee BookStack instructions with exact English
menus/buttons, reason/preview steps, financial blockers and employee-time guarantee before calling
the implemented workflow complete. Verify existing access without broadening it; employee assistant
retrieval is separate. Do not publish draft-only controls as available employee instructions.
At implementation handoff, record public-safe website notes using the documented current-profile
path resolution and mark do not publish until production acceptance.

## Open Questions

No additional product decision blocks review of this draft. Conservative defaults are:
full-source reversal, nonnegative custom remaining balance, no future-dated/scheduled writes,
no automatic invoice correction or cross-period transfers. Approval of this RFC would approve
these defaults. Any request for partial reversals, reinstatement or automatic credits requires
explicit scope review before implementation.

## Approval

Svein Tore authorized preparation of RFC and ADR with API on 2026-11-09:
"ok. Da kjører vi på med RFC og ADR og ikke glem API".
Svein Tore approved this concrete RFC and ADR and authorized Dev implementation on 2026-11-09:
"Takk. Kjør på".
No commit/push, Main promotion, production deployment, live correction or gateway publication
is implied. Human acceptance remains Pending.

## Delivered implementation acceptance

Svein Tore explicitly accepted the delivered Dev implementation on 2026-11-09: "Godkjenner. BookStack får vente".
This accepts Dev delivery and defers BookStack; it does not claim individual scenario execution,
completed handbook publication, Main promotion or production/gateway rollout authorization.
