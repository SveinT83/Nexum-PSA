# ADR: Explicit record actors and a domain-change timestamp

Status: Accepted
Date: 2026-11-12
Decision Makers: Svein Tore (approved RFC), Codex (technical implementation)
RFC: ../rfc/2026-11-12-ticket-task-actor-attribution.md

## Context

Ticket already stores both actor IDs. Task stores the creator only. Existing
updated_at is touched by read state, source activity and technical maintenance.
It cannot reliably be displayed as the last person's edit time.

## Decision

Reuse creator IDs and Ticket updater IDs. Add nullable tasks.updated_by and a
nullable actor_updated_at on both models. Match existing actor columns (indexed
unsigned IDs, configurable user table); do not introduce a hardcoded user-table
foreign key. Missing/deleted relations remain unknown, with IDs preserved.

Use one UserManagement-owned model concern for lifecycle semantics. Ordinary
updates cannot replace created_by. Explicit updated_by assignments are tracked
even when the ID is unchanged. Meaningful model changes stamp the explicit actor;
an unattributed write clears the updater instead of inheriting a previous person.
Never infer the updater from Auth, assignment, sender or another request.
A change to owned tags/custom fields/checklist uses recordRelatedMutation only
after a verified actual change, inside its database transaction.

Technical touches and is_unread-only changes retain prior attribution. Comments,
time entries and source activity keep their own authors; only parent domain
changes affect the parent attribution. actor_updated_at is separate from the
existing general updated_at. No-op actor-only writes preserve old evidence.

Both UI/API use the same stored evidence. References contain only id/name.
Batch-load collection references and preserve record authorization. Do not add
actor relationships to global model serialization or customer Portal projections.

## Rationale

This avoids false historical identities and accidental logged-in-user attribution
in workers. Existing explicit domain actions remain the authority. No-op behavior
is consistent for ordinary form/API edits; unchanged checklist text preserves its
checked state rather than being destructively recreated.

## Consequences

One additive migration must precede code activation. Older rows stay unchanged,
including null timestamps; there is no guessed backfill. New SQL/bulk domain writes
must explicitly stamp the same fields or route through domain actions; raw query
updates bypass Eloquent hooks. Application records with missing IDs are unknown.
No scheduler, queue, provider, scope or permission activation is introduced.

## Alternatives Considered

Reusing updated_at would attach technical touches to a person. Global Auth-based
observers would misattribute automation and service calls. Guessing from event
history or assignee would fabricate certainty. A new audit-history system exceeds
the approved compact attribution requirement.

## Follow-Up

Manual checklist HR-2026-11-12-TICKET-TASK-ATTRIBUTION remains Pending before
Main/production release. Maintain mutation inventory and API/handbook evidence
in plans/2026-11-12-ticket-task-attribution-verification.md.
