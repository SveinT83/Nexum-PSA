# Calendar browser/API coverage matrix

Date: 2026-10-23
Status: Done On Dev for all 25 current workflow rows, 2026-10-24. Human review remains In Review.
Owner: Codex. RFC: ../rfc/2026-10-23-calendar-full-api-parity.md.
Machine inventory: 2026-10-23-calendar-api-inventory.json.
Baseline: authoritative Dev working copy; Main 7caa07b376b268e5a928ec683b88b9ba18335d10.
Prior production read-only inspection confirmed the core event/work-plan routes and Swagger paths.
This matrix is not a live-provider write test or a claim of completed full parity.

## Calendar and consuming owners

| ID | Supported workflow / authoritative source | Baseline API | Required target outcome |
|---|---|---|---|
| CAL-01 | GET /tech/calendar; CalendarController::index, CalendarOverlayQuery | Lists visible calendars/events | Typed context with effective settings, current actor capabilities, eligible references and source-owned edit paths; equivalent calendar discovery. |
| CAL-02 | Day/week/month/list ranges, timezone, date navigation | Only from/to, timezone and one calendar_id | Same bounded range, multi-calendar selection, owner groups/only_mine, event search/sort and empty-filter semantics. Private data stays masked before search/count/sort. |
| CAL-03 | Calendar event detail and ownership badges | GET event resource | Consistent detail/list projections, participants/recurrence/ownership/source capabilities and explicit completeness; no hidden private fields in counts or related objects. |
| CAL-04 | StoreCalendarEvent: create local event and participants | POST event exists | Same actor permission plus record authorization, strict nested fields, idempotency support and exact saved result. Do not accept caller source/external IDs/created_by. |
| CAL-05 | UpdateCalendarEvent: partial event edits, target-calendar permission and participants | PUT/PATCH exists | Explicit omitted/null semantics, both source/target checks, stale-write protection and typed read-back; reject ignored recurrence fields. |
| CAL-06 | Tech destroy: scope=event with original_starts_at creates cancelled occurrence exception | API deletes event without occurrence scope | Shared authoritative delete command; cancel only the exact expanded occurrence, preserving series and other occurrences. |
| CAL-07 | Tech destroy: scope=series deletes the series and its event records | API lacks scope=series | Same series cancellation/removal behavior, guarded selection/preview and replay-safe result/tombstone. Preserve ordinary DELETE compatibility. |
| CAL-08 | Daily/weekly/monthly recurrence at creation and bounded recurrence expansion | Creation accepts recurrence; update action does not change recurrence definitions | Document exact supported recurrence contract; implement actual creation/read/occurrence/series deletion parity. Do not promise unsupported series editing or arbitrary RRULE support. |
| CAL-09 | FindAvailableSlots / Find Time panel for selected active employee | No endpoint | Authorized bounded available-slot query with timezone/duration/windows and safe free/busy results; no private titles or provider IDs. |
| CAL-10 | CalendarSettingsController::index/update | No settings API | Typed GET/PATCH settings through shared actions with existing admin + calendar.manage_settings authority; no arbitrary settings-key write. |
| CAL-11 | CalendarSettingsController::storeCalendar/destroyCalendar | Only calendar list | Create and archive supported shared/team/company/resource/absence/shift/system/external containers, same validation and retained-source guards; exact result/read-back. |
| CAL-12 | CalendarSettingsController::storeAccess/destroyAccess | No sharing API | List/upsert/revoke existing user/role access levels and flags with explicit scope, existing admin policy, affected-calendar preview and audit. Token alone never confers sharing rights. |
| CAL-13 | UserManagement ProfilePreferencesController / UpdateUserPreferences | No calendar-preferences counterpart identified | Own timezone/default view/workday-window read/update through a typed UserManagement-owned calendar subset; preserve unrelated theme and profile fields. |
| CAL-14 | UserManagement WorkPlanController | GET/PATCH users/me/work-plan present | Reuse current own-human/Workday-enabled contract and its revision/field guards; no second owner or broad employee override. |
| CAL-15 | Calendar WorkPlanBlockController / ManageWorkPlanBlock | Four operations already present | Preserve own-only employee gates, request_id/revision/scope semantics and source-owned behavior; verify and document exact parity instead of duplicating endpoints. |
| CAL-16 | WorkdayAbsence projection and retention; consumer links through LinkCalendarEvent | Some source metadata; generic event editor is blocked for protected sources | Preserve Workday ownership/retention and safe source action references; no generic source/linked-model mutation, absence edit or payroll approval through Calendar. |
| CAL-17 | EnsureCalendarDefaults used by current reads | GET can initialize settings, permissions and personal calendar | Separate query projection from explicit guarded bootstrap/onboarding; read-only token never creates data or grants permissions. Preserve setup through shared supported initialization and safe setup-required context. |
| CAL-18 | Core API method registration and docs | PUT and PATCH registered; Swagger only PATCH; schemas partly descriptive | Exact method/path/schema/ability/actor/side-effect inventory, both update methods, typed errors and fail-on-drift tests. |

