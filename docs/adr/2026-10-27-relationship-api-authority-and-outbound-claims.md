# ADR: Relationship API Authority And Outbound Claims

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore (approved parent RFC) / Codex
Parent: ../rfc/2026-10-27-relationship-full-api-parity.md

## Context
Full browser/API coverage must preserve source ownership, credential custody, Ticket
receipts and independent signed transport. A remote change can outlive a lost reply.

## Decision
Keep Relationship routes, shared validation/persistence, source-domain authority and
existing Ticket workflows. Match native permission-based AdminAccess, not a fixed role.
Separate Sanctum from machine HMAC. Use actor/token/key identities, encrypted receipts
and state preconditions. Reserve source-version/destination push before network I/O
across browser/API; retain immutable encrypted results. Unresolved source attempts
block later exports. API outbound stays default-off with explicit provider scope.
Suppress redirects and unsafe provider text. Reads expose safe metadata.
Use the existing configured Relationship creator for received Knowledge ownership;
never choose an unrelated user, impersonate a login or bootstrap extra privileges.

## Rationale
Local transactions cannot undo remote changes. HTTP acceptance differs from actual
remote content read-back. Lost replies cannot justify another send. Shared claims
prevent browser retry from bypassing API protection.

## Consequences
One additive migration creates commands, mutex and outbound claims. No grants,
historical resend, generic reset/retry, provider activation or ownership relocation.
Operators must reconcile unknown results. MySQL uniqueness/locking and cross-domain
tests are required. Knowledge retains revision/publication authority.

## Alternatives Considered
Rejected scope-only authorization, generic serialization, local-only idempotency,
duplicate Ticket escalation and automatic resend after timeout. Fixed Admin checks
would deny current permission-based operators incorrectly.

## Follow-Up
Keep coverage, Swagger, handbook read-back and HR-2026-10-27-RELATIONSHIP-API current.
Human checks remain Pending for production release. Main promotion is explicitly
authorized; merging does not authorize production/runtime activation.
