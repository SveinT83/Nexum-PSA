# Warroom API Verification

Status: Done On Dev / Human Review Reviewed
Date: 2026-10-27
Owner: Codex
Approval: Svein Tore, "Ja godkjent", concrete RFC approved on 2026-10-27.
Human-review checklist: HR-2026-10-27-WARROOM-API (Reviewed by Svein Tore, 2026-10-27).
This change has not been committed/pushed, promoted to Main or deployed to production.

## Result

All four current Warroom browser operations (3 read, 1 write), the My Day overdue
variant and embedded navigation/actions have an official domain API counterpart.
The seven HTTP operations have 25 typed Swagger schemas. Full current operational
parity includes settings writes, source authorization, exact results and side effects;
it does not invent source-domain writes or customizable dashboards.

| Method | Path below /api/v1/warroom | Token ability | Native permission |
| --- | --- | --- | --- |
| GET | /context | warroom.read | warroom.view |
| GET | /dashboard | warroom.read | warroom.view |
| GET | /my-day | warroom.read | warroom.view |
| GET | /my-day/overdue | warroom.read | warroom.view |
| GET | /settings | warroom.settings.read | warroom.manage_settings |
| PUT | /settings | warroom.settings.manage | warroom.manage_settings |
| GET | /operations/{command} | warroom.settings.manage | warroom.manage_settings |

The current runtime/Swagger/scopes/test/read-back inventory is in
2026-10-27-warroom-api-contract.json and the adjacent coverage matrix.
GET/HEAD is counted once. Four browser rows and seven API operations were independently
read from the authoritative runtime; all seven successful responses were read over
trusted HTTPS and validated against the published schemas.

## Behavior And Boundaries

- Shared browser/API queries apply owning source rights before counts or row limits.
  Denied, unavailable, configured-hidden and authorized empty states remain distinct.
- My Day retains own open Ticket/Task filters, complete counts and 8/8/12 previews.
  Combined overdue work has stable bounded pagination; focus and dedicated API match.
- Calendar uses current visible calendars and native private-detail masking.
- Mail counts use ordinary owner/shared/delegated access and active provider placement.
  Emergency grants remain in Mail's audited use workflow and cannot widen routine KPIs.
  Missing mailbox grant/placement schema is unavailable, never a legacy broad fallback.
- Integration projection excludes raw errors, credentials and model internals.
  Unknown/unhealthy health does not become a fabricated healthy result.
- All seven settings fields and nine section choices use shared validation.
  Browser/API saves acquire the same permanent singleton, compare the exact revision
  and persist settings plus encrypted API receipt in one transaction.
- API writes require If-Match and an 8..200 character Idempotency-Key.
  Same request/key replays its original result; changed request gets 409, stale
  revision 412, missing prerequisites 428/503. Rights are current on replay/lookup.
- Receipt lookup is bound to the actor and original token. Token abilities never
  replace native permissions. Settings-only managers do not need dashboard rights.
- Existing Workday/Absence shortcuts are preserved and discoverable when enabled
  and authorized. Source write workflows remain in their owning APIs.

## Automated Verification

All terminal runs below passed with zero failures/skips; these runs overlap and
their totals must not be added together.

| Run | Tests | Assertions | Purpose |
| --- | ---: | ---: | --- |
| Final complete Warroom suite | 31 | 619 | Current final module, every operation and actual success/error response schemas |
| Warroom + Calendar + Storage + Mail delegation/emergency + production Swagger scan | 343 | 5934 | Broad affected-source regression and no-dev Swagger reflection |
| Warroom + TicketModule + TaskModule | 189 | 1773 | Owning Ticket/Task workflow regressions and shortcut/sidebar refinements |
| Warroom + Mail delegation/emergency after ordinary-mail boundary refinement | 45 | 807 | Final mail authorization, read purity and unavailable grant-schema regression |
| Missing settings tables + all seven successful operations | 2 | 54 | Controlled 503 responses and successful settings/receipt workflow |

The final 31-test suite includes the last availability regression and formatter pass.
The full application-wide suite was not run; this shared Dev checkout contains
independent active work. No relevant Warroom failure is deferred.
The emergency-mail regression was shown to fail before the ordinary-scope correction
(two mailboxes instead of one), then passed with the corrected boundary.

PHP syntax checks, scoped Pint and git diff whitespace checks pass.
New module directories/files are readable by PHP-FPM; CLI commands used umask 0002.

## Native MySQL And HTTPS

