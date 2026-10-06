# Workday Slice 03 Verification

Date: 2026-10-02
Status: Done On Dev, default-off. Complete pilot and human review remain pending.
Owner: Codex. Intended reviewer: Svein Tore.
Review entry: HR-2026-10-01-WORKDAY.
Authoritative checkout: /var/Projects/tdPSA, branch Dev; uncommitted working-copy changes.

## Delivered

Own absence create/read/list/history/correction/cancellation is implemented in browser and API.
Supported categories are sickness, already agreed holiday, agreed time off in lieu and other.
Full calendar days and precise partial periods use explicit timezones and UTC elapsed time.
No notes, diagnosis or attachments are collected; no approval or balance workflow was added.

The versioned, idempotent Workday action locks the same employee row used for time confirmation.
Source, history, receipt and the single neutral Calendar projection commit in one transaction.
A unique CalendarEventLink foreign key preserves provenance. Existing calendar-only events
are not backfilled or automatically linked. Explicit legacy linking is not exposed by this slice.

Calendar displays only Unavailable, no category, notes, participants or sensitive metadata.
Generic Calendar edit/delete/recurrence paths and direct model edits reject owned projections.
The Calendar UI directs the owner to My absences and disables its generic save/delete buttons.
Calendars containing retained absence blocks cannot be archived. Nextcloud outgoing sync skips
these blocks, the direct export client rejects them and inbound updates cannot overwrite them.

Full-day affected minutes use the effective dated weekly plan and explicit work/education
blocks. Partial absence preserves other intervals. Default/unknown schedules do not invent
hours; normal busy meetings do not subtract planned work. Existing unavailability overrides do.
The calculation is current plan impact for this record, not a payroll calculation or all-absence
aggregate. Existing education blocks and their phone-duty settings remain intact.

Overlapping actual work produces a neutral warning. Workday confirmation requires explicit
acknowledgement; a changed overlapping absence invalidates the earlier preview. Work and absence
are never silently adjusted. Excluded breaks do not create work conflicts.

Own absence permissions and read/write token abilities are separate. Ownership still applies
to Superuser. Workday details on the absence screen additionally require workday.view_own.
My Day exposes the working own-absence screen only behind activation and permission checks.

## Changed Files

New Workday files:
- Actions/MutateAbsence.php; Models/WorkdayAbsence.php and WorkdayAbsenceRevision.php.
- Queries/ReadAbsence.php and AbsenceImpact.php; Support/TimeRanges.php.
- Controllers/Tech/AbsenceController.php; Views/Tech/absences/{index,show}.blade.php.
- Resources/Api/V1/AbsenceOpenApi.php; Tests/Feature/AbsenceTest.php.
- Docs/knowledge/absence.md and absence-api.md.

Updated Workday files:
- routes.php, Actions/MutateWorkday.php, Queries/ReadWorkday.php, Support/WorkdayTime.php.
- Views/Tech/{index,show,preview,conflict}.blade.php and Resources/Api/V1/WorkdayOpenApi.php.
- Existing actual-time SOP/API Knowledge articles.

Calendar additions:
- Actions/ProjectWorkdayAbsence.php, Queries/EffectiveWorkIntervals.php,
  Support/EffectiveWorkPlanRules.php.

Calendar changes:
- Actions/EnsureCalendarDefaults.php, FindAvailableSlots.php, LinkCalendarEvent.php,
  UpdateCalendarEvent.php.
- Models/Calendar.php, CalendarEvent.php, CalendarEventLink.php.
- Controllers/Tech/CalendarController.php, Controllers/Api/V1/CalendarController.php.
- Services/CalendarVisibility.php; Views/Tech/index.blade.php and day/week/month/list partials.
- README.md and Docs/knowledge/calendar-overview.md.

Shared changes:
- Nextcloud/Actions/RequestNextcloudSync.php, Services/NextcloudReadClient.php and its feature test.
- EnforceTechRoutePermission, PermissionSeeder/RoleSeeder, Integration ApiAbilityCatalog,
  My Day navigation, breadcrumbs and generated storage/api-docs/api-docs.json.
- Two migrations below; TODO, slice/index, implementation plan, RFC, ADR and human-review status.
Existing unrelated shared Dev changes were preserved. No commit or push was performed.

## Verification

