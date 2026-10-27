# Nextcloud Browser/API Coverage Matrix

Status: Verified On Dev And Isolated Main Candidate
Date: 2026-10-27
Owner: Codex. Approver: Svein Tore.

Source: live Dev route:list plus actions/controller/Blade forms and effective native permission inspection.
19 browser rows; 14 protected calendar operations; 1 HMAC webhook. These are separate surfaces, not a percentage.
Paths use /api/v1/nextcloud prefix. The concrete verified route/scope/schema/native-permission/HTTP evidence for every row is in the matching JSON matrix.

| ID | Browser route/action | Type | Proposed API | Current coverage | Scope / slice |
|---|---|---|---|---|---|
| NC01 | `GET|HEAD tech/admin/system/nextcloud` / index | read | GET /connections; GET /context | Missing general list/context; calendar list is partial | nextcloud.connections.read / 1 |
| NC02 | `DELETE tech/admin/system/nextcloud/calendar-mappings/{mapping}` / destroyCalendarMapping | write | Existing DELETE /calendar/connections/{connection}/mappings/{mapping} | Existing shared action; exact connection/owner check | nextcloud.calendar.mapping / 2 |
| NC03 | `POST tech/admin/system/nextcloud/connections` / store | write | POST /connections | Missing | nextcloud.connections.write + conditional credentials.write / 1 |
| NC04 | `GET|HEAD tech/admin/system/nextcloud/connections/{connection}` / show | read | GET /connections/{connection}; GET .../context | Missing complete detail/lookups/general logs | nextcloud.connections.read / 1 |
| NC05 | `PATCH tech/admin/system/nextcloud/connections/{connection}` / update | write | PATCH /connections/{connection} | Missing; preserve absent PATCH fields | nextcloud.connections.write + conditional credentials.write / 1 |
| NC06 | `DELETE tech/admin/system/nextcloud/connections/{connection}` / destroy | write | DELETE /connections/{connection} | Missing | nextcloud.connections.write / 1 |
| NC07 | `POST tech/admin/system/nextcloud/connections/{connection}/calendars` / storeCalendarMapping | write | Existing POST /calendar/connections/{connection}/mappings | Existing shared action; verify exact browser fields/eligibility | nextcloud.calendar.mapping / 2 |
| NC08 | `POST tech/admin/system/nextcloud/connections/{connection}/check` / check | action | POST /connections/{connection}/health | Calendar health exists; general scope missing | nextcloud.health / 4 |
| NC09 | `POST tech/admin/system/nextcloud/connections/{connection}/folders` / storeFolderMapping | write | POST /connections/{connection}/folders | Missing | nextcloud.folders.write / 2 |
| NC10 | `PATCH tech/admin/system/nextcloud/connections/{connection}/folders` / updateFolders | write | PATCH /connections/{connection}/folders | Missing root/documents selection | nextcloud.folders.write / 3 |
| NC11 | `POST tech/admin/system/nextcloud/connections/{connection}/folders/auto-match` / autoMatchClientFolders | action | POST .../folders/auto-match/preview and /apply | Missing; deterministic/AI with local mapping writes | nextcloud.folders.write + existing AI execution authority / 3 |
| NC12 | `POST tech/admin/system/nextcloud/connections/{connection}/groups` / storeGroupMapping | write | POST /connections/{connection}/groups | Missing; roles/client context/direction | nextcloud.mappings.write / 2 |
| NC13 | `POST tech/admin/system/nextcloud/connections/{connection}/sync` / sync | action | POST /connections/{connection}/sync; preview and attempts | Calendar-only sync does not implement full sync | nextcloud.sync + conditional providers.execute / 4 |
| NC14 | `PATCH tech/admin/system/nextcloud/connections/{connection}/talk-bot` / updateTalkBot | write | PATCH /connections/{connection}/talk-bot | Missing | nextcloud.talk.write + conditional credentials.write / 4 |
| NC15 | `POST tech/admin/system/nextcloud/connections/{connection}/test-talk-bot` / testTalkBot | action | POST /connections/{connection}/talk-bot/test | Missing | nextcloud.talk.execute + providers.execute / 4 |
| NC16 | `POST tech/admin/system/nextcloud/connections/{connection}/users` / storeUserMapping | write | POST /connections/{connection}/users | Missing; all skip/map/import branches | nextcloud.mappings.write / 2 |
| NC17 | `DELETE tech/admin/system/nextcloud/folder-mappings/{mapping}` / destroyFolderMapping | write | DELETE /connections/{connection}/folders/{mapping} | Missing | nextcloud.folders.write / 2 |
| NC18 | `DELETE tech/admin/system/nextcloud/group-mappings/{mapping}` / destroyGroupMapping | write | DELETE /connections/{connection}/groups/{mapping} | Missing | nextcloud.mappings.write / 2 |
| NC19 | `DELETE tech/admin/system/nextcloud/user-mappings/{mapping}` / destroyUserMapping | write | DELETE /connections/{connection}/users/{mapping} | Missing | nextcloud.mappings.write / 2 |

