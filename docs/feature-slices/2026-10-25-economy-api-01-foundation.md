# Feature Slice: Shared policy, strict input, durable command evidence and serialization

Status: Done / Merged to Main; Human Reviewed
Date: 2026-10-25
Parent: ../rfc/2026-10-25-economy-full-api-parity.md (Approved)
Owner: Codex. Reviewer: Svein Tore. Review: HR-2026-10-25-ECONOMY-API (Reviewed).

## Goal

Shared policy, strict input, durable command evidence and serialization.

## User-Visible Behavior

All supported Economy browser operations have equivalent authorized typed API results.

## Scope

Receipt/mutex/notification tables; current actor/scopes; idempotency/revision and native locking.

## Out Of Scope

New invoicing/provider engine, production, Main promotion, real customer sends/billing, permission widening.

## Data Touched

Economy records, additive receipts/mutex/notification evidence; affected source and Data Exchange records only as the parent RFC specifies.

## Permissions

Current actor/object/parent rights plus explicit token abilities; separate portal identity; preserve owner domains.

## Tests

Meaningful allowed/denied and persisted result/side-effect tests, source/retry/race coverage where affected. Native lock/unique checks and browser/HTTP verification for final acceptance.

## Documentation

Update matrix/TODO/verification, Economy Knowledge and relevant owner articles; verify actual BookStack content/scope.

## Done Criteria

Implemented directly on Dev, relevant tests pass, exact result and ownership checked, contract/docs updated and evidence linked. Human review/Main/production remain separate.

## Verification

Completed under the approved full target. See ../plans/2026-10-25-economy-api-verification.md and
its exact test/native/HTTP/BookStack evidence. HR-2026-10-25-ECONOMY-API is Reviewed; Main merge is verified in PR #315; production deployment remains separate. All relevant E01-E14 rows are reconciled in the gap matrix.

Main implementation merge verified 2026-10-25: [PR #315](https://github.com/SveinT83/Nexum-PSA/pull/315),
commit 33eac1a3cba47ffe57f2253847dbaaf8f9b6d4e9. Tested candidate tree matches Main. See
../plans/2026-10-25-economy-api-main-promotion.md for evidence. Production is not deployed.