Final focused Dev run: **121 tests passed, 960 assertions**, including 22 new absence tests.

~~~bash
umask 0002
HOME=/tmp php artisan test \
  app/Modules/Workday/Tests/Feature/AbsenceTest.php \
  app/Modules/Workday/Tests/Feature/ManualWorkdayTest.php \
  app/Modules/UserManagement/Tests/Feature/UserWorkPlanTest.php \
  app/Modules/Calendar/Tests/Feature/CalendarModuleTest.php \
  app/Modules/Booking/Tests/Feature/BookingModuleTest.php \
  app/Modules/Nextcloud/Tests/Feature/NextcloudModuleTest.php \
  app/Modules/Warroom/Tests/Feature/WarroomMyDayTest.php
~~~

Coverage includes all six absence API operations, browser HTTP create/correct/cancel/history,
real personal bearer authentication, scopes/permissions, foreign Superuser and system/inactive/
unprivileged denials, rejected medical/identity fields, preserved role revocations, pagination,
exact retry receipts, stale versions, overlapping absence rejection, original retention cutoff,
DST and overnight work, partial remaining intervals, dated rules/overrides, unknown schedules,
recurring education, work conflicts and preview invalidation, neutral broad-admin Calendar
responses, UI/source guards, archive refusal, unique DB provenance, transaction rollback after
projection creation, unchanged actual time and Nextcloud export exclusion.

An initial failing test found that a newly created Calendar had not reloaded its database
is_active default. The projection now refreshes the created Calendar before checking it.

The harness uses isolated SQLite :memory: with synthetic fixtures. Version races are exercised
as deterministic stale requests, not simultaneous browser sessions. Employee locking reuses
the previously verified MySQL worker-row lock in Slice 02. No full application suite was run.

Targeted Pint and PHP syntax passed; 56 scoped PHP/Blade files were checked for web readability.
Generated OpenAPI includes six absence operations plus absence acknowledgement/warning schemas.
Trusted HTTPS /docs was read back and checked. The anonymous absence API returns 401;
this does not prove an authenticated browser session or real MCP execution.

Evidence on Dev: /tmp/workday-slice03-n06knvj5/ (baseline copies, published-openapi.json,
api-verification.json and readback.json). No credentials or employee fixtures were stored.

## Migrations And Runtime Read-Back

Applied only on Dev after the tests passed:

1. 2026_10_02_140000_create_workday_absence_tables
2. 2026_10_02_140100_deploy_workday_absence_permissions

Three new tables: workday_absences, workday_absence_revisions and workday_absence_receipts.
Calendar links gain nullable unique workday_absence_id; previews gain absence_fingerprint.
The unique link index and both migrations were read back on MySQL. All three tables are empty.
Standard internal roles received explicit own-absence grants; ordinary role seeding preserves
later explicit revocations. No HR role or other-worker absence-reason access was introduced.

The original absence-period end anchors a three-year deadline, at midnight after its third
anniversary in the original timezone. Corrections cannot extend it. Expired own source data and
receipts are not served. Removal of retained copies/projections remains Slice 08; feature
activation must wait. Schema rollback refuses retained data.

optimize:clear and l5-swagger:generate ran on Dev. No queue/scheduler or asset build is required
for this slice. Deployment enabled=false, saved enabled=false, effective enabled=false and
settings version=0 were read back. No normal-user activation or production migration occurred.

## Review And Next Work

HR-2026-10-01-WORKDAY remains Pending. It blocks merge/promotion to Main, production migration,
deployment and activation; approved Dev implementation/test migrations are allowed.
Svein must review the full/partial absence forms, stale changes, category privacy across Calendar
viewers, correction/cancellation, work-overlap acknowledgement, desktop/mobile and keyboard
behavior when the complete pilot is ready. Automated evidence does not complete manual review.

Rollback is feature-off with retained data, not destructive down migration. Keep both activation
switches off. No external calendar write, provider phone action, leave approval, balance,
Tripletex transfer or real MCP run occurred. No public website announcement is appropriate for
this incomplete disabled rollout.

Next: Slice 04, reconciliation of existing time and Calendar evidence. Slices 04-09 remain
approved/Ready in sequence; oversight, reminders, Task conversion, retention and MCP acceptance
belong to their scheduled slices.
