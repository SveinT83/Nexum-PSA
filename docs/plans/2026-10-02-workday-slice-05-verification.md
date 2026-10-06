# Workday Slice 05 Verification

Date: 2026-10-02
Status: Done On Dev, default-off; full pilot and human review pending.
Owner: Codex. Intended reviewer: Svein Tore.
Human-review checklist: HR-2026-10-01-WORKDAY.
Authoritative checkout: /var/Projects/tdPSA, branch Dev; uncommitted working-copy changes.

## Delivered And Boundaries

Reports > Confirmed workdays provides a Workday-owned overview, detail and confirmed revision
history. UI and API share the same confirmed-only query. The query joins the last confirmed
pointer and never loads the current private revision or absence relation. A pending correction
leaves published confirmed facts unchanged. Draft-only/expired days have no oversight detail.

Filter by inclusive dates, literal employee-name substring and exact worker ID. Maximum range is
93 days; default is the current Oslo month through today. Each day keeps its original work date
and timezone. The page limit is 100, with truthful total, returned count, next page and completeness.
No result ceiling silently discards records. Aggregate totals include all matching days across
pages, and do not add Task/Ticket source or billing totals. Unallocated work is shown neutrally.
Dates/employee filters intersect within this installation's database; there is no caller-selected
tenant, company connection or cross-company export. Unknown filters are rejected.

The overview uses an explicit allowlist of confirmed facts: employee ID/name, date/timezone,
day/revision UUIDs, confirmed version/time, descriptions, actual intervals/breaks, totals and
minimal attribution. It omits private correction reasons, draft state/version, raw suggestions,
absence categories/warnings/acknowledgements and internal source IDs/fingerprints.

Source titles/links are loaded only on detail/history and only under the viewer's current domain
permission and API source-read ability. Calendar sharing/private-detail policy applies. The
viewer is not impersonating the worker. Missing or denied sources return a neutral unavailable
state without identifiers or source body. Source drift is marked without changing confirmed facts.
Source writes, billing, timebanks, provider actions and other-worker mutations remain excluded.

## Permissions And Report Integration

workday.view_all is explicit and reusable by custom internal roles. Its one-time additive
migration grants it to Superuser only. New standard Superuser roles receive it at bootstrap;
Admin and other roles do not receive it automatically. RoleSeeder preserves explicit revocation.
No HR role or module was created in live Dev.

All three new API reads require workdays.read-all and the explicit Workday permission.
Own scopes remain self-only. Existing tokens are not rewritten or upgraded. Active human identity
is required, including for oversight; system actors and coordinator-bound credentials are denied.
API/UI overview responses use private/no-store.

The Report registry gains an optional ReportVisibility domain policy, evaluated before legacy
broad discovery shortcuts. Workday's policy respects activation, current identity and the explicit
permission even for Superuser. Legacy report definitions keep their existing policy. The hub only
shows domains with visible reports. report.view remains a hub navigation permission, not a
substitute for Workday data access.

## Changed Files

New Workday files:
- Reports/ConfirmedWorkReportDefinition.php.
- Queries/ConfirmedWorkdays.php.
- Controllers/Tech/OverviewController.php.
- Resources/Api/V1/OverviewOpenApi.php.
- Views/Tech/overview/{index,show,history,snapshot,pagination}.blade.php.
- Tests/Feature/ConfirmedOversightTest.php.
- Docs/knowledge/confirmed-oversight.md.

Updated Workday files:
- routes.php, Support/WorkdayAccess.php, Queries/SourceEvidence.php.
- Existing own-workday SOP and API Knowledge guides.

Report/shared changes:
- Report/Contracts/ReportVisibility.php (new).
- Report/Support/ReportEntry.php and ReportRegistry.php.
- Report/Controllers/Tech/ReportController.php; README and Knowledge overview/API guides.
- Task and Ticket Queries/OwnWorkdayTime.php expose guarded individual source-reference reads.
- config/reports.php, config/breadcrumbs.php, EnforceTechRoutePermission.php.
- PermissionSeeder, RoleSeeder, Integration ApiAbilityCatalog.
- Migration below, generated storage/api-docs/api-docs.json.
- TODO, Slice 05/index, implementation plan, RFC, ADR and human-review checkpoint.

Unrelated shared Dev changes were preserved. No commit or push was made.

## Automated Verification

**136 distinct tests passed / 1392 assertions.**

Main affected-module run: **131 tests / 1201 assertions**, including 15 new oversight tests:

