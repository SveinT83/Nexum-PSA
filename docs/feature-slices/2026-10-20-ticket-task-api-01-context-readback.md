# Feature Slice: Ticket/Task API Context And Bounded Read-Back

Status: Implemented and verified on Dev; merged to Main in PR #379 on 2026-10-28; operational human review Pending
Date: 2026-10-20
Parent: ../rfc/2026-10-20-ticket-task-full-api-parity.md (Approved by Svein Tore)
Owner: Codex
Human review: HR-2026-10-20-TICKET-TASK-API (Pending)

## Goal

Provide typed, bounded context and exact read-back APIs that later action slices can reuse,
with explicit token abilities AND the same active-user domain rights as browser operations.

## User-Visible Behavior

Authorized API clients can discover current supported operations, permitted form references,
record-specific rate/context, Ticket ledgers/activities/planned lines/Tasks/schedule, and Task
activities/time/children/dependencies. GET requests do not mark content read or create business data.
Conversation bodies/files and new mutations belong to the next slices and are not advertised ready.

## Scope

- Shared active-actor/token/domain permission checks and explicit safe resource projections.
- Module-owned context/capability/reference reads with bounded pagination and deterministic ordering.
- Record-scoped GET read-back for implemented time/cost/scope/Task activity outcomes.
- OpenAPI, relevant permission/visibility/scope/bounds tests and documentation.
- Preserve existing limited message metadata contract and all ordinary browser behavior.

## Out Of Scope

New domain writes, shared timer service, provider calls, runtime gate activation, production,
Main/push, schema/data migrations and incomplete API controls.

## Data Touched

Read existing Ticket/Task/domain-reference tables. No stored business data changes or migrations.
New files/directories match web-readable project modes/group; no credentials or tokens changed.

## Permissions

Use implemented read scopes plus narrow time/cost scopes only where those routes exist. Require
active internal actor and current browser-equivalent domain permission. Preserve object context,
private Task visibility, nested ownership and distinct Portal identities. Capabilities describe
actual registered routes and current authorization; record/runtime checks remain explicit.

## Tests

Run isolated SQLite Dev baselines, then verify token-only/user-only/inactive/portal denial,
allowed reads, exact nested ownership, private record isolation, safe fields, complete/bounded
pagination, reference validation and read-only behavior. Generate and compare OpenAPI operations.

## Documentation

Update Ticket/Task API Knowledge, API management, this slice, matrix/TODO and human review.
Publish/read back through supported Knowledge/BookStack flow, or record concrete blocker/owner.

## Done Criteria

- [x] Narrow Dev baseline and relevant regressions pass.
- [x] Typed context/capability/read-back APIs have both actor and token authorization.
- [x] Nested ownership, safe projection and bounded deterministic reads verified.
- [x] No GET produces business mutations.
- [x] OpenAPI/request/response/error contracts match every added operation.
- [x] Knowledge source/publication status and human-review checklist reflect actual evidence.

Publication remains blocked by absent Dev BookStack configuration; exact blocker/owner is in TODO.
Source and publication status are recorded truthfully; no handbook delivery is claimed.

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
