# Calendar operational API

Status: Implemented on Dev under approved RFC 2026-10-23-calendar-full-api-parity.
Human review: HR-2026-10-23-CALENDAR-API, Reviewed by Svein Tore on 2026-10-24; Main merge approved.
Production rollout and provider activation remain separately gated.
No production deployment, live provider writes or automatic activation were performed in this work.

## Ownership and authentication

Use Authorization: Bearer and Accept: application/json. Create explicit scopes under
Admin > Integrations > API. Scopes never grant domain, calendar, employee or provider ownership.
Calendar owns event/admin/availability actions; UserManagement owns own preferences and work plans;
Nextcloud owns configured global provider transport. Domain routes live in each module's routes.php.

The context and all GETs are pure reads. New installations report setup_required; an authorized
administrator explicitly calls POST /calendar/initialize with calendar.manage, current
calendar.manage_settings permission and Idempotency-Key. Existing additive bootstrap is retained.

## Event commands

Read /calendar/context and /calendar/events/{event} before editing. Event creation requires title,
starts_at and ends_at; an omitted calendar_id uses the actor's own personal calendar. Timezone
defaults to Europe/Oslo. Status/transparency/visibility defaults match the browser.

PUT and PATCH both retain legacy partial-update semantics. Omitted fields preserve current values;
description, location and meeting_url accept null to clear. participants null or [] clears local
participants. Participant arrays accept only email, optional name, participant_type=email, role
(required/optional/resource) and notify. A legacy delimited email string is normalized and validated.
No invitation, RSVP or delivery is implied. Caller source, creator, provider IDs, linked-record data
and recurrence edits are rejected. Daily/weekly/monthly creation and supported expansion remain.
Series timing/calendar changes are rejected by the shared action; use the owning supported workflow.

Supply the revision returned by the exact event GET to detect stale changes, including same-second
edits and child changes. Send Idempotency-Key on original event CRUD for safe retries. It remains
optional only for legacy compatibility. New admin/provider commands require 8-200 character keys.
A repeated actor/operation/key with different input/target returns 409. Replay reauthorizes and
projects current state; no private response snapshots are stored. Removed hard-deleted targets
return 409. Durable receipt identities survive activity-log cleanup.

Ordinary DELETE preserves 204. For scope=event or scope=series, POST
/calendar/events/{event}/delete-preview with scope and original_starts_at for one occurrence.
DELETE submits the same scope/start plus revision, preview and Idempotency-Key. Only a real
uncancelled occurrence may be removed. Other occurrences remain. Source-owned absence/work-plan
blocks are changed through their owning APIs; Calendar cannot approve payroll or bypass retention.

## Queries, privacy and completeness

Events support from/to (increasing, at most 93 days), timezone, calendar_id or calendars[],
ownership_groups[], only_mine, event_search, event_sort (starts_at/title/calendar/status),
event_direction, view (day/week/month/list), date, work_context_id and context_type.
Without view/date, the legacy next-30-days window is retained. Empty selection means all visible
calendars; an active ownership filter matching none returns no events. Explicit invisible IDs fail.

Mask private fields before search, sorting, counts and related objects. Raw linked models are never
serialized. List and detail share the same safe event projection, with actual occurrence times.
page/per_page bounds the event page (default 100, maximum 500); meta total/current_page/last_page
allows continuation. meta.complete=false and recurrence_limited=true signal the inherited series
expansion limit; do not claim a complete range in that state. Context reference lists cap at 100,
say whether complete, and provide paginated reference URLs. Visible calendars are paginated.

GET /calendar/availability requires calendar.free-busy.read plus calendar.view_free_busy,
calendar.view and target-calendar visibility. It accepts user_id, from/to (maximum 31 days),
timezone, duration (15-480 minutes) and limit (1-50). Output contains only authorized user/calendar
references and slot times. meta.result_kind=suggestions and meta.complete=false explicitly identify
this limited suggestion list as non-exhaustive. Stored/effective windows, recurrence, absence and busy checks are reused.
GET /calendar/availability/users supplies paginated active human references.

## Administration and own preferences

