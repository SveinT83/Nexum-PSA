# Feature Slice: Workspace and source time corrections

Status: Implemented and verified on Dev / Human Reviewed / Main promotion authorized
Date: 2026-10-25
Parent: ../rfc/2026-10-25-clients-full-api-parity.md (Approved)
Owner: Codex
Human review: HR-2026-10-25-CLIENTS-API (Reviewed by Svein Tore, 2026-10-25)
Matrix: C11-C15

## Goal
Authorized owner links/projections and shared quick/Ticket/Task correction under Economy mutex.

## User-Visible Behavior
Official typed API parity for the mapped current browser workflow, with exact read-back.

## Scope
Authorized owner links/projections and shared quick/Ticket/Task correction under Economy mutex.

## Out Of Scope
Unapproved Client retention/deletion, new products, provider activation, Main/production rollout.

## Data Touched
Contact/Task/Commercial references, time entries, allocations and Economy source lock.

## Permissions
Owner-domain read ability and actor rights; source owner-or-higher edit guards. No automatic token widening.

## Tests
Positive and negative HTTP contracts, strict input, record/field/actor/token scope,
read purity, persisted read-back, rollback, stale revisions and replay/concurrency
where effects occur. Provider writes use fakes. Run affected Dev tests.

## Documentation
Update the source Clients API/overview and applicable owner documentation, matrix,
operation contract, TODO and human-review evidence. Verify actual BookStack at final handoff.

## Done Criteria
All mapped operations and schemas exist and are tested; same authoritative behavior
is used by web/API; no hidden owner bypass; meaningful Dev checks pass; documentation
and exact read-back evidence are recorded. Partial work does not close the parent.

## Completion evidence

Implemented and verified on authoritative Dev. Exact mapped operation IDs, schemas,
test methods/results, native checks and handbook read-back are recorded in
../plans/2026-10-25-clients-api-verification.md and .json.
HR-2026-10-25-CLIENTS-API is Reviewed by Svein Tore; this is not Main/production acceptance.

Promotion authorization: Svein Tore explicitly accepted the completed delivery on
2026-10-25: "Godkjent. Merge til main". HR-2026-10-25-CLIENTS-API is Reviewed.
The review/checklist statements above describe the preceding Dev handoff; Main
promotion evidence is recorded in 2026-10-25-clients-api-main-promotion.md.
Production deployment and provider activation are not authorized by this approval.