## Calendar-specific Nextcloud operations

| ID | Current authoritative behavior | Baseline API | Proposed owned contract |
|---|---|---|---|
| NC-01 | Admin connection page selects server and shows calendar mappings, latest logs/conflicts | No protected Nextcloud endpoints | Calendar-safe connection/context/status read with scoped mapping options, sanitized health and actionable blocked reasons. Never expose app passwords or unrelated user/group/file preview. |
| NC-02 | storeCalendarMapping upserts; destroyCalendarMapping removes mapping | Browser only | Typed list/upsert/remove mapping API through shared Nextcloud actions; existing admin/nextcloud.sync policy, local owner/direction checks, provenance and automatic-activation invalidation. |
| NC-03 | Existing health-check action | Browser only | Explicit health-check command (not GET), sanitized read-back and no full connection sync as health probe. TLS validation remains mandatory. |
| NC-04 | RunNextcloudCalendarSync manual path and durable dispatcher/worker | No operational API | Explicit calendar-only sync request, attempt receipt/status/read-back, same leases, conditional ETags, duplicate/unsupported-resource protection and no user/group/file work. The existing full-connection Sync now action retains its documented broader scope. |
| NC-05 | nextcloud:sync-calendars CLI enable/disable; reviewed mapping fingerprint | CLI only | Explicit preview/apply pause/resume/activate API for reviewed calendar targets; same saved consent/fingerprint and lease rules. Merely exposing the endpoint must not activate any connection. |
| NC-06 | Conflict log and unknown delivery intent; no conflict-resolution UI/action | Retained evidence, no guarded public resolver | Read safe conflict evidence and implement explicit preview/apply reconciliation for bind-existing/keep-local/keep-remote only when the exact current resource is supported. Reauthorize, re-read UID/href/ETag/local fingerprint and reject ambiguity or stale preview; no unconditional PUT or direct metadata-status patch. |
| NC-07 | Global connection service configuration is shared with Talk/users/groups/files | No protected API | Calendar context selects an already authorized configured connection. General server credential CRUD, Talk, group/user/file and global connection deletion are excluded from this Calendar RFC and must not be smuggled into a calendar action. |

## Existing dependencies, not fabricated coverage

- Discussion #173 (Calendar: scheduling, dispatch and availability upgrade) is the existing future
  scheduling goal map. Its holds/dispatch/crew/resource/override APIs enter their approved product
  slices when implemented; do not expose stubs or claim this current parity project builds them.
  Preserve the existing approved Nextcloud owner; the Discussion's future provider abstraction
  does not move current transport ownership without a separate architectural approval.

- Calendar iMIP/invitation/RSVP/OOO is already owned by the accepted 2026-08-16 ADR and queued
  Mail Feature Slice with HR-2026-08-16-029. Do not reimplement it or expose fake accepted/sent
  operations. Context reports unavailable with its dependency and eventual official API owner.
- Provider recurrence remains retained/skipped under the approved 2026-10-22 sync RFC. Calendar
  API parity does not authorize enabling a lossy provider series writer or silently adding a parser.
- Ordinary local participant records are not proof of a sent invitation, RSVP or recipient delivery.
- Booking, Ticket, Task and Workday consume Calendar through guarded contracts; API parity must
  preserve their availability, linked-record visibility, projection and retention behavior.
- API/OpenAPI completeness and live MCP tool availability are separate evidence. Do not claim a
  published MCP tool before actual discovery and authorized invocation/read-back are verified.

## Acceptance rule

