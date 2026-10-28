# Feature Slice: Ticket Configuration And Assignment Administration

Status: Implemented and verified on Dev; merged to Main in PR #379 on 2026-10-28; operational human review Pending
Date: 2026-10-20
Parent: ../rfc/2026-10-20-ticket-task-full-api-parity.md (Approved by Svein Tore)
Owner: Codex
Review: HR-2026-10-20-TICKET-TASK-API (Pending; gates production deployment/release; Main merge separately approved 2026-10-28)
Matrix: T22-T23

## Goal

Deliver the approved existing browser/API operational equivalence for these matrix groups.

## User-Visible Behavior

Explicit queue/type/status/priority catalogs, Ticket policies, own technician preferences, administrative profiles and assignment rule CRUD. Selected default values and protected reference deletion are validated. Mutations and content-free audit receipts commit atomically. Assignment rule creation uses actor-bound key/fingerprint and reserves deleted keys.

## Scope

Explicit queue/type/status/priority catalogs, Ticket policies, own technician preferences, administrative profiles and assignment rule CRUD. Selected default values and protected reference deletion are validated. Mutations and content-free audit receipts commit atomically. Assignment rule creation uses actor-bound key/fingerprint and reserves deleted keys.

## Out Of Scope

No new product workflow, shared credentials, token widening, production activation, Main promotion or deployment.

## Data Touched

Existing Ticket catalogs/settings, assignment profiles/pivots/rules and activity_log. No new migration or permission grants. No arbitrary setting/model dispatcher.

## Permissions

Explicit catalog/settings/profile/assignment token scopes plus existing current-user domain permissions. Reads do not create technician profiles.

## Tests

Nine new administration tests and existing browser regressions; targeted idempotent rule create test passes after documenting first response 201 and replay 200. Canonical user table resolved through User::getTable, including configured user_management.

## Documentation

Update exact API/Knowledge sources, matrix, RFC, TODO and human review. Dev BookStack publication is blocked by missing supported integration configuration; Svein supplies that path, Codex performs scoped publication/read-back afterward. No employee handbook completion claim.

## Done Criteria

- Implemented operations have typed routes/contracts and independent persisted read-back.
- Relevant Dev tests pass with synthetic/faked external effects.
- Final matrix and source documentation reflect remaining gaps honestly.
- Named human review remains open until explicitly completed.

## Integrated Acceptance Checkpoint (2026-10-20)

The slice is implemented within the complete 216-operation surface. See
../plans/2026-10-20-ticket-task-api-acceptance.md for final combined tests, exact changed files,
trusted HTTPS Swagger read-back, deployment prerequisites and remaining checks.
HR-2026-10-20-TICKET-TASK-API remains Pending for operational checks and production deployment/release. The scoped Main merge was separately approved by Svein Tore on 2026-10-28.
Source Knowledge is updated; supported BookStack publication/access remains blocked with Svein
as configuration owner and Codex as scoped publication/read-back owner.

Final evidence: broad affected-domain run 560 passed / 15,569 assertions (66 files). After the
legacy internal-owner compatibility regression/fix, the complete affected Task/read-back/lifecycle/
Swagger set passed 106 tests / 11,079 assertions (12 files). See final acceptance for sequencing
and verification limits. No named human review was inferred from these automated results.

Main promotion authorization and current-Main verification: [2026-10-28-ticket-task-api-main-promotion.md](../plans/2026-10-28-ticket-task-api-main-promotion.md).

Main source verified: PR #379, f949c346347a037ea6e28a4a50e1f62bb0b9da48. Current complete coverage is 217 operations; production deployment and manual acceptance remain separate.