## Embedded workflows

| ID | Workflow | Type | Planned contract |
|---|---|---|---|
| NCE01 | Folder browser navigation and bounded live metadata | read | GET /connections/{connection}/files?path=...; configured roots, feature checks and TLS |
| NCE02 | Scoped form choices and action availability | read | GET /context and /connections/{connection}/context |
| NCE03 | General sync logs and exact attempts | read | GET /connections/{connection}/attempts and /attempts/{attempt}; sanitized evidence |
| NCE04 | Mapping collection and exact item read-back | read | GET .../{connection}/users|groups|folders and /{mapping}; existing calendar mapping reads |
| NCE05 | Full sync identity/contact/calendar effects | action | General sync preview/execute/status, source-owned writes and leases |
| NCE06 | Scoped user skip, map existing and import | write | All existing POST user-mapping payload branches and exact read-back |
| NCE07 | Write-only service/bot secret rotation | write | Connection/Talk PATCH with additional credentials.write grant; omitted values unchanged |
| NCE08 | Own command receipt read-back | read | GET /operations/{operation}; fresh actor/token/record checks |

## Existing calendar compatibility inventory

| Method | URI | Ability |
|---|---|---|
| GET|HEAD | `api/v1/nextcloud/calendar/connections` | nextcloud.calendar.read |
| GET|HEAD | `api/v1/nextcloud/calendar/connections/{connection}` | nextcloud.calendar.read |
| POST | `api/v1/nextcloud/calendar/connections/{connection}/activation` | nextcloud.calendar.activate |
| GET|HEAD | `api/v1/nextcloud/calendar/connections/{connection}/attempts` | nextcloud.calendar.read |
| GET|HEAD | `api/v1/nextcloud/calendar/connections/{connection}/attempts/{attempt}` | nextcloud.calendar.read |
| GET|HEAD | `api/v1/nextcloud/calendar/connections/{connection}/conflicts` | nextcloud.calendar.read |
| POST | `api/v1/nextcloud/calendar/connections/{connection}/conflicts/{conflict}/apply` | nextcloud.calendar.reconcile |
| POST | `api/v1/nextcloud/calendar/connections/{connection}/conflicts/{conflict}/preview` | nextcloud.calendar.reconcile |
| POST | `api/v1/nextcloud/calendar/connections/{connection}/health` | nextcloud.calendar.health |
| GET|HEAD | `api/v1/nextcloud/calendar/connections/{connection}/mappings` | nextcloud.calendar.read |
| POST | `api/v1/nextcloud/calendar/connections/{connection}/mappings` | nextcloud.calendar.mapping |
| DELETE | `api/v1/nextcloud/calendar/connections/{connection}/mappings/{mapping}` | nextcloud.calendar.mapping |
| POST | `api/v1/nextcloud/calendar/connections/{connection}/preview` | nextcloud.calendar.preview |
| POST | `api/v1/nextcloud/calendar/connections/{connection}/sync` | nextcloud.calendar.sync |

## Completion evidence

For each row record implemented runtime route, static OpenAPI operation/schema, effective native
permission, explicit token grant, positive/negative/stale/replay HTTP tests and exact read-back.
Include embedded workflows, all legacy calendar operations, and independent webhook regressions.
No row is complete merely because a read-only metadata endpoint exists.
Shared controller/action logic must preserve all existing branches and actual side effects.
No real provider/test-message/customer import is authorized by this planning inventory.

## Verified Completion (2026-10-27)

All 19 browser rows and 8 embedded workflow groups have implemented shared/native actions
and typed read/write/action coverage. 50 HTTP operations = 36 full-domain additions + 14
compatible calendar operations. All 50 have actual successful response/schema evidence;
each new operation includes native/token authorization and documented typed errors.
47 new concrete response/component schemas; request schemas are inline static attributes.
Dev affected: 373 / 13899; isolated Main: 332 / 8889. Final Nextcloud/Talk set after scoped
privacy handling: 120 / 6460 on both. Totals overlap and must not be added.
Exact native MySQL lock/uniqueness/encrypted read-back/rollback and trusted HTTPS pass.
Synthetic inactive HTTPS object was deleted/replayed; temporary token revoked. No live
provider/calendar/customer mutation. Human checklist remains Pending; Main merge authorized.