~~~bash
umask 0002
HOME=/tmp php artisan test \
  app/Modules/Workday/Tests/Feature/ConfirmedOversightTest.php \
  app/Modules/Workday/Tests/Feature/ManualWorkdayTest.php \
  app/Modules/Workday/Tests/Feature/AbsenceTest.php \
  app/Modules/Workday/Tests/Feature/SourceReconciliationTest.php \
  app/Modules/Report/Tests/Feature/ReportModuleTest.php \
  app/Modules/Report/Tests/Feature/WorklogOpenApiTest.php \
  app/Modules/Calendar/Tests/Feature/CalendarModuleTest.php \
  app/Modules/Integration/Tests/Feature/AiCoordinatorGovernanceTest.php
~~~

Focused Integration API administration/catalog regression: **5 tests / 191 assertions**:

~~~bash
HOME=/tmp php artisan test app/Modules/Integration/Tests/Feature/IntegrationModuleTest.php \
  --filter='admin_can_open_api_management|admin_can_create_scoped_api_key|api_key_creation_requires_explicit_scopes|ability_catalog_has_explicit_access_metadata|broad_existing_api_keys_are_flagged'
~~~

Coverage includes Superuser/custom HR/ordinary role boundaries, revocation, own-only reads and
foreign mutation denial, confirmed history during corrections, private draft/search exclusion,
a 121-record fixture across workers and pages, exact full-filter sums, expiry, invalid/forged filters,
source permission and token ability changes, source drift/deletion, private Calendar access,
absence exclusion, active-human checks, real personal bearer authentication, coordinator-bound
denial, Report discovery, browser HTTP pages and generated OpenAPI scope/schema parity.

Initial lint found generated return-type escaping and the first test run found two test setup/API
naming mistakes. These were corrected before the final passing runs. No verification failure is
deferred. The database harness uses isolated SQLite :memory: and synthetic fixtures, not real
employee records. No full application suite was run.

Read-only MySQL checks confirmed Laravel's JSON aggregation on known synthetic values (450+120=570)
and the empty live confirmed set. The database uses REPEATABLE-READ. List counts/sums/page are read
inside one normal database transaction. Subsequent page requests are not an immutable export and
can change when employees confirm new revisions.

Targeted Pint, PHP syntax, UTF-8 reads, scoped whitespace and web-path permissions passed.
Trusted Dev HTTPS /docs read-back verifies all three new operations and their separate projection
schemas. Anonymous overview access returns 401. This does not establish an authenticated live
browser session; browser evidence above consists of Laravel HTTP tests. Actual device/mobile/
keyboard and employee MCP acceptance remain in the complete-pilot human checklist.

Evidence directory: /tmp/workday-slice05-1a4ep4kt/ (baseline, test logs, sanitized MySQL read-back,
published contract and implementation manifest). No credentials or real employee fixtures retained.

## Dev Migration And Operations

Applied only on Dev after passing the tests:
2026_10_02_180000_deploy_workday_oversight_permission.

The migration creates workday.view_all and adds one Superuser role grant through the configured
permission tables. It does not seed broad role permissions or alter API tokens. The grant was
read back on Dev. No Workday table/schema or source-data change is required in this slice.

optimize:clear and l5-swagger:generate ran on Dev. No asset build, queue reload or scheduler
change is required. Both activation switches remain false, effective_enabled=false and settings
version=0. All Workday/absence tables remain empty.

Rollback keeps the feature off and preserves employee data and later explicit permission choices.
The permission migration is forward-only; do not restore old broad-discovery behavior while
exposing the unfinished feature. Main/production promotion and production migration remain Svein's.

## Review And Next Work

HR-2026-10-01-WORKDAY remains Pending and blocks merge/promotion to Main, production migration,
deployment and activation. It permits the separately approved Dev-only implementation/migration.
Svein requested notification for manual review only when the complete pilot actually requires it;
no partial manual review is requested now.

The eventual checks include named overview/totals, confirmed history during correction, source
links with/without grants, Superuser and custom HR revocation, no other-worker editing or absence
reasons, responsive layout, keyboard use and real employee MCP read-back. Automated tests cannot
mark this checklist Reviewed.

Next: Slice 06, Workday reminders and profile notification choices. Task conversion, three-year
retention cleanup and real employee MCP acceptance remain Slices 07-09. Tripletex remains future
scope. No public website announcement is appropriate for the incomplete, disabled rollout.
