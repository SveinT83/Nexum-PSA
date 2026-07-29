# Feature Slice: Ticket API Solution Completion

Status: Done
Date: 2026-07-29
Completed: 2026-07-29
Parent: `docs/rfc/2026-07-29-ticket-api-customer-completion-flow.md`
Owner: Codex

## Goal

Verify and document the complete API coordinator path from publication and solution reply through
workflow decisions, Resolved, and Close.

## User-Visible Behavior

A `send_solution` customer reply records the normal technician response and solution facts. The
coordinator then uses existing guarded decision, transition, and close endpoints without bypasses.

## Scope

- End-to-end feature test for publish/reply/decisions/Resolved/Close.
- Negative authorization, scope, idempotency, and inbound external-message coverage.
- OpenAPI regeneration, Knowledge updates, deployment verification, and human review record.

## Out Of Scope

Automatic close, new workflow transitions, new email infrastructure, production messages, frontend
UI, or changing pinned workflow semantics.

## Data Touched

Workflow history, Ticket status/lifecycle fields, Ticket messages/events, generated OpenAPI,
Knowledge sources, RFC/TODO/index files, and human-review tracking.

## Permissions

The coordinator needs the two new abilities plus `tickets.workflow.read` and `tickets.actions` for
the existing decisions, transition, and close steps. Domain permissions and guards still apply.

## Tests

The new suite passes with 4 tests and 53 assertions. TicketModuleTest, PortalTicketTest, and
TicketWorkflowV3Test pass with 146 tests and 1,156 assertions. The complete Dev Laravel suite
passes with 915 tests and 7,014 assertions.

## Documentation

OpenAPI was regenerated. Ticket and Integration Knowledge sources, RFC/ADR, TODO, Feature Slice
index, website handoff, deploy commands, and `HR-2026-07-29-002` are updated.

## Done Criteria

- [x] `send_solution` exposes an allowed Resolved transition.
- [x] Existing transition and close actions complete the Ticket.
- [x] OpenAPI generation and affected regression suites pass.
- [x] Deployment and manual review requirements are recorded.
