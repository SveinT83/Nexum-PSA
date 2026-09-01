# Feature Slice: Ticket Message Read API

Status: Done on Dev; human review pending
Date: 2026-08-25
Parent: `docs/rfc/2026-06-01-domain-api-foundation.md`
GitHub issue: #240
Human review: `HR-2026-08-25-011`
Owner: Svein / Codex

## Goal

Let an authorized coordinator verify Ticket message existence and first-response timing through a
stable read endpoint without exposing customer or internal message content.

## User-Visible Behavior

`GET /api/v1/tickets/{ticket}/messages` accepts the public Ticket key and returns newest-first,
paginated message metadata. The response also includes the Ticket's authoritative
`first_responded_at`, `first_response_due_at`, and whether the recorded response met that due
time.

## Scope

- Ticket-owned API route, controller, query, and summary resource.
- Existing `tickets.read` Sanctum ability.
- `per_page` validation from 1 to 100, defaulting to 25.
- Message ID, type, visibility, author type, and creation timestamp.
- Summary flags for message existence and first-response verification.
- OpenAPI, Ticket Knowledge, API Management Knowledge, and regression coverage.

## Privacy Boundary

The endpoint deliberately excludes body, subject, raw metadata, attachments, author ID,
idempotency data, and all customer content. It does not add filters or include options that can
expand this projection.

## Out Of Scope

Message creation, editing, deletion, attachments, transcript search, customer-content retrieval,
new abilities, workflow changes, SLA calculation changes, or database changes.

## Data Touched

Read-only access to the existing `tickets` and non-deleted `ticket_messages` rows. No migration,
queue, scheduler, cache, or frontend build change is required.

## Permissions

The route remains inside `auth:sanctum` and requires the existing `tickets.read` ability. Missing
ability returns HTTP 403; invalid Ticket keys use normal Ticket route binding and return HTTP 404.

## Tests

- Route ownership, GET/HEAD methods, and `tickets.read` middleware.
- Newest-first pagination and Ticket isolation.
- Authoritative first-response summary and due-time comparison.
- Explicit absence of body, subject, metadata, attachments, author ID, and test customer content.
- Missing ability and out-of-range pagination failures.

## Done Criteria

- [x] The endpoint is Ticket-owned and uses the existing least-privilege read ability.
- [x] Pagination is bounded and deterministic.
- [x] The projection contains only coordination metadata.
- [x] First-response verification uses persisted Ticket timestamps.
- [x] Focused regression tests pass on Dev.
- [ ] Named human reviewer confirms the live API contract before merge or release.
