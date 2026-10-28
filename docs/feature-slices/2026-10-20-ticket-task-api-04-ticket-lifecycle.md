# Feature Slice: Ticket Fields And Lifecycle

Status: Implemented and automated-verified on Dev; Main promotion explicitly approved 2026-10-28; operational human review Pending
Date: 2026-10-20
Parent: ../rfc/2026-10-20-ticket-task-full-api-parity.md (Approved by Svein Tore)
Owner: Codex
Review: HR-2026-10-20-TICKET-TASK-API (Pending; gates production deployment/release; Main merge separately approved 2026-10-28)
Matrix: T03-T04, T09, T14-T17

## Goal

Deliver the approved existing browser/API operational equivalence for these matrix groups.

## User-Visible Behavior

Full Ticket fields, tags and scheduling; read receipts; assignment preview and retry-safe assignment; not-ticket/delete with stale-snapshot checks. Svein approved merging all selected clients without a preview on 2026-10-20. Merge preserves target Task/reservation context and historical financial identity as specified in the RFC.

## Scope

Full Ticket fields, tags and scheduling; read receipts; assignment preview and retry-safe assignment; not-ticket/delete with stale-snapshot checks. Svein approved merging all selected clients without a preview on 2026-10-20. Merge preserves target Task/reservation context and historical financial identity as specified in the RFC.

## Out Of Scope

No new product workflow, shared credentials, token widening, production activation, Main promotion or deployment.

## Data Touched

Ticket, TicketEvent, scheduling/calendar, assignment and Mail classification; no schema migration.

## Permissions

Existing Ticket view/create/update and action-specific rights plus implemented token scopes. No actor receives extra browser authority.

## Tests

TicketLifecycleApiTest and customer-completion regressions: 12 passed / 114 assertions. Final integrated and affected regressions passed; evidence below.

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
