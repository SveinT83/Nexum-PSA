# RFC: Email And Ticket Rules Signal Alignment

Status: Approved
Date: 2026-07-08
Owner: Codex

## Context

Email, Ticket, and Signal now all have rule engines:

- Email rules triage stored inbound messages, link replies, tag/archive messages, and create tickets.
- Ticket rules classify newly created tickets by setting fields such as type, queue, priority,
  category, tags, and SLA before assignment runs.
- Signal rules act on normalized cross-domain events and can tag clients/contacts, suppress
  marketing email, create Sales follow-up, create Ticket/Task follow-up, send portal invitations,
  emit derived signals, and queue webhooks.

This is useful, but the ownership boundary must be explicit before the rule engines expand further.
Without a clear boundary, the same business rule can be configured in more than one place, automation
can become hard to audit, and Signal-created tickets can accidentally trigger more Signal work.

This is Level 3 work because it affects Email, Ticket, Signal, automation ownership, cross-module
side effects, rule audit, and ticket creation behavior.

## Goals

- Define which rules emit Signals, consume Signals, or remain domain-local.
- Keep Email as the owner of inbound message storage, raw message triage, ticket ingress, and
  message-specific suppression.
- Keep Ticket as the owner of ticket field routing, SLA selection, workflow entry, and assignment
  inputs.
- Keep Signal as the owner of normalized events and cross-domain automation after an event exists.
- Add an explicit, opt-in Signal handoff from selected Email and Ticket rules instead of emitting
  Signals for every email or every ticket.
- Prevent loops where Signal creates a ticket, Ticket rules emit another Signal, and Signal creates
  another ticket.
- Preserve the existing ticket-first inbound email behavior for normal customer requests.

## Non-Goals

- Do not replace Email rules or Ticket rules with Signal rules.
- Do not emit Signal records for every inbound email, every ticket creation, every ticket update, or
  every assignment.
- Do not add broad Ticket rule triggers for replies, workflow changes, status timers, or SLA timers
  in this change.
- Do not remove the existing inbound Email machine-signal classifier.
- Do not move IMAP parsing, thread linking, Email tags, Ticket assignment, or SLA resolution into
  Signal.
- Do not add provider-specific monitoring ingestion beyond existing Signal API ingest.

## Current Behavior

Inbound email processing starts in `ProcessInboundRules`. It loads the stored `EmailMessage`, asks
`InboundEmailSignalClassifier` to detect machine/vendor signals, and archives the message when the
resulting Signal type should stop ticket routing. Deterministic Email signal types currently include
hard bounce, soft bounce, auto reply, out-of-office, unsubscribe request, and vendor notification.
Signal AI classification can also run as a settings-controlled fallback.

If Email classification does not stop ticket routing, `InboundEmailRuleEngine` runs active Email
rules. Current Email rule actions can link a message to a ticket by subject token, create a ticket,
archive the message, or tag the message. If no rule stops processing, default ticket policy links by
headers/subject token or creates a ticket for known contacts and lead tickets for unknown senders.

Ticket creation goes through `StoreTicket`, which applies `TicketRuleEngine` for the `on_create`
trigger before assignment. Current Ticket rule actions set type, queue, priority, SLA, category, and
tags. Ticket rules run for tickets created from manual entry, Email, Signal follow-up, Intake, and
other callers that use `StoreTicket`.

Signal records are created through `RecordSignal`, which runs matching Signal rules by default.
Signal action `ticket_follow_up` creates a normal ticket through `StoreTicket` using channel
`signal`, then stores Signal provenance in ticket metadata. The ticket is idempotent per Signal by
checking existing tickets with matching `metadata->signal_id`.

## Proposed Change

Define this ownership contract:

- Email rules remain local when they parse, link, tag, archive, or create tickets from stored
  inbound messages.
- Ticket rules remain local when they classify a ticket for routing, SLA, workflow entry, category,
  tags, and assignment inputs.
- Signal rules own cross-module automation once a normalized event exists.
- Email and Ticket rules may produce Signal records only through explicit, opt-in `emit_signal`
  actions.
- Signal-created tickets still pass through Ticket rules for field routing, but Ticket rule
  `emit_signal` actions are skipped by default when the source channel is `signal`.

### First Implementation Slice

Add explicit Signal handoff actions to Email and Ticket rules.

Email `emit_signal` action:

- Records a Signal with `source_domain: email`, source set to the `EmailMessage`, and contact/client
  context resolved from the sender or classified recipient when available.
- Requires the admin to choose `signal_type`; optional fields include severity, summary, confidence,
  and payload notes.
- Adds payload context such as email message id, rule id/name, from address, subject, current state,
  matched tags, and whether a ticket was already linked.
- Runs through normal Signal rule processing after the Email rule action records the event.
- Does not run for deterministic machine signals already recorded by `InboundEmailSignalClassifier`
  unless the admin explicitly creates a separate Email rule and chooses a different Signal type.

