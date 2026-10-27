# ADR: Marketing API Authority And Delivery Boundaries

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore / Codex
Related: ../rfc/2026-10-27-marketing-full-api-parity.md (Draft).

## Context

Existing Marketing API routes cover core writes but omit parts of web context and
read-back. They use token abilities without explicit native Marketing guards, share
editing authority with provider calls and have no complete typed Swagger contract.
Marketing already owns a lifetime delivery ledger and an at-most-once claim boundary;
a transport extension must not replace those invariants or infer delivery from queueing.

## Decision

Approved under the parent RFC by Svein Tore, 2026-10-27: keep one Marketing domain workflow shared by web/API,
requiring fresh native actor/object rights and scoped token abilities. Provide narrow
workflow contexts without global data grants. Separate external AI/WordPress/SMTP API
execution using explicit ability and a default-off guard that propagates to queued work.
Use encrypted actor/token-bound durable command receipts and locked state preconditions;
retain existing optional-key compatibility explicitly without claiming safe unkeyed replay.
Preserve existing public recipient capabilities, Email ownership and evergreen delivery
identity/claim state. Do not seed privileges or activate providers during ordinary reads.

## Rationale

One authoritative mutation boundary prevents transport drift and concurrent overwrites.
Token scopes should constrain current actor rights rather than create them. Durable
local read-back and provider ambiguity are distinct from inbox receipt; existing stable
campaign-email/person/mailbox identities remain the no-resend authority.

## Consequences

New tests/migration and integration compatibility documentation are required. Narrow
contexts must work for real operators. Existing scope-only actors need deliberate native
permission configuration; nothing is granted automatically. Provider disabled/enabled,
revocation, unknown outcomes and exact result contracts all need verified success paths.

## Alternatives Considered

Read-only additions fail the requested parity. Duplicated API-only business logic drifts
from web. Scope-only authorization or editor-only external execution grants excess power.
Using command keys instead of delivery claims would not protect historical no-resend.

## Follow-Up

Approve the parent RFC, implement its sequential slices, preserve shared Dev work,
record native database/HTTP/OpenAPI tests and actual handbook read-back. Human review,
Main promotion, production and runtime activation remain separate gates.
