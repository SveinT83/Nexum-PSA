# Feature Slice: Ticket API Idempotent Customer Reply

Status: Done
Date: 2026-07-29
Completed: 2026-07-29
Parent: `docs/rfc/2026-07-29-ticket-api-customer-completion-flow.md`
ADR: `docs/adr/2026-07-29-ticket-api-message-idempotency.md`
Owner: Codex

## Goal

Create a real outbound public technician reply through the API exactly once across safe retries.

## User-Visible Behavior

The customer receives the normal Ticket reply, and an identical API retry returns the original
message without another email, portal notification, event, relationship sync, or workflow trigger.

## Scope

- Nullable Ticket message idempotency key/fingerprint and Ticket-scoped unique index.
- `StoreIdempotentTicketCustomerReply` wrapper around `AddTicketMessage`.
- `tickets.reply_customer` ability, customer-reply-only route, and message resource.
- Active same-client Contact/email, Published state, action guard, CC, and intent validation.
- HTTP 201 first result, 200 matching replay, and 409 conflict/deleted reservation behavior.

## Out Of Scope

Internal notes, attachments, editing/deleting API messages, inbound external-message behavior, or a
cross-domain idempotency service.

## Data Touched

`ticket_messages`, the additive migration, Ticket message model/action, API route/controller,
ability catalog, resources, queue side effects, and tests.

## Permissions

Requires `tickets.reply_customer`, an active internal user, and `ticket.reply_customer` when the
domain permission exists. Current workflow action policy remains authoritative for new requests.

## Tests

Feature tests cover one message/job, same replay, changed payload, cross-client Contact, hidden
Ticket, missing ability, soft deletion, solution metadata, and external-message isolation regression.

## Documentation

Accepted ADR, parent RFC, Ticket email/technical Knowledge, Integration API Management, OpenAPI,
deploy notes, TODO, and human review are updated.

## Done Criteria

- [x] Database uniqueness is the final concurrency guard.
- [x] `AddTicketMessage` remains the only outbound side-effect owner.
- [x] Matching retries never rerun side effects.
- [x] Migration is applied and focused/regression tests pass on Dev.
