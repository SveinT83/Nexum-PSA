# Feature Slice: Ticket Rule Lifecycle And Execution APIs

Status: Implemented and verified on Dev; merged to Main in PR #379 on 2026-10-28; operational human review Pending
Date: 2026-10-20
Parent: ../rfc/2026-10-20-ticket-task-full-api-parity.md (Approved by Svein Tore)
Owner: Codex
Review: HR-2026-10-20-TICKET-TASK-API (Pending; gates production deployment/release; Main merge separately approved 2026-10-28)
Matrix: T24-T25

## Goal

Deliver the approved existing browser/API operational equivalence for these matrix groups.

## User-Visible Behavior

Expose catalog/draft/create/update/preview/publish/versions/enabled state, legacy catalog actions, bounded execution detail, action retry and receipt-gated full rerun using current domain actions and allowlisted presenters. Preserve all default-off capability, system-actor, current-state and field-evidence guards.

## Scope

Expose catalog/draft/create/update/preview/publish/versions/enabled state, legacy catalog actions, bounded execution detail, action retry and receipt-gated full rerun using current domain actions and allowlisted presenters. Preserve all default-off capability, system-actor, current-state and field-evidence guards.

## Out Of Scope

No new product workflow, shared credentials, token widening, production activation, Main promotion or deployment.

## Data Touched

Existing rule drafts/versions/authority fence and execution evidence. Reuse journal only for transport receipts, never raw rule/customer data. No schema migration or runtime activation.

## Permissions

Separate tickets.rules.read/manage/preview/publish/executions.read/retry/full-rerun scopes with existing ticket.manage_rules/rule_preview/rule_publish/rule_execution_view/rule_retry/rule_full_rerun plus ticket.view/object/field checks.

## Tests

Latest Dev evidence: 12 new API tests plus 9 existing retry/full-rerun tests pass (21 / 223 assertions); 21 existing builder/catalog tests pass (274 assertions). Twenty typed operations and seven scopes are registered. Unknown node values are hidden and preserved through position-bound source placeholders/checksums. Final integrated/OpenAPI verification remains required.

New permission, nested ownership, current-state/checksum, no-write preview, publication replay, retry and full-rerun tests; existing rule lifecycle/history/retry tests. All tests isolate runtime gate overrides from actual Dev configuration.

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