calendar.manage requires an active administrator plus calendar.manage_settings. Admin context and
paginated references list supported setting keys, calendars, users and roles. PATCH settings
requires revision; omitted settings remain. Creating shared containers and changing settings uses
a durable command key. User/role access supports the same levels and flags as browser settings.

For access upsert/revoke or archive, obtain /calendar/admin/calendars/{calendar}/preview with the
exact operation and changes/access_id; apply the same fields plus revision/preview and command key.
Stale or changed targets/choices fail. Workday retention prevents archiving protected calendars.
Access and admin persistence/validation is shared with the browser and serializes actual targets.

Own /users/me/calendar-preferences accepts only timezone/default_calendar_view/workday_start/
workday_end and revision. Theme/settings and weekly availability remain unchanged. Work plans
retain their existing own-human/Workday runtime, token, revision and source guards.

## Calendar-only Nextcloud operations

Choose an already configured global connection. Read context, paginated mappings, calendar-only
attempts and safe conflicts. Credentials, raw error strings, historical bodies and non-calendar
operations are never returned. Read scopes require the current administrator's nextcloud.sync
permission; health additionally uses the browser's nextcloud.connection_manage authority.

POST /nextcloud/calendar/connections/{connection}/preview selects operation=mapping,
remove_mapping, activation or sync with exact choices. Review provider counts, unsupported resources
and duplicate candidates. A complete collection inventory is read for this explicit POST; over
2000 resources is rejected rather than treated as complete. Each sync/activation selects 1-10
explicit mappings and one remote destination per local calendar. Unassigned mapping records may
remain configured but are ineligible for activation. Mappings must point to their active human
owner's personal calendar. Changing/removing mappings pauses automatic activation.

Apply mapping/activation/sync with unchanged fingerprint/expires_at/preview (10-minute lifetime)
and a durable command key. Scope/content changes invalidate previews. Activation saves consent
without starting sync. Pausing can stop queued automatic work. Mapping edits respect connection
and cross-connection Calendar leases. The API never invokes the full users/groups/files sync.

Sync and reconciliation return 202 plus attempt ID/status_url, not success. Read the exact attempt
until terminal. Queued jobs reauthorize actor/scope/owner before provider access. Request replay
returns the existing attempt. Dispatch failure becomes a failed attempt and releases its lease.
The existing minutely scheduler and queue worker remain independently required deployment parts.

For an open calendar conflict, POST conflicts/{conflict}/preview with bind_existing, keep_local
or keep_remote and optional exact event_id. Apply resolution/event_id/fingerprint/expires_at/preview.
Resolution is queued once, serialized by existing connection/Calendar leases, and rechecks actual
local fingerprint plus remote UID/href/ETag and duplicate inventory. Only exact supported simple
resources are eligible; recurrence, source-owned records, attendees and ambiguous identities remain
retained. Mapping direction and provider write mode apply. bind_existing requires equal content.

keep_local uses conditional PUT, committed unknown-delivery intent and exact GET read-back.
keep_remote preserves local ownership/children and applies only supported Calendar fields. Only a
verified result resolves the conflict and appends outcome evidence. Unknown PUT is never blindly
repeated: observe equal intended content and bind it, or explicitly reconcile remote content first.
No provider deletion, credential CRUD, Talk/user/group/file operation or privacy expansion is added.

## Method inventory

The machine contract is docs/plans/2026-10-24-calendar-api-contract.json. Its 43 methods cover all
25 matrix rows; fail-on-drift tests compare routes, scopes and generated OpenAPI, including PUT.

