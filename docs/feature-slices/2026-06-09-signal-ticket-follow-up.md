# Feature Slice: Signal Ticket Follow-Up

Status: Done
Date: 2026-06-09
Parent: `docs/rfc/2026-06-09-signal-domain-active-automation.md`
Owner: Codex

## Goal

Allow Signal rules to create operational tickets when a signal needs technician follow-up.

## User-Visible Behavior

Signal rules can use `ticket_follow_up` to create a normal Ticket owned by the Ticket module. The
created ticket is unread, linked to the signal through metadata, and includes an internal note with
the signal context.

## Scope

- `ticket_follow_up` Signal action.
- Idempotency per signal to avoid duplicate tickets.
- Documentation and tests.

## Out Of Scope

- Ticket actions from the Ticket UI.
- AI-generated ticket routing.
- Phone-list scoring.

## Data Touched

- Reads `signals` and `signal_rules`.
- Writes `tickets`, `ticket_messages`, and normal Ticket side effects from `StoreTicket`.

## Permissions

No new permission. Only users with `signal.rule.manage` can configure rules that trigger this
action.

## Tests

Signal feature test verifies ticket creation, metadata, internal note, and idempotency.

## Documentation

Updated Signal README, Knowledge docs, and TODO.

## Done Criteria

- Matching Signal rules can create one ticket per signal.
- Ticket creation uses the Ticket module's `StoreTicket` action.
- Targeted tests pass.
