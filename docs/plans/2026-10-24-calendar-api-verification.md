# Full Calendar API verification and handoff

Date: 2026-10-24. Owner: Codex. Human reviewer/release owner: Svein Tore.
Status: Done On Dev. HR-2026-10-23-CALENDAR-API: In Review.
Authoritative code: /var/Projects/tdPSA, branch Dev.
HEAD preserved: 36918edbcaae1ce7b1208ba34f84a3a1719f0e98.
Approval: Svein Tore approved RFC 2026-10-23-calendar-full-api-parity in chat ("Ja").
No commit, push, Main merge, production deployment, actual provider event write, invitation,
automatic activation or token widening was performed by this implementation.

## Delivered behavior

All 25 current workflow rows (CAL-01..CAL-18 and NC-01..NC-07) are implemented across 43
documented HTTP methods. The exact frozen machine contract is
2026-10-24-calendar-api-contract.json; the baseline and implemented matrix is
2026-10-23-calendar-api-gap-matrix.md. All six implementation slices are Done On Dev.

Calendar: pure context/reads, bounded ranges and reference lists, masking before search/counts/
sort and related projections, safe consistent list/detail, strict input and typed participants,
actor plus token and source/destination authorization, persisted revisions and durable replay,
shared occurrence/series deletion with exact previews. Source-owned absence/work-plan guards
and retained data remain authoritative. Ordinary CRUD preserves documented keyless/partial PUT
compatibility; new commands require keys. Unsupported recurrence edits are rejected honestly.

Administration: settings, shared containers, guarded access upsert/revoke/archive with current
previews, shared browser/API validation and target locks, explicit additive initialization.
UserManagement: own revision-protected display preferences preserve theme and weekly availability.
Existing own-human work-plan/block contracts are retained. Available slots reuse effective work
windows and private busy checks. Their bounded suggestions explicitly declare non-exhaustiveness.

Nextcloud: sanitized paginated context/mappings/attempts/conflicts, guarded mapping operations,
health check, explicit target preview and activation, calendar-only queued sync and real guarded
bind_existing/keep_local/keep_remote reconciliation. Jobs reauthorize current actor and scope,
lease only selected targets, preserve unknown delivery, use conditional ETags and verify read-back.
Mapping changes invalidate automatic activation. No full user/group/file sync is used by these APIs.
Dispatch failures yield readable failed attempts. Provider preflight failures are sanitized 503s.
Unsupported series, ambiguous identities and unsupported provider data remain retained/blocked.

## Automated evidence

- Full affected run: **262 passed / 2519 assertions**, 204.67 seconds.
  Calendar, Nextcloud (including Talk/transport), UserWorkPlan/UserPreferences, Booking,
  Workday absence/retention, Integration catalog/production Swagger scan, Ticket and Task read-back.
- Final hardening run: **68 passed / 681 assertions**, 70.71 seconds, after typed settings,
  selected-target leases and sanitized outage responses. Includes actual queue dispatch assertion.
- Last availability-envelope regression: **1 passed / 20 assertions**, 30.48 seconds.
- These are overlapping runs, not additive distinct-test counts.
- Receipt migration up/down tested in isolated SQLite; durable replay survives activity cleanup.
- All provider HTTP tests use fakes; no real provider/calendar mutation is claimed.
- Scoped formatter: **39 PHP files pass**; final edited availability file separately passes.
- Scoped git diff --check passes. Changed PHP files are world-readable for web/PHP.
- Laravel Boost tooling was not available through this session's tool catalog; current repository
  actions/tests/framework runtime were inspected directly rather than assuming framework behavior.
- Full repository suite was not run: the affected modules/contracts were tested on the shared dirty
  Dev tree; unrelated release work and its reviews were preserved.

## Native Dev and HTTPS read-back

Only additive migration 2026_10_24_120000_create_calendar_api_command_receipts was applied on Dev.
Native MySQL read-back confirmed the table, expected eight columns and migration entry.
Receipt rows: 0. Enabled automatic calendar connections: 0. Live Calendar leases: 0.
The new table stores actor/operation/key/request hashes and result identity, not private event bodies.
It is separate from activity retention, preventing log cleanup from enabling duplicate commands.
No backfill or existing event/settings/permission mutation was needed by this migration.