Only the exact additive migration
database/migrations/2026_10_27_110000_create_warroom_api_commands.php
was applied on Dev. No unrelated pending migrations were run.

Native MySQL verified outer transaction rollback, encrypted durable replay,
one receipt for a winning concurrent write and stale rejection of the competing
writer. The second writer waited 705 ms for the singleton, then returned 412;
the winner returned 200. Live settings values were preserved.

Trusted HTTPS at https://dev.nexumpsa.eu independently verified all seven operations,
actual response schemas and no-store headers, exact settings read-back/replay,
401 without authentication, 403 for missing scope and 412 for stale state.
The official published Swagger JSON route is /docs (HTTP 200), with six paths and
seven Warroom operations; /api/documentation is the Swagger UI.

Temporary bounded verification tokens and synthetic receipts were removed and
read back as absent. Native settings values remain unchanged; revision generations
advanced during same-value native/HTTPS verification.

See native-evidence.json, https-evidence.json and cleanup-evidence.json alongside
this report (each prefixed 2026-10-27-warroom-api-).

## Handbook And Knowledge

All three native Warroom source articles are published/internal and exact-match
the repository Markdown. Actual BookStack pages 443 and 444 were updated and
independently read back byte-for-byte after trimming. The API reference is included
in the existing Warroom Overview page, because the connector rejected valid normal
text when creating/searching pages; no duplicate page was created.

- Overview/API: https://doc.tronderdata.no/books/nexum-psa/page/warroom-overview
- Settings: https://doc.tronderdata.no/books/nexum-psa/page/warroom-settings

Existing page/chapter permission inheritance and book permissions were preserved.
The Editor role mapped to /staff-internal has book/chapter/page-view-all.
No BookStack/OpenWebUI permissions were changed. Employee OpenWebUI retrieval was
not tested and is a separate claim.

Native automatic BookStack sync is unconfigured and was not enabled. Actual
publication was performed through the connected BookStack tools; pending_push
metadata is not publication proof. Before any future automatic sync enablement,
the integration owner must reconcile source/page mappings, including the API
appendix, and must not push an unrelated pending backlog.

## Files And Deployment

Implementation is confined to app/Modules/Warroom, the exact additive migration
and three additive Warroom entries in the shared Integration ApiAbilityCatalog.
Controller logic moved into shared queries/actions; explicit resources and
OpenAPI contracts, tests, minimal view guards/revision input and Knowledge sources
were added/updated. Contributor My Day Workday/Absence links remain intact.
Other contributors' Integration/Swagger/route changes were preserved.

No frontend assets changed; no frontend build, queue/scheduler change, worker
activation, provider execution, mail/test sends or permission grants are required.
Future authorized deployment requires the exact migration, normal optimize:clear
and l5-swagger:generate; preserve APP_KEY and command records when recovering.
Do not roll back the command tables while clients depend on replay/read-back.

One public-safe editorial handoff item was appended and read back in the existing
Content/Visibility queue: NEXUM-WARROOM-API-2026-10-27. It is explicitly "Ikke publiser"
until Main promotion, production deployment and verification; human review is now accepted.

## Accepted Human-Review Scope And Separate Promotion

Svein Tore explicitly accepted the completed Dev handoff and checklist on
2026-10-27: "Godkjenner". HR-2026-10-27-WARROOM-API is Reviewed; its human-review
gate is cleared for the verified scope. This records his acceptance, not agent
observation of every manual scenario. Main/production actions still require
separate authorization. Production version and OpenWebUI retrieval are unverified.

1. Compare Dashboard and My Day in desktop/mobile layouts with source-authorized
   and restricted users; no protected counts, rows or dead destinations should appear.
2. Compare own counts, unread/overdue drill-downs, preview limits and overdue pages.
3. Confirm private Calendar details remain Busy and ordinary mail totals exclude
   emergency-only mailboxes; normal Mail emergency use retains its audit workflow.
4. Open two settings tabs, save one and reject/reload the stale second tab. Compare
   browser/API values, retry and receipt read-back with a bounded own test token.
5. Confirm existing Workday/Absence shortcuts and all nine panel choices.
6. Review Swagger and the published handbook; verify the actual production version
   separately before describing the feature as available there.

## Acceptance Record

Reviewer: Svein Tore. Date: 2026-10-27. Exact approval: "Godkjenner".
All 28 recorded module/source hashes still matched the verified snapshot when
approval was recorded. Only coordination documentation changed; no new code,
tests, migration, commit/push, Main promotion or production operation was performed.
The existing passing test/native/HTTPS/handbook evidence remains unchanged.
