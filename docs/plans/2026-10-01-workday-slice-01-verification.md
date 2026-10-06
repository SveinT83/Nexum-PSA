# Workday Slice 01 Verification

Date: 2026-10-01
Owner: Codex. Product/implementation approval: Svein Tore, 2026-10-01.
Status: Done On Dev, default-off; complete Workday delivery remains in progress.
Parent: [Implementation plan](2026-10-01-workday-implementation-plan.md)
Slice: [01](../feature-slices/2026-10-01-workday-01-simple-work-plan.md)
Human review: HR-2026-10-01-WORKDAY - Pending before Main/production.

## Delivered

- Independent weekly-plan UI/API save under UserManagement, preserving account-security ownership.
- Profile hours/timezone projected into effective-dated Calendar rules with explicit provenance.
- Legacy preference fallback, a conflict preview and preservation of unowned Calendar rules.
- Display preferences no longer overwrite working hours, Calendar timezone or availability.
- Calendar-owned Education/Work/Other blocks, weekly recurrence, phone-duty and booking choices.
- Single-occurrence modification/cancellation; explicit series update/cancellation and stable create IDs.
- Own-only scoped APIs, version conflicts, strict local-clock validation, recurrence across DST,
  generic Calendar edit guards and unchanged billing/actual-time boundaries.
- Generated OpenAPI and staged Knowledge documentation.

No migration, backfill, permission seeding, queue job, scheduler, frontend bundle change or external
integration is required for this slice. New abilities are catalog entries, not automatic token grants.
WORKDAY_ENABLED remains false on Dev. The remaining workflow must be finished before ordinary use.

## Verification

Baseline before implementation: 43 tests / 357 assertions passed.
Final focused cross-module run: 75 tests / 599 assertions passed, exit 0, 48.63 seconds.
Suites: UserWorkPlanTest, UserPreferencesTest, UpdateUserProfileSecurityBoundaryTest,
UserManagementAdminTest, UserManagementApiTest, Calendar Feature and Booking Feature.
All tests used the protected SQLite :memory: harness on the authoritative Dev server.

The regression test for unrelated preferences was also executed with only the original preference
action preloaded in an isolated PHPUnit process. It failed as expected because the old action
changed Calendar timezone from Europe/Oslo to America/New_York. The current action passes.
No shared runtime file was reverted for this comparison.

Authenticated Laravel HTTP tests cover UI rendering, API mutation/read-back, declined scopes,
foreign ownership, inactive/system/portal identities, date boundaries, DST, old-plan preservation,
Calendar recurrence and Booking regressions. Existing protected user mutation tests pass.
The initial new profile-projection assertion used a date-only string against SQLite's serialized
midnight date. It was corrected to assert the date semantically; the final entire run passes.

Six API operations and eleven total UI/API routes are registered in the owning modules.
php artisan l5-swagger:generate succeeded. All six operations were read back from
https://dev.nexumpsa.eu/docs over trusted HTTPS, HTTP 200.
This verifies the published contract, not a real employee MCP write or a browser session.
PHP syntax, targeted Pint formatting and git diff --check passed for the implementation files.
No full application suite or real employee/production mutation was performed.

## Changed Areas

UserManagement: UserWorkPlan, dedicated controller/middleware/UI, own API routes, profile projection,
display-preference correction, feature/regression tests, OpenAPI and Knowledge.
Calendar: plan-block action/controller/routes, provenance on initial rules, effective date/overnight
availability, recurrence replacement and DST behavior, generic edit guards and Knowledge.
Integration: four scoped ability catalog entries. Shared API loader: explicit owning route includes.
Config: workday default-off switch. Generated API specification updated.
RFC/ADR approval, slice index, TODO and human-review tracking updated in the same session.

Machine-local implementation evidence: /tmp/workday-implementation-swoast31/ on Dev.
This contains the exact final test log, pre-change copies, focused code inventory and downloaded
published contract. Evidence is outside the repository and contains no credentials.

## Remaining Work And Release

Slices 02-09 are Ready under the existing implementation approval; next is own manual days,
confirmation and API. Absence, source reconciliation, oversight, profile reminders, Task conversion,
three-year Workday retention and actual employee NexumMCP verification are not delivered by Slice 01.

Human review is Pending. Once the complete Dev pilot is enabled, Svein must review different
weekday hours, preserved Calendar exceptions, recurring education/one-occurrence edits,
phone duty, timezone behavior and desktop/mobile/keyboard use. No review item is marked Reviewed.
Main merge/promotion and production migration/deployment/activation remain blocked by the
human-review gate and Svein's separate release ownership.

Future deployment: regenerate OpenAPI and refresh normal route/config caches as applicable;
set umask 0002 for view-rendering Artisan commands. No migration or worker reload is needed for
Slice 01 itself. Keep WORKDAY_ENABLED=false until pilot/release conditions are met.
Rollback: disable the feature; retain profile data, dated rules, Calendar blocks and source records.
Do not delete historical plans or restore the preference-overwrite behavior to an enabled workflow.
