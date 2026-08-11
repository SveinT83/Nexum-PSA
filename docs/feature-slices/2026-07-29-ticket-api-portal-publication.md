# Feature Slice: Ticket API Portal Publication

Status: Done
Date: 2026-07-29
Completed: 2026-07-29
Parent: `docs/rfc/2026-07-29-ticket-api-customer-completion-flow.md`
Owner: Codex

## Goal

Expose the established one-way Customer Portal publication action to trusted API coordinators
without duplicating or racing the technician web flow.

## User-Visible Behavior

An authorized coordinator can publish an eligible client Ticket. The first call records visibility,
actor, event, and notification; repeated calls return the same published state without duplicates.

## Scope

- Shared `PublishTicketToCustomerPortal` action with a database row lock.
- Technician create/publish flow reuse.
- `tickets.portal.publish` ability and API route.
- API-only active same-client Ticket Contact and email validation.
- Additive Ticket resource visibility fields and links.

## Out Of Scope

Unpublishing, changing manual web eligibility, automatic API publication during create, or a generic
idempotency ledger.

## Data Touched

Existing Ticket visibility fields, Ticket events, Customer Portal notifications, API catalog,
routes, controller, resources, and tests. No schema change belongs to this slice.

## Permissions

Requires `tickets.portal.publish`, an active internal user, and `ticket.update` when that domain
permission exists.

## Tests

Feature tests cover first/repeated publication, one event/notification, one-way behavior, missing
ability, internal Tickets, and Contact scope. Existing portal and Ticket suites remain green.

## Documentation

Parent RFC, Ticket/Integration Knowledge, OpenAPI, TODO, and human review are updated.

## Done Criteria

- [x] Web and API use the shared lock-safe action.
- [x] Only the first call emits publication side effects.
- [x] API eligibility and both authorization layers are enforced.
- [x] Focused and regression tests pass on Dev.
