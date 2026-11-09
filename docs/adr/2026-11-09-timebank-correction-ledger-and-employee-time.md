# ADR: Timebank Correction Ledger And Preserved Employee Time

Status: Accepted
Date: 2026-11-09
Decision Makers: Svein Tore (product requirements and approved design), Codex (proposal)
Related RFC: ../rfc/2026-11-09-timebank-adjustments-and-charge-reversals.md

## Context

A timebank reset changes a commercial balance. A mistaken customer charge may need reversal even
though the employee performed the work. Existing source editing and guarded deletion do not
represent these distinct facts. Task actual time and Ticket customer-billing projections already
have separate meanings. Reusing draft deletion would unlock sources and may discard allocations.

The proposal must fit existing Commercial command receipts, EconomyMutex/source ownership,
official typed APIs and the planned #391 pooling direction. No pooled bank semantics are approved
by this ADR. The inspected baseline is authoritative Dev on 2026-11-09, not a new production audit.

## Decision

Propose an additive, immutable commercial correction ledger, not a replacement work ledger.

1. Commercial owns adjustment records and period/source effects, balance projection and correction
   orchestration. Ticket/Task own source authorization and canonical billing-projection identity.
   Economy owns all financial effects. Clients presents history/reporting.
2. An adjustment changes allowance available within one original contract-item period without
   rewriting contracted allowance, usage or employee time. Derive remaining/overuse from a signed
   balance; target writes subtract the signed balance rather than a clamped display.
3. A reversal references one canonical original customer charge, negates its still-effective
   commercial effects once and retains original source and financial snapshots. Covered/billable
   components reconcile without double-crediting. Task work and its billing projection share the
   same reversal identity. No negative employee work or change to Workday/payroll evidence.
4. Pending source regeneration and Task synchronization must recognize the reversal. Draft
   financial correction requires Economy authority and retained preimages. Finalized/exported/
   unknown financial states block apply until a verified owning correction is available.
   No implicit credit-note provider action or partially completed bank correction.
5. Existing EconomyMutex serializes competing financial/correction writers in one transaction
   with a documented consistent subordinate lock order. Integrate Commercial receipts without
   taking locks in an inverse order. Lock source, period and ledger; revalidate dependencies.
6. Native unique source reversal constraints and durable idempotency receipts enforce one
   reversal across all entry points. Reauthorize on replay. Monotonic ledger/dependency revisions
   prevent stale writes and offsetting-change fingerprint collisions.
7. UI and official domain API invoke the same actions and pure previews. New commands require
   actor/target/input/dependency-bound expiring preview, If-Match and Idempotency-Key.
   Independent persisted read-back distinguishes local correction from provider outcome.
8. Distinct adjustment/reversal permissions and abilities supplement current record/native
   rights; no automatic grants or provider/runtime enablement. MCP delegates to the official API.
9. Corrections store effective period/date separately from recorded-at time. Historical reports
   retain gross work and expose net customer effects; expired-period credits do not transfer.
   Backdated effects require explicit reconciliation, never retroactive rewriting of invoices.

Accepted by Svein Tore on 2026-11-09; implementation and human acceptance remain separate.

## Rationale

Separate commercial correction evidence preserves employee/payroll truth and audit history.
Append-only effects let reports explain both original and corrected customer charges.
A shared source identity prevents Task/Ticket double reversal and supports later pooled allocation.
The current database-backed Economy lock and durable receipts offer a known correctness boundary;
a new process lock, generic negative entry or UI-only action would undermine it.
Signed balance arithmetic handles overuse and zero targets without hiding debt.

## Consequences

Positive: traceable resets, explicit financial outcomes, operational UI/API parity, preserved
employee work, deterministic retries and future-compatible period/source allocations.

Costs: every competing allocator, source editor, Task reconciler, draft removal path and reporting
projection must become correction-aware. Additive migrations and native concurrency verification
are required. Global financial serialization may limit throughput; preserve correctness before
measuring/optimizing. Legacy ambiguous financial records may remain blocked for manual resolution.

Once corrections exist, an old application that ignores the ledger is not a safe balance reader.
Disable writes on rollback and retain correction/receipt evidence; prefer a compatible build.
Generic audit cleanup must not remove correction history or allow replay. New retention rules
must account for original sources and their financial identities.

## Alternatives Considered

- Zero/delete old time entries: loses work, payroll evidence and source dependencies.
- Edit original minutes or set a customer balance field: loses explanation and drift control.
- Register negative work: corrupts employee work totals and may bill a negative hour accidentally.
- Call CorrectClientTimeUsage: it edits source work, not just customer charge.
- Delete the draft line only: may delete allocation evidence and makes the source billable again.
- Independent UI/API corrections: diverging policy, race and read-back behavior.
- Build a new universal event-sourcing/payroll system: unnecessary domain expansion.
- Wait for #391 pooling: not required if per-source/period effects remain explicit and reusable.

## Follow-Up

RFC approved 2026-11-09; define Feature Slices with complete browser/API behavior per slice.
Inspect actual schema/constraints and all writer lock order before the first migration.
Register TODO and HR-2026-11-09-TIMEBANK-CORRECTIONS; implement native race tests, OpenAPI coverage,
source-preservation/report/export tests and handbook publication/read-back.
Dev implementation authorized. No Main promotion/deployment, commit/push, live correction or gateway publication authorized.


## Implementation evidence (2026-11-09)

Implemented on authoritative Dev. Restrictive source/customer/period foreign keys additionally
prevent parent cascades from deleting original work. Source ledger-sequence snapshots order
pending work and corrections within one date. Read-back redacts unauthorized financial record
references; replay rechecks conditional Economy write authority.
See ../plans/2026-11-09-timebank-corrections-verification.md and ../plans/2026-11-09-timebank-corrections-native-evidence.json.
Svein Tore accepted Dev delivery on 2026-11-09 and explicitly deferred BookStack. Human review is
Reviewed; publication/retrieval remains incomplete. No Main promotion, commit/push, production
deployment or live customer correction is implied by that approval.
