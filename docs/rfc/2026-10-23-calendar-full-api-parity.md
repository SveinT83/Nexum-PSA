# RFC: Full Calendar Browser And API Coverage

Status: Approved / Done On Dev - Svein Tore approved 2026-10-24 in chat; API acceptance reviewed by Svein Tore; Main promotion approved 2026-10-24.
Date: 2026-10-23
Owner: Codex. Product/privacy approval and release owner: Svein Tore.
Change level: Level 3 - API, authorization, Calendar/UserManagement/Nextcloud contracts.
Evidence: ../plans/2026-10-23-calendar-api-gap-matrix.md and ../plans/2026-10-23-calendar-api-inventory.json.
Human-review checklist: HR-2026-10-23-CALENDAR-API (In Review).

## Context

Svein requests full Calendar API coverage after the confirmed API gaps. This is the complete
operational target: all supported browser reads, writes, actions and administrative workflows,
with equivalent actor/record/field authorization and useful API context/read-back. It is not a
read-only interface or a restricted subset presented as full coverage.

The current Calendar API covers event CRUD, visible calendars and own work-plan blocks. Browser
filters/Find Time, administrative settings/containers/access, occurrence/series cancellation
and calendar preferences are not fully covered. Some validated fields can be ignored by the
underlying action. GET currently invokes initialization that may create settings, permissions
and personal calendars. Those are explicit contract gaps, not assumptions about missing models.

The previous Nextcloud sync repair is Done On Dev and merged to Main through PR #309. Its live
production pilot remains dependent on separate release/activation authorization and named human
review, HR-2026-10-22-NEXTCLOUD-CALENDAR-SYNC. No finishable code work under that request is being
abandoned. This request establishes the next Calendar/API workstream while those external gates
remain owned by Svein. Existing dirty Workday, Calendar and other module work must be preserved.