| Method | Path | Required scope |
|---|---|---|
| POST | /api/v1/calendar/admin/calendars | calendar.manage |
| DELETE | /api/v1/calendar/admin/calendars/{calendar} | calendar.manage |
| GET | /api/v1/calendar/admin/calendars/{calendar}/access | calendar.manage |
| POST | /api/v1/calendar/admin/calendars/{calendar}/access | calendar.manage |
| DELETE | /api/v1/calendar/admin/calendars/{calendar}/access/{access} | calendar.manage |
| POST | /api/v1/calendar/admin/calendars/{calendar}/preview | calendar.manage |
| GET | /api/v1/calendar/admin/references | calendar.manage |
| GET | /api/v1/calendar/admin/settings | calendar.manage |
| PATCH | /api/v1/calendar/admin/settings | calendar.manage |
| GET | /api/v1/calendar/availability | calendar.free-busy.read |
| GET | /api/v1/calendar/availability/users | calendar.free-busy.read |
| GET | /api/v1/calendar/context | calendar.read |
| GET | /api/v1/calendar/events | calendar.read |
| POST | /api/v1/calendar/events | calendar.create |
| DELETE | /api/v1/calendar/events/{event} | calendar.delete |
| GET | /api/v1/calendar/events/{event} | calendar.read |
| PATCH | /api/v1/calendar/events/{event} | calendar.update |
| PUT | /api/v1/calendar/events/{event} | calendar.update |
| POST | /api/v1/calendar/events/{event}/delete-preview | calendar.delete |
| POST | /api/v1/calendar/initialize | calendar.manage |
| GET | /api/v1/calendar/work-plan/blocks | calendar.work-plan.read |
| POST | /api/v1/calendar/work-plan/blocks | calendar.work-plan.write |
| DELETE | /api/v1/calendar/work-plan/blocks/{event} | calendar.work-plan.write |
| PATCH | /api/v1/calendar/work-plan/blocks/{event} | calendar.work-plan.write |
| GET | /api/v1/calendars | calendar.read |
| GET | /api/v1/nextcloud/calendar/connections | nextcloud.calendar.read |
| GET | /api/v1/nextcloud/calendar/connections/{connection} | nextcloud.calendar.read |
| POST | /api/v1/nextcloud/calendar/connections/{connection}/activation | nextcloud.calendar.activate |
| GET | /api/v1/nextcloud/calendar/connections/{connection}/attempts | nextcloud.calendar.read |
| GET | /api/v1/nextcloud/calendar/connections/{connection}/attempts/{attempt} | nextcloud.calendar.read |
| GET | /api/v1/nextcloud/calendar/connections/{connection}/conflicts | nextcloud.calendar.read |
| POST | /api/v1/nextcloud/calendar/connections/{connection}/conflicts/{conflict}/apply | nextcloud.calendar.reconcile |
| POST | /api/v1/nextcloud/calendar/connections/{connection}/conflicts/{conflict}/preview | nextcloud.calendar.reconcile |
| POST | /api/v1/nextcloud/calendar/connections/{connection}/health | nextcloud.calendar.health |
| GET | /api/v1/nextcloud/calendar/connections/{connection}/mappings | nextcloud.calendar.read |
| POST | /api/v1/nextcloud/calendar/connections/{connection}/mappings | nextcloud.calendar.mapping |
| DELETE | /api/v1/nextcloud/calendar/connections/{connection}/mappings/{mapping} | nextcloud.calendar.mapping |
| POST | /api/v1/nextcloud/calendar/connections/{connection}/preview | nextcloud.calendar.preview |
| POST | /api/v1/nextcloud/calendar/connections/{connection}/sync | nextcloud.calendar.sync |
| GET | /api/v1/users/me/calendar-preferences | users.calendar-preferences.read |
| PATCH | /api/v1/users/me/calendar-preferences | users.calendar-preferences.update |
| GET | /api/v1/users/me/work-plan | users.work-plan.read |
| PATCH | /api/v1/users/me/work-plan | users.work-plan.update |

## Deployment and rollback

Apply only the additive 2026_10_24_120000_create_calendar_api_command_receipts migration for this API
change. Existing calendar operations also require 2026_10_22_180000 lease tables from the prior sync
repair. Run scoped tests on Dev, generate Swagger, clear caches and restart affected workers during
a separately authorized rollout. Verify minutely schedule:run separately from queue:work.
No new UI build or package is required. Do not run unrelated pending migrations as part of this API.

Rollback disables new provider commands/activation first and retains receipts, mappings, conflicts,
attempts and unknown-write intent. Do not drop the receipt table after accepting real commands.
Publication of generated Swagger to production and actual MCP tool discovery are separate claims.
