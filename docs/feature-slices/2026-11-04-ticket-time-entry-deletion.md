# Feature Slice: Guarded Ticket Time Deletion

Status: Done On Dev / Main Promotion Authorized
Date: 2026-11-04
Parent: ../rfc/2026-11-04-ticket-time-entry-deletion-api.md (Approved)
Owner: Codex; review/promotion owner Svein Tore
Human review: HR-2026-11-04-TIME-DELETE Pending; production gate, scoped merge explicitly authorized.

## Goal

Remove an erroneous pending direct manual Ticket time entry through a typed API,
with an actor-bound preview, exact retry semantics and retained audit read-back.

## User-Visible Behavior

Authorized employees may correct another technician's eligible entry. Financial,
Task and retained Workday dependencies prevent deletion. No new browser control.

## Scope

Three documented Ticket API operations, one Ticket action, dedicated catalog scope,
retained deletion event/receipt, focused tests and handbook instructions.

## Out Of Scope

Production/gateway deployment, real-case cleanup, new grants, financial reversals,
Workday mutation, repeated-create prevention and unrelated dirty Dev work.

## Data Touched

Eligible ticket_time_entries row, ticket_events deletion record and existing Economy
write mutex revision. Read-only references in Economy, Workday and current actor policy.
No schema migration, background job, scheduler or asset build.

## Permissions

tickets.time.delete plus current ticket.view, ticket.register_time, ticket.update;
active identity, existing Ticket/context and REGISTER_TIME workflow checks.

## Tests

Allowed other-technician deletion, original preservation, independent GET receipt,
permissions, nested IDs, exact/conflicting replay, preview expiry/change, processing/
Task/financial/retained Workday blockers and transactional audit failure.
Focused ledger, Ticket/Task schema/inventory, Report, Economy and Workday regressions.
Real-database race checks use only identified synthetic fixtures and verified cleanup.

## Documentation

Ticket Time Registration, API Management, RFC/ADR, coverage matrix, TODO and human
review; exact-source BookStack content/audience read-back and public-safe website handoff.

## Done Criteria

- [x] Focused Dev tests and schema inventory pass.
- [x] Native isolated concurrency boundaries verified; configured Dev database remains unavailable.
- [x] Scoped latest-main candidate independently verified and authorized for merge; actual merge is read back in the handoff.
- [x] Exact BookStack sections and existing reading audience inspected/read back; employee OpenWebUI retrieval remains a human check.
- [x] Human review remains Pending for actual manual acceptance before production.