Trusted certificate/SNI direct LAN HTTPS:
- /up: 200.
- /api/v1/calendar/context with JSON Accept and no authentication: 401.
- /docs: 200, exact byte match with generated Swagger; all 43 method/path pairs present.
The earlier /docs/api-docs.json probe was 404; the registered Swagger JSON route is /docs.
Final Swagger SHA-256: b86dd476944a89d5399f97f540d772f1910e5107ded6d1b186e7b33e049d5b16.

Caches cleared on Dev. No Vite build or new package required.
Existing Nextcloud lease migration 2026_10_22_180000 remains a deployment prerequisite.

## Documentation and handbook

Source documentation updated:
- Calendar/Docs/knowledge/calendar-overview.md.
- Nextcloud/Docs/knowledge/03-users-groups-calendars.md.
- Integration/Docs/knowledge/api-management.md.
- Complete developer guide: app/Modules/Calendar/Docs/api-calendar.md.
- Approved RFC, accepted ADR, all six slices, TODO, matrix, frozen contract and human-review register.

Published through the supported BookStack integration after before-read and dry-run:
- Page 420, Nextcloud Users Groups And Calendars, revision 5.
- Page 455, Calendar Overview, revision 5.
- Page 449, Api Management, revision 120.
Independent full reads confirmed every added section matches its source text exactly.
Page/chapter inheritance and book 339 overrides were read back. Staff Editor role 2 retains
book/chapter/page view-all through inheritance; no permissions were changed.
Employee OpenWebUI retrieval and actual MCP tool publication/invocation were not tested or claimed.
All new handbook sections explicitly say Dev-only and preserve production/human-review gates.

One public-safe Norwegian website handoff item added/read back in the agreed Content and Visibility
file, status "klar for vurdering", with explicit "Ikke publiser" gates. No site publication performed.

## Changed implementation files

The following 39 PHP files are this API work's scoped changes relative to its preserved Dev baseline.
Other pre-existing dirty/shared changes are retained and are not part of an implicit promotion.
Generated storage/api-docs/api-docs.json reflects the shared Dev API tree; prepare a fresh isolated
candidate and regenerate its documentation if Main promotion is later authorized.

- app/Modules/Calendar/Actions/CalendarAdministration.php
- app/Modules/Calendar/Actions/DeleteCalendarEvent.php
- app/Modules/Calendar/Actions/ExecuteCalendarApiCommand.php
- app/Modules/Calendar/Actions/UpdateCalendarEvent.php
- app/Modules/Calendar/Controllers/Admin/CalendarSettingsController.php
- app/Modules/Calendar/Controllers/Api/V1/CalendarAdministrationController.php
- app/Modules/Calendar/Controllers/Api/V1/CalendarAvailabilityController.php
- app/Modules/Calendar/Controllers/Api/V1/CalendarController.php
- app/Modules/Calendar/Controllers/Tech/CalendarController.php
- app/Modules/Calendar/Queries/CalendarApiWorkspace.php
- app/Modules/Calendar/Resources/Api/V1/CalendarApiCommandOpenApi.php
- app/Modules/Calendar/Resources/Api/V1/CalendarApiOpenApi.php
- app/Modules/Calendar/Resources/Api/V1/CalendarEventResource.php
- app/Modules/Calendar/Services/CalendarVisibility.php
- app/Modules/Calendar/Support/CalendarApiContract.php
- app/Modules/Calendar/Tests/Feature/CalendarApiContractTest.php
- app/Modules/Calendar/Tests/Feature/CalendarApiParityTest.php
- app/Modules/Calendar/api.php
- app/Modules/Calendar/routes.php
- app/Modules/Integration/Support/ApiAbilityCatalog.php
- app/Modules/Nextcloud/Actions/CalendarApiAccess.php
- app/Modules/Nextcloud/Actions/ManageNextcloudCalendarMapping.php
- app/Modules/Nextcloud/Actions/NextcloudCalendarLease.php
- app/Modules/Nextcloud/Actions/PreviewNextcloudCalendarOperation.php
- app/Modules/Nextcloud/Actions/ReconcileNextcloudCalendarConflict.php
- app/Modules/Nextcloud/Actions/RunNextcloudCalendarSync.php
- app/Modules/Nextcloud/Actions/SyncMappedNextcloudCalendars.php
- app/Modules/Nextcloud/Controllers/Admin/NextcloudConnectionController.php
- app/Modules/Nextcloud/Controllers/Api/V1/CalendarConflictController.php
- app/Modules/Nextcloud/Controllers/Api/V1/CalendarOperationsController.php
- app/Modules/Nextcloud/Jobs/ReconcileRequestedCalendarConflict.php
- app/Modules/Nextcloud/Jobs/RunRequestedCalendarSync.php
- app/Modules/Nextcloud/Services/NextcloudReadClient.php
- app/Modules/Nextcloud/Tests/Feature/CalendarOperationsApiTest.php
- app/Modules/Nextcloud/api.php
- app/Modules/Nextcloud/routes.php
- app/Modules/UserManagement/Controllers/Api/V1/CalendarPreferencesController.php
- app/Modules/UserManagement/routes.php
- database/migrations/2026_10_24_120000_create_calendar_api_command_receipts.php


