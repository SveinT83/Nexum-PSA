# ADR: Time Facts And Pseudonymous Contract Links

Status: Accepted
Date: 2026-09-27
Decision Makers: Svein (scope approval), Codex (implementation)

## Context

The approved historical-export RFC needs actual time, direct Commercial timebank consumption and
contract evidence without adding billing projections to technician work or exposing ordinary IDs.

## Decision

Keep Report worklog as actual Ticket/Task time. Commercial owns separate direct consumption and
Ticket billing-basis/contract-allocation reads. Reuse workload-scoped alias types so explicitly
permitted facts can join. Never expose an alias reversal endpoint. Validate contract/item/client
relationships before serializing aliases, retain invalid/missing linkage explicitly, and never
allocate a cumulative Task billing delta to individual actual entries. Context enforcement is
shared in Integration; domain permissions still apply. maximum_results remains a window ceiling.

## Rationale

Existing modules and persisted references define authority. Separate fact types let consumers
reconcile billing and actual work without counting the same work twice. Existing policy limits
retain their meaning and consumers can detect every incomplete window.

## Consequences

Clients need multiple narrowly scoped reads and a completeness manifest. A dense capped day needs
an explicit policy decision; no frozen snapshot is promised. Source permission/context changes can
exclude historical rows. Contract state is current metadata, not a historical customer document.

## Alternatives Considered

Flattening all minutes into one worklog would confuse billing projections with work. Returning
raw IDs or inferring contract ownership from a client/name would defeat the chosen data profile.
Removing the result ceiling or exposing a generic report runner would change unrelated controls.

## Follow-Up

Verify generated contracts and the exact deployment; keep HR-2026-09-27-WORKLOG pending until Svein
checks the UI setup, test workload reads, cross-customer denial and manifest reconciliation.
