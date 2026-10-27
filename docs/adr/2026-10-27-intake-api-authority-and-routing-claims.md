# ADR: Intake API Authority, Public Acceptance And Routing Claims

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore (approved 2026-10-27), Codex (implementation)
Parent: rfc/2026-10-27-intake-full-api-parity.md

## Context

Intake has public form access and protected administrative review/routing with PII,
private uploads and downstream target/Signal effects. A generic CRUD adapter or browser
redirect wrapper cannot preserve these boundaries or guarantee one target on replay.
Four browser review actions currently use a view-permission fallback; the approved
routing documentation requires intake.submission_review. Legacy Sales Client creation
also conflicts with current Clients provider/number/billing authority.

## Decision

Accepted: one Intake-owned typed API, shared native validation/actions/authorization,
explicit internal scopes plus human permissions and a separate public safe projection.
Correct the four browser review-route guards to the documented review permission.
Staff mutations use encrypted actor/token-bound durable claims, canonical payload/file
hashes, revision checks, locks and explicit skip/unknown outcomes. Public retries share
form-bound claims but return only generic acceptance, never internal receipt details.
Recheck current authority on every result/replay. Use existing Clients-owned creation
intent for Sales auto-create; Contact, Ticket, Task and Signal keep their authority.

## Rationale

Transport cannot grant permission, ownership of an Intake record cannot grant arbitrary
target access, and public submission cannot expose matching or operational outcomes.
Shared rules prevent browser/API drift. Durable claims prevent duplicate targets,
files and events, including cross-transport and competing-route retries. Unknown
provider outcomes must be reconciled rather than retried as new customer creation.

## Consequences

Additive receipt storage and explicit client integration tests are required. More
precise guards deny view-only callers that relied on four unintended route fallbacks.
No existing role receives extra rights. Public clients receive a typed acknowledgement
instead of target IDs or internal audit. Existing switches remain authoritative.
Old reviewed human checks are retained; a new API review checklist remains pending.

## Alternatives Considered

Browser-controller forwarding: redirect/session output and duplicated authorization.
Generic model CRUD: bypasses lifecycle, private storage and target ownership.
New Intake automation engine: duplicates Signal.
Only read endpoints: cannot satisfy requested operational parity.
Local customer numbering under active provider sync: violates Clients authority.

## Follow-Up

RFC approved and sequential slices implemented; verified INTAKE01-INTAKE05, exact
route/schema/side-effect evidence and real handbook read-back. Review ID:
HR-2026-10-27-INTAKE-API. Main and production remain separately authorized gates.
