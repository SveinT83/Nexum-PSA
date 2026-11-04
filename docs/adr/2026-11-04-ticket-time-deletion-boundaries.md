# ADR: Ticket Time Deletion Uses Existing Financial And Workday Boundaries

Status: Accepted
Date: 2026-11-04
Decision Makers: Svein Tore (approved parent RFC), Codex (implementation details)

## Context

An erroneous pending direct time entry can be removed without creating a financial
reversal, but the same row may be consumed by billing or selected as Workday evidence.
A blind cascade would destroy allocations; a source-only lock does not coordinate
with existing writers. Reliable retries must survive source removal.

## Decision

Reuse EconomyMutex and lock relevant user rows in ID order, then the exact time
entry and Ticket. Workday already serializes the worker before source and Ticket.
Billing serializes through EconomyMutex. Read initial source ownership before the
transaction and use current locking dependency reads to avoid a stale repeatable-read
snapshot after waiting for Workday. Recheck eligibility inside the transaction.

Store the deleted row snapshot, actor, reason and hashed request identity in a
TicketEvent in the same transaction as deletion. Serialize receipt lookup/creation
through the existing actor row and Economy mutex. Return a typed receipt from a
dedicated GET; never expose internal hashes. No new table or uniqueness migration.

Block all retained Workday references, including indexed and pre-index snapshot
history. Never rewrite them or rely on only the current revision. Block every
financial allocation/order reference regardless of a stale pending status.

## Rationale

This preserves the owning domains and existing serialization without a second
financial ledger, new employee-time authority or generalized mutation platform.
A preview identifies reviewed data; current transactional checks remain authoritative.

## Consequences

Only unprocessed manual direct time qualifies. Actor/request replay is retained with
Ticket audit under existing retention; it is not a claim of immutable storage.
Historical snapshot fallback has a read cost. Deletion cannot be undone by code
rollback; restoration would require a separate reviewed application procedure.
Receipt access rechecks current permissions and Ticket workflow, including on replay.

## Alternatives Considered

Hard deletion without audit loses evidence. Soft deletion adds global filtering and
migration obligations across reports and billing. A general financial reversal is
a separate workflow. A new receipt table is unnecessary with existing shared locks.

## Follow-Up

HR-2026-11-04-TIME-DELETE remains Pending for manual acceptance before production;
production/gateway exposure and actual duplicate cleanup remain separately scoped.
