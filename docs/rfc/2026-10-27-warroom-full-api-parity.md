# RFC: Full Warroom Browser/API Parity

Status: Approved
Date: 2026-10-27
Owner: Codex
Product owner and intended human reviewer: Svein Tore
Classification: Level 3 API and shared authorization change

## Context

Svein Tore requested: "Warroom domain, vi trenger 100% API støtte".
Authoritative implementation is /var/Projects/tdPSA on Dev. Fresh route and source
inspection found four owned browser HTTP operations (3 read, 1 write), zero
Warroom API operations, plus embedded My Day overdue focus, navigation and panel
settings. The current Warroom suite passes 15 tests / 103 assertions.
This is baseline evidence; no API implementation or full coverage is claimed.

No open Warroom GitHub Issue was found in the scoped live search. Historical
navigation #202, My Day #208 and Storage warning #183 define existing behavior.
Keep the contributor's four-line My Day Workday/Absence shortcut change intact.
Other domain implementations and shared Integration/Swagger edits are independent.

## Goals

Expose every supported current Warroom read, write and administrative operation,
with the same effective actor rights in web and API, bounded typed responses,
exact read-back, discoverable capabilities, validation, Swagger/OpenAPI, meaningful
tests and verified employee documentation. Count totals before preview limits.
Include all enabled dashboard panels, personal queues, unread/overdue counts,
combined overdue work, Storage Should order and authorized navigation/actions.

## Non-Goals

No new customizable dashboard, new source-domain writes or workflow ownership.
Ticket, Task, Calendar, Mail, Workday and other action links keep their existing
domain API and authorization; Warroom exposes authorized destinations/capabilities.
No provider execution, mail sends, grants, scheduler changes, production activation,
commit/push or Main merge/deployment is authorized by the current request.

## Current Behavior

WarroomController directly counts cross-domain tables and projects recent records.
Only the Storage warning explicitly checks its owning domain permission. Calendar
dashboard rows are direct table reads; My Day has personal owner/assignee/event
membership filters but does not apply all source-domain field/access projections.
Integration health can include raw last_error text. Links commonly check route
existence rather than the actor's ability to use their destinations.

My Day returns complete personal counts with previews capped at 8 tickets, 8 tasks
and 12 events. focus=overdue returns combined assigned open Ticket/Task work.
Settings are global common_settings type=warroom/name=dashboard: two 1..168 hour
windows, four 1..20 list limits and at least one of nine enabled_sections keys.
The web guards are warroom.view and warroom.manage_settings under the Tech surface.

## Proposed Change

Implement seven typed HTTP operations under /api/v1/warroom:

| Method | Path suffix | Purpose | Token ability / native authority |
| --- | --- | --- | --- |
| GET | /context | Available panels, source states, defaults, authorized links/actions | warroom.read / warroom.view |
| GET | /dashboard | All configured authorized dashboard projections | warroom.read / warroom.view plus source authority |
| GET | /my-day | Own queues/counts/window and optional focus=overdue | warroom.read / warroom.view plus source authority |
| GET | /my-day/overdue | Bounded paginated combined overdue list and full total | warroom.read / warroom.view plus source authority |
| GET | /settings | Current global settings, section choices and revision | warroom.settings.read / warroom.manage_settings |
| PUT | /settings | Validated full replacement and exact persisted result | warroom.settings.manage / warroom.manage_settings |
| GET | /operations/{command} | Own durable settings-command result | original write ability and native authority |

All definitions belong in Warroom/routes.php, with a loader-only api.php compatible
with the current API loader. API controllers/resources/actions/queries stay in the
module. Add catalog entries only alongside working routes/tests; use explicit read
and write classifications. Reject unknown fields, actor overrides and unsupported
filters. Keep the current My Day date semantics; do not invent arbitrary date/user
selection. Document timezone, generated_at, window, stable ordering and limits.

Approval explicitly includes correcting the current cross-domain projection gap
through shared web/API queries: warroom.view alone cannot disclose source data.
Use native record/field rules, CalendarVisibility/CalendarOverlayQuery and the
read-only MailboxAccess scope path without causing read-state or entitlement writes.
My Day keeps its personal filter in addition to source authorization. Dashboard
retains its operational/global intent only over records the actor can actually read.
Preserve Calendar masking, Mailbox scope and protected fields; never serialize raw
Eloquent models or raw provider exception/credential text. Emit structured safe
health states. Unauthorized sections expose no counts/records/URLs. Authorized
empty, hidden-by-setting, inaccessible and unavailable sources are distinct states;
an unavailable source must not become a fabricated healthy/zero result.
API tokens narrow the actor and never grant native domain authority. Follow-through
on source-domain action URLs requires that domain's own API token abilities.

