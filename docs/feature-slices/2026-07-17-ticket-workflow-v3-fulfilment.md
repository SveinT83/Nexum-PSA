# Feature Slice: Ticket Workflow v3 Approved Fulfilment And Implementation

Status: Done
Date: 2026-07-17
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Codex

## Goal

Convert accepted planned scope into controlled Storage/implementation work and actual Economy
completion without premature reservation or billing.

## User-Visible Behavior

Accepted scope unlocks reserve/pick/purchase-need actions and implementation. Resolution explains
unfinished tasks/items or commercial overrun; customer-declined closure creates no order.

## Scope

Idempotent planned-line conversion, Storage guards, draft purchase need, implementation transition,
Task/Asset/time/cost requirements, approved tolerance, structured close outcomes, Economy order
guard, UI, API, and events.

## Out Of Scope

Automatic vendor order sending and replacing actual time/cost records with quote lines.

## Data Touched

Planned-line conversions, Storage reservations/picks/purchase needs, Ticket Tasks/time/cost/events,
close outcome, Economy orders/lines, routes, views, and API.

## Permissions

Existing Storage, Ticket cost/time, Task, Asset, Economy, resolve/close permissions plus workflow
policy.

## Tests

No pre-approval side effects, accepted conversion, stock shortage, pick guard, implementation
requirements, tolerance/reapproval, no-sale close, idempotent Economy generation, and API parity.

## Documentation

Ticket, Storage, Economy, implementation, API, deploy/queue, and human review.

## Done Criteria

- [x] Planned lines are never reserved, picked, ordered, or billed before allowed approval.
- [x] Actual delivered records remain domain-owned and drive Economy.
- [x] Overruns require configured reapproval.
- [x] Focused cross-module tests pass on Dev.
