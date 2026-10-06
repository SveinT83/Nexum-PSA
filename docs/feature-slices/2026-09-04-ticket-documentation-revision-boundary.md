# Feature Slice: Ticket Documentation Revision Boundary

Status: Done On Dev
Date: 2026-09-04
Parent: `docs/rfc/2026-09-04-knowledge-revision-approval-publication.md`
Owner: Svein / Codex

## Goal

Turn Ticket documentation markers into durable independent follow-ups and allow exact scoped review
of AI-attributed revisions.

## User-Visible Behavior

Ticket closure does not close documentation work. The Ticket shows open, review, publishing, failed,
or completed state. An authorized Ticket technician may review only the linked AI revision without
receiving general Knowledge access.

## Scope

Documentation request model/action, Ticket event linkage/rightbar status, Documentation Agent draft
action, exact object authorization, and completion/read-back events.

## Out Of Scope

Automatic Ticket analysis or AI prose generation.

## Data Touched

`knowledge_documentation_requests`, `ticket_events`, and Knowledge revisions.

## Permissions

Ticket-scoped review requires current `ticket.view` and `ticket.update`; publication remains separate.

## Tests

Independent Ticket closure, system actor draft creation, exact scoped approval, cross-Ticket denial,
and completion only after read-back.

## Documentation

Ticket lifecycle and Knowledge workflow docs.

## Done Criteria

- [x] A Ticket documentation request has durable status independent of Ticket closure.
- [x] The linked AI revision carries exact Ticket, request, actor, and source provenance.
- [x] A technician with current Ticket view/update access can review that exact revision only.
- [x] The scoped exception grants no Knowledge index, API, other-revision, or publication access.
- [x] The request completes only after the exact publication read-back succeeds.
- [x] Focused Ticket and workflow regression tests pass on Dev.
