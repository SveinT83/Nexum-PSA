# ADR: Contact API Authority And Canonical Identity

Status: Accepted by Svein Tore through RFC approval, 2026-10-27
Date: 2026-10-27
Decision Makers: Svein Tore / Codex
Related RFC: ../rfc/2026-10-27-contact-full-api-parity.md (Draft).

## Context
Contact APIs must cover the canonical browser workflow while preserving stable legacy
identities and CustomerPortal's separate permission-bearing invitation lifecycle.
Current token checks alone do not enforce current native actor rights, and retries of
an invitation or ownership repair can repeat side effects.

## Decision
Proposed: use Contact-owned routes and shared actions/queries with both explicit token
abilities and current native rights. Contact remains canonical; client_users IDs remain
stable bridge identifiers. Ticket contact_id always uses the verified customer's active
bridge ID. CustomerPortal keeps role/scope/invitation validation and after-commit delivery.
Add narrowly bound durable command receipts, previews, stale-state/concurrency checks
and default-off external invitation execution. Recheck authority before execute/replay/read.
Do not create new destructive or merge behavior to make a numerical coverage claim.

## Rationale
The same business actions preserve workflow and audit semantics across entry points.
Separate ability and actor checks prevent token authority outliving actual human rights.
Exact receipts distinguish committed local state from queued/unknown external delivery.

## Consequences
An additive replay ledger and native-database locking tests are required. Existing
callers may need their legitimate native permission restored by an authorized human;
this change never expands grants automatically. Current API URLs remain compatible.
No raw invitation tokens or credentials may enter responses, docs or ordinary audit.

## Alternatives Considered
Direct table CRUD would bypass domain invariants. Token-only authorization would leave
actor revocation ineffective. A second invitation implementation would diverge from
CustomerPortal. Broad contact merge/delete would introduce unapproved product behavior.

## Follow-Up
Approve the linked RFC, implement sequential slices, complete the coverage matrix,
verify BookStack, and retain HR-2026-10-27-CONTACT-API until named human acceptance.
This proposal does not supersede the accepted 2026-09-03 canonical Contact identity ADR.