Current docs/GitHub Issue/Discussion inspection found no existing Calendar full-parity RFC/Issue.
Discussion #33 concerns ownership indicators already implemented. A full paginated search read all
54 Discussions and identified #173, Calendar: scheduling, dispatch and availability upgrade
(https://github.com/SveinT83/Nexum-PSA/discussions/173). Its body was read; it has no comments.
It remains the future scheduling goal map: dispatch, holds, crews/resources, overrides and PWA.
This RFC completes today's operational Calendar API foundation without treating that product idea
as approval to build the dispatch system. Future implemented scheduling actions must extend the
same API/matrix from their owning approved slices. The invitation/OOO plan below is an existing
dependency, not a duplicate Calendar API project.

Discussion #173 proposes Integration ownership for future external providers. Current approved
Nextcloud sync RFC/ADR and implementation own the existing Nextcloud transport/leases/mappings.
This API RFC preserves that approved owner; moving it to Integration requires a separate approved
architecture decision and is not silently inferred from an unimplemented Discussion.

## Goals

1. Every supported Calendar browser operation has a typed official API with the same current
   actor/role/domain permission, Calendar/Work Context/field authorization and side effects.
2. A client can discover available calendars, effective settings/preferences, eligible targets,
   safe source actions and capability/readiness reasons without scraping HTML or guessing IDs.
3. Cover all create/update/delete/actions, including single recurring occurrence versus whole
   series, calendar administration/sharing and bounded available-slot queries.
4. Expose calendar-specific Nextcloud mapping/sync/status/activation/reconciliation operations
   through Nextcloud-owned actions without broad unrelated synchronization or shared credentials.
5. Preserve private masking, ownership, Workday projections, existing recurrence bounds and
   unsupported-provider protection; API availability alone grants or activates nothing.
6. Accurate OpenAPI and deterministic coverage tests map every method/path and supported browser
   behavior to the official contract, including honest limits, completeness and error states.
7. Documentation, BookStack read-back, affected-module tests and explicit human verification
   are part of each completed implementation, not postponed until an assumed future release.

## Non-Goals

No new dispatch/holds/crew/resource-matching product from Discussion #173, arbitrary RRULE editor,
public calendar-sharing links, unrestricted
generic model/SQL/tool dispatcher, provider credential-sharing interface or user impersonation.
No payroll approval, Workday absence ownership transfer or consumer-domain rewrite.
No Talk, file browser, group/user/contact synchronization or general Nextcloud connection credential
CRUD under a Calendar-only API. Existing authorized connections remain owned by Nextcloud.

Invitations, RSVP, organizer sends and OOO stay under the accepted
../adr/2026-08-16-email-calendar-imip-and-out-of-office-boundary.md and queued
../feature-slices/2026-08-16-email-mail-calendar-invitations-out-of-office.md.
Their current dependency gates and HR-2026-08-16-029 are not bypassed. They must gain their official
API with the owning implementation; this RFC does not fabricate a working API before that exists.

No commit/push, new Main merge, administrator override, production deploy/activation or real
calendar mutation is authorized by this design proposal. Earlier PR #309 approval is scope-specific.

## Planning Baseline Behavior And Gaps

The attached matrix is the detailed source for endpoint and field coverage. Key observations:

- Calendar API exposes seven core HTTP operations and four own-work-plan block operations.
- Browser DELETE distinguishes ordinary event, one recurring occurrence and entire series;
  current API DELETE only deletes the event record.
- Browser settings administer system defaults, shared containers and user/role access; no APIs.
- Browser offers Find Time, multiple calendars, owner groups/only_mine and masked event search/sort.
- UserManagement owns calendar-related preferences and own work-plan; do not relocate that owner.
- Current core APIs use token abilities but must also match the browser's module permissions and
  record access. A broad token or owned calendar alone is not a replacement for those checks.
- PUT is registered but omitted from Swagger. Existing schemas lack parts of the actual field,
  occurrence/series, runtime-readiness, negative-case and read-back contracts.
- Existing default initialization in GET needs an explicit bootstrap/query boundary.
- Nextcloud has no protected operational API; automatic calendar sync is separately default-off.

## Proposed Change

### Authoritative ownership and route registration

Calendar owns local scheduling, recurrence/occurrence behavior, availability and Calendar access.
UserManagement owns own preferences/working hours; Workday owns absence projections and retention.
Nextcloud owns provider mappings, connection health, leases, attempt logs, activation and conflicts.
Integration owns ability discovery and API/OpenAPI publication.

Define domain APIs in each owning module's routes.php conditional API branch, preserving existing
paths/names and legacy loading compatibility. Do not create new route files or add domain logic
to routes/api.php or routes/web.php. Existing api.php files can remain compatibility entry points
while route ownership is reconciled without duplicate registration.

Extract business mutations now embedded in browser controllers into shared actions and validation.
Browser and API call the same authoritative operations. Do not wrap redirects or Livewire calls
as an API. No shared actor credential or privileged service actor substitutes for current user rights.

### Complete endpoint families

The matrix freezes the required behaviors. Proposed additive paths under /api/v1 include:

- Calendar context and effective owned settings/capabilities; current calendar/event list/detail.
- Equivalent event filters and range/view context; event create/update plus explicit deletion
  scope or equivalent dedicated occurrence/series commands retaining existing DELETE behavior.
- Calendar-only available-slot queries with bounded inputs and free/busy-only safe output.
- Calendar administration context, system settings GET/PATCH, supported container create/archive
  and access list/upsert/revoke with exact affected-calendar read-back.
- UserManagement-owned own calendar preferences GET/PATCH; existing users/me/work-plan and
  calendar/work-plan/blocks remain the authoritative specialized APIs.
- Nextcloud-owned calendar context, eligible mapping list/upsert/remove, explicit health check,
  calendar-only sync request/status, reviewed automatic-target preview/apply/pause/resume and
  safe conflict/unknown-delivery reconciliation preview/apply.

Paths are additive and typed; final route/operation IDs are frozen with the implementation slice.
The inventory is the completeness gate, not a predetermined endpoint-count cap.

### Authorization and privacy

Every request needs Sanctum authentication, the explicit relevant token ability AND current
active actor/domain/record/field authority. Mirror current tech/admin route permissions and
CalendarVisibility decisions in shared actions; do not silently give a contributor or read-only
token new editor/admin powers. Sharing/settings retain current admin policy and module permissions.
If existing controller/service behavior disagrees with documented rights, record and resolve that
specific mismatch before changing the business rule.

Proposed new ability families: calendar.context/settings/calendars/access/availability/occurrences
as required, own UserManagement preference read/update, and nextcloud.calendar read/mapping/sync/
activation/reconciliation. Retain existing calendar.read/create/update/delete and work-plan abilities.
Add no catalog ability before its implemented route and allow/deny tests. No token is widened.

Mask details before search, counts, sorting and related projections. Free/busy-only consumers see
intervals/availability, not titles, description, participants, meeting URLs or provider identifiers.
Both source and destination calendars must be authorized for moves. Bound provider identities,
creator/source fields, Workday ownership, private source-record access and retention are not writable
caller fields. Related Ticket/Task/Mail objects require their own authorization before projection.

### Mutation, replay and concurrency contracts

Use strict whitelisted and nested request fields. Unsupported mutation fields fail 422 instead
of appearing saved. Document omitted-versus-null semantics for partial updates and participants.
Changing a recurrence definition is not falsely accepted while its action ignores the request.

Durable idempotency binds actor, operation, scope, canonical request fingerprint and exact targets.
New command/create/provider-effect contracts require an idempotency key; existing legacy keyless
routes remain explicitly documented compatibility behavior and may not be blindly auto-retried.
Supplying a key enforces stable replay; a different payload with the same key returns 409. Permission
revocation is rechecked before any replay response can expose saved private content.

Use current-state fingerprints/revisions under short row locks, not second-precision updated_at
alone. Conflicting edits return 409 without an unintended second change. Removal returns an exact
authorized receipt/tombstone or compatible 204 plus operation read-back; missing and unauthorized
records must not reveal private scope through error/count differences.

Read APIs do not create calendars/settings or mutate roles. Extract explicit guarded bootstrap
from EnsureCalendarDefaults and arrange setup through supported onboarding/mutation/operator
initialization. Context can report setup_required without granting roles. Preserve existing data
and additive role grants; never run a destructive blanket role sync to initialize this API.

### Calendar recurrence, participants and consumers

Implement the current browser's single-occurrence exception and series removal semantics through
shared actions used by both interfaces. Keep existing daily/weekly/monthly creation, timezone/DST,
all-day boundaries and expansion limits. Expose series/occurrence identity and safe operation choices.
Do not label arbitrary series edits as implemented. Unsupported requests fail honestly.

Participants remain local scheduling records until the separate invitation workflow is implemented.
Storing participant email/notify is not evidence of an invitation, RSVP or delivery. Work-plan and
absence edits follow their owning APIs. Calendar availability reuses effective working windows,
recurrence, busy statuses, cancellation and Booking contracts without reading private details.

### Nextcloud operations and reconciliation

Reuse the approved calendar-only runner, connection/Calendar leases, conditional writes, source
binding, canonical baseline and write read-back. Do not invoke full RequestNextcloudSync merely
to run/check calendar synchronization: that also handles users/groups/files.

Mapping changes and activation are distinct commands. Preview identifies exact local owner, remote
calendar, direction, conflict/duplicate candidates and effects; apply freezes and revalidates the
reviewed fingerprint. Capability availability does not flip persisted activation, map Personlig,
change sharing or expand PR #309's first work-calendar-only pilot.

Conflict actions must have real shared implementations with append-only outcome evidence, not
a direct status=resolved field or unrestricted metadata patch. Bind-existing/keep-local/keep-remote
is only available when exact current UID/href/ETag and local fingerprint are verified, the actor
can operate both sides, and the resource can be represented safely. Changed previews return 409.
Unknown delivery is reconciled before any repeat provider effect. Unsupported recurring resources
remain retained/blocked; no unconditional overwrite or deletion is introduced.

### OpenAPI, discovery and completeness

Typed request, success/error, pagination and capability contracts cover every registered HTTP method,
including PUT. Preserve safe legacy responses unless a documented additive contract is used.
Bound ranges, expansion, reference lists and queries with explicit completeness/continuation;
a cap must never look like a complete result. Test scope/actor denial, unknown fields, runtime-off,
stale preview, idempotent replay and exact read-back, not only controller happy paths.

Maintain a machine-readable browser/query/API/ability/schema matrix and fail CI on missing supported
operations, route duplicates or undocumented methods. Publish the actual generated Swagger artifact
during a separately authorized release and independently read it back. OpenAPI is prepared for MCP
consumption; actual gateway tool publication/discovery and authorized invocation are a separate
verification claim, not inferred from a Swagger edit.

## Impact Analysis

Affected: Calendar controllers/actions/queries/resources/route ownership/Tests/Knowledge;
Nextcloud calendar mapping/runner/status/activation/conflict actions; UserManagement preferences/
working-hour boundary; Integration ability catalog/OpenAPI; existing Booking/Workday/Task/Ticket
consumers and relevant regressions. Email invitation/OOO architecture is retained unchanged.

Existing dirty Calendar models/controllers/recurrence/visibility and Nextcloud files overlap the
target. Preflight each slice, preserve their verified Workday/sync behavior and stop on conflicting
unapproved changes. No isolated local Windows implementation is permitted; code/runtime work stays
in /var/Projects/tdPSA. UI layout changes are not proposed; read UI guidelines before any later UI edit.
New user-facing errors/help remain English until localization is explicitly in scope.

## Data And Migration Plan

Reuse existing events, series/exceptions, participants, containers/access/settings and Nextcloud
state. Do not invent missing table/column assumptions. Inspect current schema and existing durable
idempotency/audit patterns before choosing scoped additive command/preview/revision storage.
No destructive migration, calendar re-parenting, bulk duplicate merge or historical import.

Any needed additive migrations get explicit scope, isolated migration/up/down and native database
checks before Dev application. Bootstrap/default/backfill is explicit, bounded and idempotent;
it must not change permissions or business objects through GET. Record migration order and worker/
cache/OpenAPI refresh steps. Existing Nextcloud lease migration remains independently required.

No new queue/provider/parser/package is assumed for Calendar API reads. Existing runners/workers
and provider switches stay authoritative. Rollback pauses new provider commands/activation first
and retains bindings, attempts, pending delivery and conflict evidence.

## Testing Plan

- Route inventory versus every browser route/query workflow, HTTP method, ability and OpenAPI schema.
- Same actor outcomes through browser/API, strict fields/null semantics and precise saved results.
- Missing token scope AND missing domain/role/object permission, inactive actor, private/free-busy
  isolation, owner-group filters, masked search/counts and related-source authorization.
- Ordinary/one-occurrence/whole-series delete, recurrence expansion, participants, target-calendar
  moves, working hours, DST/all-day and Booking availability regressions.
- Own-only Workday/UserManagement boundaries, absence retention and source edit guards.
- Read-only GET causes zero Calendar/default/permission writes, including a newly provisioned user.
- Idempotent replay/conflicting payload, same-second edits, stale references/previews and tombstones.
- Nextcloud mapping/activation fingerprints, same-connection/cross-connection leases, blocked
  duplicate candidates, unknown PUT reconciliation, ETag 412 and no unintended full connection sync.
- Affected Calendar/Nextcloud/Workday/UserManagement/Booking and relevant Integration/Ticket/Task
  tests on Dev; isolated Main candidate only if a later merge is explicitly requested.
- Fakes with stray HTTP forbidden; no real invitations, sharing changes or provider writes in QA.
  Live provider, employee handbook access and future production checks require explicit evidence.

## Documentation Plan

Update this RFC, accepted design ADRs, formal Feature Slices after approval, machine matrix, TODO,
module/API/Knowledge sources and HR-2026-10-23-CALENDAR-API. Publish affected handbook content via
the supported BookStack integration and read back exact articles and inherited employee access.
Do not claim employee OpenWebUI retrieval or MCP tool availability without verifying those paths.
Public website handoff is added only after meaningful verified implementation exists.

## Delivery Order After Approval

After the full target is approved, freeze independently testable Feature Slices without reducing it:
1. Route/context/read/strict contract and initialization/authorization boundary.
2. Event mutations, participants, occurrence/series deletion, replay and exact read-back.
3. Administration, sharing and own preference/work-plan parity.
4. Availability and consuming-domain contract/regression coverage.
5. Nextcloud calendar operations, reviewed activation and safe reconciliation.
6. Complete matrix/OpenAPI/handbook/human-review acceptance across all owners.

No final delivery claim while an applicable matrix row is missing. Each slice gets implemented
behavior, tests, documentation and a truthful status before proceeding to the next.

## Approval and delivery state

Svein Tore approved this complete target for implementation on Dev on 2026-10-24 in chat ("Ja").
Six implementation slices are tracked under docs/feature-slices/2026-10-24-calendar-api-*.
HR-2026-10-23-CALENDAR-API remains In Review. Main promotion, production deployment, runtime
activation, live provider writes and completed human scenario review are separate decisions.
No commit or push is requested. The earlier PR #309 merge approval remains scope-specific.

## Implementation evidence

All 25 matrix rows implemented across 43 HTTP methods. See ../plans/2026-10-24-calendar-api-verification.md.
All six slices are Done On Dev. Durable receipts use an additive metadata table independent of audit-log retention.
Human review, Main/production rollout, real provider writes/activation and MCP publication remain separate gates.