Extract the settings validator/action for both surfaces. Settings writes require
Idempotency-Key and If-Match against a deterministic singleton revision. Serialize
browser and API writes through the same singleton lock; the browser also submits
its revision. Persist command, result and settings atomically. Same key/body
replays the original result; changed body/key or stale revision fails without a
second mutation. Recheck current actor/token authority on replay/read-back.
Receipts are encrypted, actor/token-bound and exclude secrets. GET reads stay pure.

## Impact Analysis

Warroom controllers/queries/actions/resources/requests/routes/tests and minimal
Blade capability/conditional rendering and revision submission; Integration ability
catalog; API loader; Swagger scan/contracts. Read dependencies: Ticket, Task,
Calendar, Storage, Email, Clients, Commercial, Sales, Economy, Asset, Knowledge,
Integration, Nextcloud and Notification. Workday shortcuts stay conditional.
No source-domain schema or ownership changes, new packages or runtime activation.
Consult docs/ui-guidelines.md before the approved minimal view changes.
Risk: narrower shared projections can change counts/visible panels for actors
without source access; this is an intentional decision requiring RFC approval.

## Data And Migration Plan

One additive Warroom settings-command/locking migration; existing business settings
stay in common_settings, with no backfill or default changes. Verify transactions,
singleton initialization, uniqueness and competing writes on native Dev MySQL.
Do not run unrelated pending migrations. Review docs/human-review.md before the
exact migration. Preserve command data and APP_KEY when rolling back/recovering.
After implementation: normal cache clear and Swagger generation; a frontend build
only if assets actually change. Production migration/deployment is a separate gate.

## Testing Plan

Baseline PASS: 15 tests / 103 assertions on authoritative Dev.
Cover all seven operations with real bearer tokens, native permission denial,
inactive/portal-only actors, revocation, settings-manager-only discovery, unknown
fields and allowed ranges. Verify source authorization before counts, Calendar
masking, mailbox isolation, read purity, safe health projection, hidden sections,
unavailable schemas/sources, totals before caps and stable overdue pagination.
Verify own-ticket/task lifecycle and consistent totals/focus, timezone boundaries,
settings validation, exact persisted values, stale browser/API writes, durable
replay, changed body, cross-actor/token lookup denial and native concurrent writes.
Compare every runtime route/method to Swagger and validate successful and error
responses against typed schemas. Run affected source-domain/browser/Integration
regressions, trusted HTTPS smoke/read-back and native database verification.
Passing baseline tests or adding routes is not sufficient to claim 100% parity.

## Documentation Plan

Maintain adjacent coverage matrix and baseline, this RFC, ADR, slices, TODO and
human-review checklist HR-2026-10-27-WARROOM-API. Update Warroom overview/settings
Knowledge sources and add API usage documentation with exact menu/button names,
permissions, examples, expected results and troubleshooting. Publish/sync affected
articles through the supported integration and independently read back actual
BookStack content and employee access. State Dev/Main/production separately;
employee OpenWebUI retrieval is a separate claim. Planning creates no handbook change.

## Sequential Feature Slices

WR01: Shared pure source-authorized projections, context and API foundations.
WR02: Dashboard/My Day/overdue endpoints and bounded typed results.
WR03: Shared settings validation, locking, revisions and durable command read-back.
WR04: Exact contracts, regressions, native/HTTPS checks and verified handbook.

## Open Questions

None. Shared source-authorization correction and all four slices approved.

## Approval

Svein Tore explicitly approved this concrete RFC on 2026-10-27: "Ja godkjent".
This authorizes WR01-WR04 on authoritative Dev, including the exact additive Dev
migration and necessary verification/documentation. It does not mark human
scenarios Reviewed or authorize commit/push, Main merge or production deployment.

## Implementation Verification (2026-10-27)

WR01-WR04 Done On Dev. Current runtime: 4 browser operations, 7 API operations,
25 typed schemas. Final domain suite 31/619; affected groups 343/5934, 189/1773,
45/807 pass with no failures/skips. Native MySQL concurrency, encrypted replay/
rollback, all seven trusted HTTPS/schema read-backs and actual handbook verified.
Emergency mailbox grants remain in Mail's audited workflow, outside routine counts.
See ../plans/2026-10-27-warroom-api-verification.md and adjacent evidence.
HR-2026-10-27-WARROOM-API is Reviewed by Svein Tore (2026-10-27, "Godkjenner"). No commit/push or Main/production action.

## Named Human Acceptance (2026-10-27)

Svein Tore explicitly accepted the completed Dev handoff/checklist: "Godkjenner".
HR-2026-10-27-WARROOM-API is Reviewed. Individual browser scenarios were not
independently observed by the agent. Commit/push, Main promotion and production
deployment are separate actions and were not authorized or performed here.
