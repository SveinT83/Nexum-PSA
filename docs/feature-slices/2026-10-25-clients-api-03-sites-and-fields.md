# Feature Slice: Sites and custom fields

Status: Implemented and verified on Dev / Human Reviewed / Merged to Main in PR #319
Date: 2026-10-25
Parent: ../rfc/2026-10-25-clients-full-api-parity.md (Approved)
Owner: Codex
Human review: HR-2026-10-25-CLIENTS-API (Reviewed by Svein Tore, 2026-10-25)
Matrix: C07-C10

## Goal
Exact Site detail/create/update/delete/default transitions and per-field policy/read-back.

## User-Visible Behavior
Official typed API parity for the mapped current browser workflow, with exact read-back.

## Scope
Exact Site detail/create/update/delete/default transitions and per-field policy/read-back.

## Out Of Scope
Unapproved Client retention/deletion, new products, provider activation, Main/production rollout.

## Data Touched
Client Sites, custom values and existing reference constraints.

## Permissions
Existing Client create/update/delete and CustomField actor/definition authority. No automatic token widening.

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

Main implementation merge verified: [PR #319](https://github.com/SveinT83/Nexum-PSA/pull/319),
commit 154ca96b47867b5dfe7a21438d626000935fb24b. The complete merge tree equals the independently tested
candidate. HR-2026-10-25-CLIENTS-API is Reviewed. Production remains undeployed.
Current promotion evidence: 2026-10-25-clients-api-main-promotion.md.
