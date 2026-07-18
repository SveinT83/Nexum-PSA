# Feature Slice: Ticket-Origin Sales Quote And Planned Scope

Status: Done
Date: 2026-07-17
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Codex

## Goal

Reuse the Sales quote engine from Ticket with pre-approval planned scope and complete customer
acceptance evidence.

## User-Visible Behavior

Allowed technicians plan equipment/time, open the shared quote editor, send an immutable PDF and
link in a Ticket reply, and see acceptance, decline, expiry, or manual email acceptance.

## Scope

Planned scope lines, linked `ticket_service_quote` Opportunity, shared quote editor/service,
immutable PDF snapshot, Ticket reply delivery, shared acceptance service, win/loss state, UI, API,
events, and idempotency.

## Out Of Scope

The unrelated broader CPQ redesign and Sales-origin implementation Ticket creation.

## Data Touched

Ticket planned scope/context links, Sales Opportunity/Quote/Version/Activity, Ticket messages/events,
PDF files, queued mail, routes, views, and API.

## Permissions

Existing Sales quote/opportunity permissions plus Ticket planned-cost, send, manual acceptance, and
message permissions; workflow may only narrow them.

## Tests

Create/edit/send, shared calculations, snapshot immutability, PDF/link reply, retry, public/portal/
email acceptance, stale version, client scope, permissions, workflow re-evaluation, and API parity.

## Documentation

Ticket, Sales, email, customer approval, API, queue, and human review.

## Done Criteria

- [x] Ticket and Sales operate the same Quote and Opportunity.
- [x] Sent PDF and link identify the same immutable version.
- [x] Every acceptance method uses one Sales-owned service and audit contract.
- [x] Focused Ticket/Sales/Email tests pass on Dev.
