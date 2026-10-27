# Feature Slices: Full Warroom Browser/API Parity

Status: Done On Dev / Human Review Reviewed
Date: 2026-10-27
Parent: ../rfc/2026-10-27-warroom-full-api-parity.md
Owner: Codex
Human review: HR-2026-10-27-WARROOM-API (Reviewed by Svein Tore, 2026-10-27)

## Common Boundaries

Four sequential independently verified slices; no parallel implementation implied.
Preserve all contributor work, especially existing My Day Workday/Absence links.
Native source rights intersect Warroom rights; no provider activation or grants.
Each slice updates coverage/evidence and runs affected Dev tests before handoff.

## WR01: Source Authority And Pure Workspace Context

Status: Done
Goal/user-visible behavior: discover available authorized panels/actions through
GET /context; browser and API use shared source access and safe field projections.
Scope: pure queries, native actor checks, route foundation, read ability and context.
Out of scope: settings writes and source-domain writes.
Data touched: authorized read-only source queries; Integration ability metadata.
Permissions: warroom.view plus current source record/field/mailbox authority.
Tests: native/token denial/revocation, Calendar masking, mailbox scope, no GET writes,
safe integration state and source-unavailable behavior.
Documentation: shared-authority decision, source matrix and current evidence.
Done: route/context contracts pass; no broader access; contributor view work retained.

## WR02: Dashboard And Personal Queues

Status: Done
Goal/user-visible behavior: GET /dashboard, /my-day and /my-day/overdue provide every
current configured panel and personal/focus data with bounded typed results.
Scope: existing 8/8/12 previews, complete totals, exact personal filters, enabled
sections, Storage Should order, authorized lanes/actions and source-state metadata.
Out of scope: new dashboard design, arbitrary employee/date selection and source writes.
Data touched: source reads only.
Permissions: WR01 authority applied before totals/limits/serialization.
Tests: full versus capped counts, lifecycle exclusions, combined overdue ordering/
pagination, timezone boundaries, hidden/zero/denied/unavailable and web/API parity.
Documentation: complete W01-W03/W06 mapping and schemas.
Done: snapshots and navigation match effective web behavior and source rights.

## WR03: Settings Commands And Read-Back

Status: Done
Goal/user-visible behavior: GET/PUT /settings and GET /operations/{command} expose
all existing settings with safe browser/API concurrency and exact results.
Scope: common validation/action, revision on web/API, additive commands/locking
migration, required If-Match/Idempotency-Key, current-authority replay/read-back.
Out of scope: new settings or defaults, per-user panels and production migration.
Data touched: common_settings singleton and encrypted local command/locking rows.
Permissions: native warroom.manage_settings plus exact settings token ability.
Tests: numeric limits/sections, unknown fields, settings-only actor, stale browser/
API saves, rollback, key/body conflicts, replay after revocation and native races.
Documentation: conflict/retry operating procedure and exact migration.
Done: all fields persist/read back identically; concurrent writes cannot overwrite
stale state; no secret or another actor/token receipt is exposed.

## WR04: Contract And Operational Acceptance

Status: Done
Goal/user-visible behavior: all seven operations are documented and independently
verified, with usable employee guidance.
Scope: exact runtime/OpenAPI equality, real response schema validation, regression
suites, native database and trusted HTTPS checks; Knowledge source updates and
actual BookStack publication/read-back with employee access scope.
Out of scope: inferred human approval, Main/production promotion and provider sends.
Data touched: documentation/Knowledge and approved integration publication.
Permissions: publication uses existing authorized scope without grants.
Tests: Warroom plus affected Ticket/Task/Calendar/Storage/Email/Integration and other
source regressions; report any blocked/failed/skipped check separately.
Documentation: verification report, matrix, TODO, RFC/ADR, human-review entry;
public website handoff follows its process only if applicable, marked do not publish.
Done: every browser read/write/action mapped to runtime route, scope, OpenAPI,
meaningful tests and exact read-back. Handbook actual content/scope verified.
Dev/Main/production/human acceptance are distinct final states.

## Completed Verification

All four slices verified on authoritative Dev. Exact evidence, runtime/Swagger
mapping, tests, native/HTTPS results and handbook read-back are in
../plans/2026-10-27-warroom-api-verification.md. Manual HR checklist is Reviewed by Svein Tore (2026-10-27, "Godkjenner");
Main/production and commit/push are separate and were not performed.

## Named Human Acceptance (2026-10-27)

Svein Tore explicitly accepted the completed Dev handoff/checklist: "Godkjenner".
HR-2026-10-27-WARROOM-API is Reviewed. Individual browser scenarios were not
independently observed by the agent. Commit/push, Main promotion and production
deployment are separate actions and were not authorized or performed here.
