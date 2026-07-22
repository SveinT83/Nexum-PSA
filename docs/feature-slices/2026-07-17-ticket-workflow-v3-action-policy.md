# Feature Slice: Ticket Workflow v3 Action Policy And Enforcement

Status: Done
Date: 2026-07-17
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Codex

## Goal

Give every state one server-enforced action policy and expose the same decision to Ticket view and
API clients.

## User-Visible Behavior

Technicians see only relevant actions; blocked actions explain all missing requirements and link to
safe corrective actions.

## Scope

Action registry, inherited/hidden/blocked/available/conditional policy, decision resources,
controller/action middleware integration, transition history, Ticket view and API parity.

## Out Of Scope

New cross-domain action implementations supplied by later slices.

## Data Touched

Published state action definitions, Ticket events/history, Ticket controllers/actions/views/API,
and permission seeding.

## Permissions

All existing action permissions remain mandatory; workflow only narrows them.

## Tests

Direct route/API/bulk denial, UI decisions, missing permissions, stale decision re-evaluation,
concurrency, audit, and backward compatibility.

## Documentation

Ticket lifecycle, technician operations, API decisions, permissions, and human review.

## Done Criteria

- [x] View and API return the same action decisions.
- [x] Every Ticket mutation in scope is guarded server-side.
- [x] Workflow never grants missing permissions.
- [x] Focused tests pass on Dev.
