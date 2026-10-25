# ADR: Commercial API Commands And Frozen Delivery

Status: Accepted
Date: 2026-10-25
Decision Makers: Svein Tore (approved RFC), Codex (technical defaults within scope).

## Context

Full operational parity needs shared workflows, current actor rights, pure reads, exact parent
identity, durable retries and truthful frozen outbound delivery evidence.

## Decision

Commercial owns durable command receipts and protected delivery attempts, independent of activity
retention. Serialize commands by current actor and target, require fresh content revisions for new
mutations, canonical fingerprints and scoped replay. Keep current actor/token/object/field checks.
Browser, Livewire and typed API delegate to shared domain operations; no HTML/controller wrapping.
Use the existing exact pricing/customer-document/term evidence boundary without historical rebuilds.
Freeze outbound recipient/CC/body/document/provider binding and dispatch after commit. A started
unknown SMTP attempt is not retried blindly. Staff, exact public capability and portal identity remain
distinct. Keep owner-domain APIs for Client/Ticket/Economy/Integration operations.

## Rationale

A token cannot substitute for current rights; audit cleanup must not permit replay; mutable
recipient/content would invalidate review; SMTP acceptance is not inbox receipt.

## Consequences

Additive schema and preserved receipt/delivery evidence are required. Default-off capabilities stay
off. Named human acceptance is recorded in HR-2026-10-25-COMMERCIAL-API (Reviewed by Svein Tore,
2026-10-25). Main/production and external message checks remain separate actions.

## Alternatives Considered

Controller/HTML wrapping, generic model mutation, transient audit replay and blind SMTP retry are
rejected because they bypass typed workflows or lose authorization/evidence guarantees.

## Follow-Up

All six slices, native/Dev tests, OpenAPI and handbook read-back are complete.
HR-2026-10-25-COMMERCIAL-API is Reviewed following Svein Tore's "Godkjent" on 2026-10-25.
Main promotion, production deployment and controlled delivery activation require separate execution.