Every applicable row must have a named implemented route/action, request/response/error schema,
scope plus actor/object/field authorization, meaningful allow/deny and replay tests, and documentation.
Existing operations and required writes/actions are mandatory; read-only coverage is not parity.
Explicitly dependency-gated future product features are reported as unavailable, not counted as
implemented or used as a reason to omit an already supported browser operation.


## Implemented verification (2026-10-24)

The baseline columns above describe the pre-change gaps. Every row is now implemented on Dev.
The frozen contract is 2026-10-24-calendar-api-contract.json (43 HTTP methods), with current owning
actions, scopes, request/response schemas and response codes. CalendarApiContractTest fails on route,
scope or generated OpenAPI drift. Evidence: 2026-10-24-calendar-api-verification.md.

| Row | Implemented method(s) |
|---|---|
| CAL-01 | GET /api/v1/calendar/context; GET /api/v1/calendars |
| CAL-02 | GET /api/v1/calendar/events |
| CAL-03 | GET /api/v1/calendar/events/{event} |
| CAL-04 | POST /api/v1/calendar/events |
| CAL-05 | PUT /api/v1/calendar/events/{event}; PATCH /api/v1/calendar/events/{event} |
| CAL-06 | POST /api/v1/calendar/events/{event}/delete-preview; DELETE /api/v1/calendar/events/{event} |
| CAL-07 | POST /api/v1/calendar/events/{event}/delete-preview; DELETE /api/v1/calendar/events/{event} |
| CAL-08 | POST /api/v1/calendar/events; GET /api/v1/calendar/events |
| CAL-09 | GET /api/v1/calendar/availability |
| CAL-10 | GET /api/v1/calendar/admin/settings; PATCH /api/v1/calendar/admin/settings |
| CAL-11 | POST /api/v1/calendar/admin/calendars; DELETE /api/v1/calendar/admin/calendars/{calendar} |
| CAL-12 | GET /api/v1/calendar/admin/calendars/{calendar}/access; POST /api/v1/calendar/admin/calendars/{calendar}/preview; POST /api/v1/calendar/admin/calendars/{calendar}/access; DELETE /api/v1/calendar/admin/calendars/{calendar}/access/{access} |
| CAL-13 | GET /api/v1/users/me/calendar-preferences; PATCH /api/v1/users/me/calendar-preferences |
| CAL-14 | GET /api/v1/users/me/work-plan; PATCH /api/v1/users/me/work-plan |
| CAL-15 | GET /api/v1/calendar/work-plan/blocks; POST /api/v1/calendar/work-plan/blocks; PATCH /api/v1/calendar/work-plan/blocks/{event}; DELETE /api/v1/calendar/work-plan/blocks/{event} |
| CAL-16 | GET /api/v1/calendar/events/{event}; PATCH /api/v1/calendar/events/{event}; DELETE /api/v1/calendar/events/{event} |
| CAL-17 | GET /api/v1/calendar/context; POST /api/v1/calendar/initialize |
| CAL-18 | PUT /api/v1/calendar/events/{event}; PATCH /api/v1/calendar/events/{event} |
| NC-01 | GET /api/v1/nextcloud/calendar/connections; GET /api/v1/nextcloud/calendar/connections/{connection}; GET /api/v1/nextcloud/calendar/connections/{connection}/conflicts |
| NC-02 | POST /api/v1/nextcloud/calendar/connections/{connection}/preview; GET /api/v1/nextcloud/calendar/connections/{connection}/mappings; POST /api/v1/nextcloud/calendar/connections/{connection}/mappings; DELETE /api/v1/nextcloud/calendar/connections/{connection}/mappings/{mapping} |
| NC-03 | POST /api/v1/nextcloud/calendar/connections/{connection}/health |
| NC-04 | POST /api/v1/nextcloud/calendar/connections/{connection}/sync; GET /api/v1/nextcloud/calendar/connections/{connection}/attempts; GET /api/v1/nextcloud/calendar/connections/{connection}/attempts/{attempt} |
| NC-05 | POST /api/v1/nextcloud/calendar/connections/{connection}/preview; POST /api/v1/nextcloud/calendar/connections/{connection}/activation |
| NC-06 | POST /api/v1/nextcloud/calendar/connections/{connection}/conflicts/{conflict}/preview; POST /api/v1/nextcloud/calendar/connections/{connection}/conflicts/{conflict}/apply |
| NC-07 | GET /api/v1/nextcloud/calendar/connections/{connection} |
