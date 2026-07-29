# ADR: Ticket API Message Idempotency And Side-Effect Boundary

Status: Accepted
Accepted: 2026-07-29 by Svein Tore
Date: 2026-07-29
Decision Makers: Svein Tore / Codex
Related RFC: `docs/rfc/2026-07-29-ticket-api-customer-completion-flow.md`
GitHub issue: #194

## Context

A new Ticket API customer-reply endpoint will create a public technician message and trigger several
irreversible or externally visible side effects: outbound customer email, Customer Portal
notification, relationship synchronization, owner notification, Ticket events, first-response
metadata, and workflow actions. Network clients must retry safely when a response is lost or times
out.

A read-before-write lookup in JSON metadata is not concurrency-safe. Two parallel requests can both
observe no result, insert two messages, and queue two customer emails. Using the existing external
message synchronization path is also incorrect because that boundary deliberately stores an
external author, strips solution intent, and suppresses normal outbound email.

The implementation also needs to preserve one source of truth for Ticket reply behavior instead of
rebuilding email, notification, and workflow logic in an API controller.

## Decision

Use the existing `AddTicketMessage` action as the only side-effect owner for new outbound technician
replies. Add a narrow Ticket-owned idempotent wrapper for the customer-reply API.

Add nullable `idempotency_key` and `idempotency_fingerprint` columns to `ticket_messages`. Enforce a
unique database index on `ticket_id + idempotency_key`. Web, inbound email, portal, external sync,
and other non-API messages keep both columns null.

The wrapper will:

- normalize the side-effect-driving reply payload;
- hash the normalized payload with SHA-256;
- reserve the Ticket-scoped idempotency key through the unique message insert;
- call `AddTicketMessage` only for the winning first request;
- return the existing message for the same actor, key, and fingerprint;
- reject key reuse by a different actor or payload with HTTP 409;
- reject retries whose original message was soft-deleted; and
- recover from a concurrent unique-key collision by reloading and validating the winning row.

Idempotency lookup happens only after token ability, active-user, and current domain-permission
checks. A valid replay returns the original result without re-evaluating mutable portal, Contact, or
workflow state and without rerunning any side effect.

Portal publication uses a different idempotency strategy. It is a one-way Ticket state transition,
so a shared `PublishTicketToCustomerPortal` action will lock the Ticket row, check
`portal_visible_at` inside the transaction, and emit its event and notification only for the first
state change. It does not need a separate operation ledger.

The customer-reply endpoint receives the dedicated `tickets.reply_customer` Sanctum ability, while
portal publication receives `tickets.portal.publish`. The authenticated internal user must also hold
the existing Spatie domain permission checked by the Ticket action or publication service.

## Rationale

- A unique database constraint is the only reliable final guard against parallel duplicate writes.
- Keeping the idempotency identity on the message makes the successful result and its replay key one
  durable record without a second generic operation table.
- Ticket-scoped keys are simple for coordinators and prevent the same Ticket from receiving duplicate
  replies if an integration accidentally changes tokens or users during retry.
- A payload fingerprint prevents silent acceptance when a client reuses a key for different content.
- Retaining soft-deleted rows preserves the reservation and prevents an old retry from recreating and
  resending a deleted reply.
- Reusing `AddTicketMessage` keeps persistence, email, notifications, events, relationship sync, and
  workflow triggers transactionally aligned with the technician UI.
- Separate least-privilege abilities avoid granting broad workflow/commercial Ticket actions merely
  to publish or reply.
- Natural state idempotency is clearer and smaller for one-way publication than forcing every action
  into a generic ledger.

## Consequences

Positive consequences:

- Customer-reply retries cannot create or queue duplicate side effects when the key is used
  correctly.
- API and technician UI share the same Ticket message behavior.
- Conflicting key reuse becomes visible as an explicit client error.
- Existing message rows and non-API creation paths remain compatible.
- Portal publication becomes safe under concurrent web/API requests.
- API keys can be granted only the customer-facing capabilities they require.

Negative consequences:

- `ticket_messages` gains two API-oriented nullable columns and a unique index.
- Idempotency keys are reserved permanently per Ticket, including after soft deletion.
- The wrapper must handle database unique violations carefully and distinguish matching retries from
  conflicts.
- A valid replay may return success after the Ticket has moved or closed because it represents the
  already-completed original operation; it must not be mistaken for authorization to perform a new
  action.
- Existing tokens need explicit ability updates unless they intentionally use full access.

## Alternatives Considered

Store the key only in message JSON metadata:

- Rejected because JSON lookup has no practical unique constraint for the Ticket/key pair and is
  unsafe under concurrent retries.

Create a generic API idempotency ledger:

- Rejected for this slice because the only new create-once object is the Ticket message. A generic
  ledger adds lifecycle, retention, response serialization, cleanup, and cross-domain ownership that
  are not yet required. Revisit if several domains need reusable operation replay.

Use `external-messages` with special metadata:

- Rejected because it would collapse inbound synchronization and outbound technician authorship,
  weaken the #129 safety boundary, and bypass normal email/workflow semantics.

Use only an application mutex or cache lock:

- Rejected because locks can expire, disappear, or differ between processes. They may reduce
  contention but cannot replace database uniqueness.

Use `tickets.actions` for both new endpoints:

- Rejected because it grants much broader workflow, commercial, review, escalation, and close
  capabilities than portal publication or customer reply requires.

Add a separate API solution endpoint immediately:

- Rejected because `reply_intent = send_solution` already invokes the established public
  technician-solution behavior. A separate endpoint can be proposed later if post-creation solution
  changes become a real coordinator requirement.

## Follow-Up

- Accept this ADR only together with approval of the related Level 3 RFC.
- Add and verify the Ticket message migration and composite unique index.
- Implement focused duplicate, conflicting-payload, different-actor, soft-delete, and race-recovery
  tests.
- Document idempotency scope, 200/201/409 behavior, and irreversible side effects in OpenAPI and
  Ticket/Integration Knowledge.
- Revisit a generic cross-domain idempotency ledger only when another approved API requires durable
  replay of a different operation type.

## Implementation Result

- Accepted with the parent RFC on 2026-07-29.
- Migration `2026_07_29_120000_add_api_idempotency_to_ticket_messages` added the nullable key,
  fingerprint, and Ticket-scoped unique index and ran on Dev in batch 54.
- `StoreIdempotentTicketCustomerReply` performs authorization before lookup, validates matching
  replays, rejects actor/payload/deleted conflicts, and reloads the database winner after a unique
  collision.
- `AddTicketMessage` remains the sole persistence and side-effect owner for the winning request.
- Focused tests verify one message/job across retries, conflict semantics, permanent soft-delete
  reservation, the solution completion flow, and inbound external-message isolation.