Ticket `emit_signal` action:

- Stores pending Signal emission intent while `TicketRuleEngine` evaluates `on_create`, then records
  the Signal after the ticket is persisted so the Signal has a stable ticket source id.
- Records a Signal with `source_domain: ticket`, source set to the `Ticket`, and client/contact
  context from the ticket.
- Requires the admin to choose `signal_type`; optional fields include severity, summary, confidence,
  and payload notes.
- Adds payload context such as ticket id/key, rule id/name, channel, queue, type, priority, category,
  SLA id/source, and ticket tags.
- Skips by default when the created ticket channel is `signal` to avoid loops. A later RFC can decide
  whether an explicit `allow_signal_channel` escape hatch is needed.

Idempotency rules:

- Email handoff must not create duplicate Signals for the same email message, Email rule, action, and
  signal type when inbound processing is retried.
- Ticket handoff must not create duplicate Signals for the same ticket, Ticket rule, action, and
  signal type when ticket creation or queued after-commit work is retried.
- Existing Signal `ticket_follow_up` idempotency remains unchanged.

Execution timing:

- Email handoff can record during inbound rule processing because the Email message already exists.
- Ticket handoff should record after the ticket transaction commits so Signal actions do not run
  against a ticket that might still roll back.

### Later Slices

Later slices can be proposed separately after the first handoff is proven:

- Better rule trace views that show Email rule, Ticket rule, Signal, and resulting action history
  together.
- Optional admin presets for common patterns such as vendor notices, security events, VIP
  escalation, or monitoring alerts.
- Broader Ticket triggers only after workflow, status, SLA, and loop-guard rules are designed.

## Impact Analysis

- **Email:** gains an opt-in Signal-producing action for selected inbound rules. Existing machine
  signal classification, archive behavior, spam/not-ticket tagging, thread linking, and default
  ticket policy stay in Email.
- **Ticket:** gains an opt-in Signal-producing action for ticket creation rules. Existing field
  routing, SLA resolution, assignment order, and ticket creation behavior remain Ticket-owned.
- **Signal:** remains the shared automation layer and receives more events only when an admin
  explicitly configures a handoff.
- **Permissions:** no new permissions are expected. Existing Email rule management, Ticket rule
  management, and Signal rule permissions apply.
- **Routes/UI:** Email and Ticket rule forms need one new action option each. Signal rule UI does
  not need to change for the first slice.
- **Data:** expected to use existing JSON action fields and existing `signals` payload fields. No
  migration is expected unless implementation finds a reliable idempotency key requires schema
  support.
- **Queues/transactions:** Ticket handoff should use after-commit behavior or equivalent so Signal
  automation runs only after the ticket exists durably.
- **Risk:** misconfigured rules can still create too much automation. The first slice reduces this
  by making Signal emission opt-in and by skipping Ticket emit actions for Signal-created tickets.

## Data And Migration Plan

No database migration is planned for the first slice.

Email and Ticket rule action JSON will support an additional `emit_signal` action shape. Existing
rules remain valid. Existing Signal records, rule executions, and webhook deliveries remain
unchanged.

Rollback can disable or remove the new rule actions from Email/Ticket rules without deleting Signal
history. Signal records already created should remain as audit history.

## Testing Plan

- Email rule `emit_signal` records one Signal with source `EmailMessage`, selected type, payload
  context, and resolved contact/client when available.
- Email rule `emit_signal` is idempotent if inbound processing retries the same message.
- Email machine-signal classifier behavior remains unchanged for hard bounce, auto reply,
  out-of-office, unsubscribe request, and vendor notification.
- Ticket rule `emit_signal` records one Signal after ticket creation with source `Ticket`, selected
  type, payload context, and client/contact context.
- Ticket rule `emit_signal` is idempotent if after-commit work retries.
- Ticket rule `emit_signal` does not run by default for tickets created through Signal
  `ticket_follow_up`.
- Existing Email rule actions, default inbound ticket policy, Ticket field-routing actions, Signal
  ticket follow-up, and Signal rule execution tests continue to pass.

## Documentation Plan

- Update Email Knowledge documentation to describe when Email rules should stay local and when to
  emit a Signal.
- Update Ticket rules/assignment Knowledge documentation to describe Ticket-local field routing
  versus Signal handoff.
- Update Signal README and Knowledge documentation with the Email/Ticket producer boundary and loop
  guard.
- Update `docs/TODO.md` when this RFC is approved and when the first slice is completed.

## Open Questions

Approve the proposed first slice: add explicit, opt-in `emit_signal` actions to both Email rules and
Ticket creation rules, with Ticket emit skipped by default for Signal-created tickets.

## Approval

Approved by Svein in conversation on 2026-07-08 when requesting implementation of the proposed first
slice.
