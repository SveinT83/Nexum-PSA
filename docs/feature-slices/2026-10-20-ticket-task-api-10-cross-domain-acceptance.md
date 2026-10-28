# Feature Slice: Ticket Cross-Domain And Portal APIs And Final Parity Acceptance

Status: Implemented and automated-verified on Dev; Main promotion explicitly approved 2026-10-28; operational human review Pending
Date: 2026-10-20
Parent: ../rfc/2026-10-20-ticket-task-full-api-parity.md (Approved by Svein Tore)
Owner: Codex
Review: HR-2026-10-20-TICKET-TASK-API (Pending)

## Goal

Complete existing Documentation request, bound Mail draft, Relationship escalation, SLA report and Customer Portal operations and reconcile every browser/API matrix group.

## User-Visible Behavior

Staff create/read exact documentation follow-up requests, prepare replies for a selected permitted Mail conversation, discover permitted Relationship targets/escalate/read actual sync status and read the existing SLA report calculations. Portal customers use their own existing authenticated identity and active Client/Site membership for list/create/public messages/attachments. No internal role or arbitrary client_id is accepted as a substitute.

## Scope

Explicit typed domain routes, shared authoritative actions, bounded customer-safe resources, actor-bound retries, nested ownership, current Mail membership/provider checks, delivery-state read-back and relevant owner regressions. Complete legacy HTTP method/OpenAPI/permission coverage, read-only queries, safe event history and final matrix inventory.

## Out Of Scope

New authentication/login/MFA stack, broader Portal rights, shared credentials, external sends/real stock/billing during QA, commit/push/Main promotion or production activation.

## Data Touched

Existing DocumentationRequest/TicketEvent, Mail communication/draft/submission, Relationship sync links, Portal audit/membership and Ticket records. Preserve source ownership and history; no broad backfill or schema change planned.

## Permissions

Separate scopes plus current domain/object/field/Mail rights. Portal route middleware resolves the existing customer identity and exact selected valid membership, never internal Ticket permissions. Existing web login/MFA remains authoritative; Sanctum authenticates the same User.

## Tests

API denial/read-back/replay and nested-scope tests; public-only Portal messages/files with no internal events/ledger/assignment exposure; native SLA summary/filter and current Mail/Relationship regressions. External effects faked. Full scoped suite and typed route/spec mapping before handoff.

## Documentation

Knowledge/API sources, full matrix JSON/Markdown, RFC/TODO and human review. BookStack publication remains blocked on configured supported integration; Svein owns configuration and Codex scoped publication/read-back.

## Done Criteria

Every existing browser operation maps to a verified API or an explicit UI-only presentation outcome. No implemented stub, failing verification or undocumented blocker is represented as complete. Named operational human review remains Pending and gates production deployment/release. Svein Tore separately approved the scoped Main merge on 2026-10-28.

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
