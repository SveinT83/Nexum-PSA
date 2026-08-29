# Feature Slice: RMM Alert Rule Domain Actions

Status: Done on Dev (human review pending)
Date: 2026-08-25
Parent: `docs/rfc/2026-08-25-rmm-alert-rules.md`
Owner: Codex

## Goal

Execute the approved create Ticket, create Task, reopen Ticket, and emit Signal actions through
authoritative domain boundaries with fingerprint deduplication and durable work links.

## User-Visible Behavior

A matching RMM alert can create or update a Ticket, create or reuse a Task, reopen a linked Ticket
through its Workflow, or emit a Signal. Ticket Rules and Signal Rules continue after their normal
domain entry points.

## Scope

- Add the protected `rmm_alert_rule_automation` system actor.
- Add typed, validated action handlers and ordered failure semantics.
- Create/update Tickets by fingerprint through `StoreTicket` and idempotent internal notes.
- Allocate Ticket keys through a locked yearly sequence so parallel RMM actions cannot collide.
- Create/reuse open Tasks through `StoreTask` and Task activity.
- Reopen only through an exact configured non-terminal Ticket status and valid Workflow transition.
- Emit one idempotent RMM Signal and invoke normal Signal Rules.
- Keep downstream Signal webhooks in a uniquely keyed durable outbox with pending-row recovery and
  at-most-once automatic HTTP start.
- Store target links and actual action results.

## Out Of Scope

- Automatic close/complete on RMM recovery.
- Ticket or Task mutation outside their existing domain Actions.
- Direct notification, webhook, RMM script, provider write, or AI actions.
- A generic action registry shared with another rule engine.

## Data Touched

RMM execution/work-link tables plus normal Ticket, Task, Task activity, Ticket message/event, Signal,
and Signal execution data produced by their authoritative Actions. Cross-domain reliability also
uses `ticket_key_sequences` and additive Signal webhook delivery claim/action-key columns.

## Permissions

The system actor receives only `ticket.create`, `ticket.note_internal`, `ticket.reopen`,
`task.create`, and the system-only `task.source_update` used for internal activity on a reused Task.
Runtime reopening
is reauthorized by Ticket's action and Workflow guards.

## Tests

- Ticket/Task creation and open-work reuse retain RMM provenance.
- Valid Workflow reopen, no-target skip, and lower create-Ticket fallback.
- Signal handoff continues through normal Signal Rules.
- Per-action failure marks later actions `not_run` but allows a lower rule.
- Asset ownership drift, Asset reassignment, and manual Ticket/Task reassignment fail closed or
  create context-safe replacement work.
- Routing references disabled after rule save fail closed while a lower fallback rule may continue.
- RMM Ticket creation/updates/reopen suppress synchronous owner notifications, and Signal webhook
  rows commit with the action so no external request or row survives a rolled-back RMM action.
- Ticket sequence locking, unique Signal webhook action keys, stranded-pending dispatch, duplicate
  job claims, abandoned claims, ambiguous transport, broker failure, and scheduler registration.

## Documentation

Document action semantics and target-domain ownership in RMM Knowledge documentation.

## Done Criteria

- Idempotent action re-entry cannot create a duplicate target for one action key; automatic
  occurrence retry/full-rerun is intentionally unsupported after execution starts.
- Same-fingerprint open work is reused as documented.
- Reopen never directly patches Ticket status.
- Every side effect has a durable work link and execution result.
- Parallel Ticket creation and downstream Signal webhook delivery have database-enforced uniqueness
  boundaries rather than process-local deduplication.
- Cross-module focused Dev tests pass.

## Verification

On 2026-08-26, the combined RMM rule/provider suite passed 25 tests with 226 assertions. Related
regressions passed: Signal 39 tests / 208 assertions, Task 31 / 202, and TicketModule 127 / 952. A
live two-connection MariaDB sequence probe also completed without deadlock and rolled back.
Tests cover
Ticket/Task reuse, Workflow reopen, Signal Rule continuation, commit/rollback webhook delivery,
unique webhook action identity and claim/recovery, terminal action failure, every stored Ticket/Task
routing-reference type, context-safe replacement, lost-lease blocking, and suppressed RMM Ticket
notifications. Human review
`HR-2026-08-25-014` remains Pending.