## Deployment and recovery

No Main/production action is authorized by the design approval. After required human acceptance and
a separate rollout request:
1. Prepare a scoped reviewed candidate without bringing unrelated shared dirty work.
2. Run affected tests, generate Swagger and apply only the scoped additive receipt migration.
   Preserve/apply the earlier Calendar lease migration if not already present.
3. Clear caches, restart affected queue workers and verify the actual minutely schedule:run job.
4. Read back the exact Swagger artifact, permissions/runtime flags, attempts and intended provider
   event in a separately authorized controlled pilot. Exposing endpoints does not activate sync.
5. Retain command receipts, attempts, bindings, conflicts and unknown-delivery evidence on rollback.
   Pause commands/automatic activation first; do not drop a used receipt table.

Implementation baseline preserved outside the repository:
/tmp/nexum-calendar-api-20261024-before. Transient diagnostics remained under /tmp.
No scratch files were introduced in the repository root.

## Required human review

The authoritative persistent entry is docs/human-review.md, HR-2026-10-23-CALENDAR-API.
It is a **human-review checklist**, In Review, and blocks Main promotion and production rollout.
Design approval and automated tests do not complete these manual scenarios. Svein Tore should:
1. Exercise context/filtering/search and private/shared event masking through an approved client.
2. Create/edit/cancel ordinary and recurring events; verify stale/replay behavior and exact results.
3. Review settings/access/archive previews and own preferences/work-plan behavior.
4. Check Find Time suggestions and protected absence/work-plan source actions in the browser.
5. Review calendar-only mapping/activation/conflict previews without expanding the approved pilot.
6. Separately authorize and verify one real controlled provider reconciliation/read-back.
7. Review the published employee handbook and intended employee access.

The prior HR-2026-10-22-NEXTCLOUD-CALENDAR-SYNC remains In Review for its real production pilot;
this API implementation does not clear that activation gate. Invitation/RSVP/OOO remains queued
under its separate Mail ADR/slice, HR-2026-08-16-029. Future dispatch/holds/crews, arbitrary recurrence
and unsupported provider recurrence are not claimed as delivered by this current-workflow API.

## Main promotion approval (2026-10-24)

Svein Tore explicitly approved the delivered scope and requested merge: "Godkjent. Merge".
HR-2026-10-23-CALENDAR-API is Reviewed for API acceptance/Main promotion. Earlier In Review and
no-merge wording above is the original Dev handoff snapshot, retained as evidence. No individual
manual scenario execution is inferred. Production deployment and provider activation remain separate.
Promotion is being prepared from current origin/main with only this scope and isolated verification.

## Isolated Main candidate verification (2026-10-24)

Base: origin/main 7caa07b376b268e5a928ec683b88b9ba18335d10.
Branch: codex/calendar-api-20261024. Scope extracted from the saved pre-implementation baseline.
Selected PHP files match verified Dev, except the shared ability catalog where only 11 Calendar/
UserManagement/Nextcloud families (22 lines) are promoted. Unrelated Ticket/Task catalog work and
shared TODO/review changes are excluded. Only the Calendar API entries in those registers are added.

248 tests / 2160 assertions pass on the isolated Main candidate, 172.31 seconds.
The candidate has its own vendor tree, autoloader and generated test-only .env with isolated SQLite;
a runtime check verified App and Tests resolve inside the candidate, not the shared Dev checkout.
39 scoped PHP files pass Pint; git diff --check passes. Swagger is regenerated from this candidate,
not copied from dirty shared Dev. All 43 Calendar methods and owning schemas/scopes are covered.
The two additional Ticket/Task read-back test files used in the earlier Dev run belong to separate
unmerged work and were not promoted or treated as prerequisites.

Svein Tore's "Godkjent. Merge" clears HR-2026-10-23-CALENDAR-API for API acceptance and Main merge.
Production deployment, provider activation and HR-2026-10-22-NEXTCLOUD-CALENDAR-SYNC pilot are separate.
