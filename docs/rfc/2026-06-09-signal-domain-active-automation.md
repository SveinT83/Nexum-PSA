# RFC: Signal Domain Active Automation

Status: Approved
Date: 2026-06-09
Owner: Codex

## Context

Nexum PSA needs a shared signal layer for Email, Marketing, Ticket, Sales, Contact, and future AI
workflows. Marketing tracking already produces opens, clicks, and unsubscribe events. Email inbound
processing will soon need bounce, auto-reply, out-of-office, and delivery-failure classification for
both Marketing and Ticket workflows. Keeping this logic inside one producer domain would create
duplicated rules and make cross-domain automation hard to audit.

## Goals

- Create a singular `Signal` module.
- Store normalized signals from Email, Marketing, Ticket, and future domains.
- Support active rules that can execute mutating actions.
- Audit every rule execution and action result.
- Support outbound webhooks from signal rules.
- Allow rules to suppress marketing email, tag Contacts/Clients, create follow-up signals, and
  prepare for Ticket actions.
- Integrate Marketing tracking/unsubscribe as the first producer.
- Keep Email inbound classification and Ticket consumption compatible with the same contract.

## Non-Goals

- Do not implement full AI classification in the first slice.
- Do not build every Email bounce parser in the first slice.
- Do not move Marketing tracking storage into Signal.
- Do not remove existing Email or Marketing logs.

## Current Behavior

Marketing stores campaign events locally. Email stores inbound messages and routing logs. Ticket has
its own workflow and tag behavior. There is no shared signal event store, no cross-domain rule engine,
and no central audit for automated actions.

## Proposed Change

Create `app/Modules/Signal` with:

- `signals`: normalized event/classification records.
- `signal_rules`: rule definitions with simple match conditions and actions.
- `signal_rule_executions`: audit rows for every matching rule.
- `signal_webhook_deliveries`: queued outbound webhook delivery attempts.

The first rule engine supports conditions for source domain, signal type, severity, status, and
minimum confidence. Actions are executed directly and audited:

- `marketing_suppress_contact_email`
- `tag_contact`
- `tag_client`
- `emit_signal`
- `webhook`

Marketing campaign events call Signal when opens, clicks, and unsubscribe events are recorded.

## Impact Analysis

Affected modules:

- `Signal`: new domain, routes, controllers, actions, jobs, tests, docs.
- `Marketing`: first producer of tracking/unsubscribe signals.
- `Contact` and `Client`: optional tag and suppression targets.
- `Taxonomy`: existing tags are reused for Signal actions.
- `Email`: future producer for bounce and auto-reply classification.
- `Ticket`: future consumer and producer for ticket-related signals.

Permissions:

- `signal.view`
- `signal.rule.manage`
- `signal.webhook.manage`
- `signal.action.execute`

Routes:

- `/tech/admin/system/signals`
- `/tech/admin/system/signals/rules`
- `/tech/admin/system/signals/rules/create`
- `/tech/admin/system/signals/rules/{rule}`

Queues:

- Webhook delivery jobs are queued on `default`.

## Data And Migration Plan

New tables are additive. No destructive migration is required. Rollback drops only Signal-owned
tables. Marketing event rows remain the source-specific audit trail even if Signal is rolled back.

## Testing Plan

- Signal routes and permissions.
- Recording a signal persists normalized data.
- Matching rules execute direct actions.
- Marketing unsubscribe creates a Signal and can trigger suppression/tag/webhook actions.
- Webhook delivery job records success/failure without blocking signal recording.

## Documentation Plan

- Signal module README.
- Signal Knowledge documentation.
- Update Marketing Knowledge with Signal integration.
- Update TODO active workstream.

## Open Questions

Future slices will decide the exact Email bounce classifier patterns and Ticket workflow actions.

## Approval

Approved by Svein Tore Ramstad in conversation on 2026-06-09. The requested direction was that Signal
must be an active domain with its own rules, direct actions, and webhook support.
