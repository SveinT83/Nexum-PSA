# ADR: Signal Active Rule Domain

Status: Accepted
Date: 2026-06-09
Decision Makers: Svein Tore Ramstad, Codex

## Context

Email, Marketing, Ticket, Sales, Contact, and future AI workflows need a shared way to represent
events and classifications. The decision was whether Signal should be passive storage only or an
active automation domain.

## Decision

Signal is an active domain. It owns normalized signal records, rule evaluation, rule execution audit,
and outbound webhook delivery. Producer domains still keep their domain-specific records and call
Signal with normalized context.

## Rationale

Centralizing rules prevents Marketing, Email, and Ticket from each inventing their own automation
model. Direct actions are required because bounce, unsubscribe, tagging, and later Ticket routing
need to happen automatically. Audit rows make those mutating actions inspectable.

## Consequences

Positive:

- Cross-domain events use one vocabulary.
- Automation is auditable.
- Webhooks can be driven by the same rule engine.
- AI classification can later write normalized signals instead of special-case domain records.

Negative:

- Signal becomes part of the critical path for some automations.
- Rule actions must be conservative and well tested.
- Producer domains need stable contracts when writing signals.

## Alternatives Considered

- Passive event store only: rejected because the user explicitly needs Signal to execute actions.
- Keep rules in Marketing or Email: rejected because Ticket and future AI flows need the same layer.

## Follow-Up

- Add Email inbound bounce/autoreply classifier.
- Add Ticket signal actions and consumption.
- Add richer rule condition builder after the first JSON-backed rule UI.
