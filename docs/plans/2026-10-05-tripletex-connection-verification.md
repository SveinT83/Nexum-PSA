# Tripletex Connection Foundation - Dev Verification

Date: 2026-10-05
Owner: Codex
Status: Connection setup and provider client implemented/tested on Dev.
Overall delivery: Incomplete. Production company identity is verified; controlled provider write/precision tests and the remaining sync slices are outstanding.
Parent: [RFC](../rfc/2026-10-05-workday-tripletex-automatic-time-sync.md),
[plan](2026-10-05-workday-tripletex-delivery-plan.md).
Human review: HR-2026-10-05-WORKDAY-TRIPLETEX is In Review; initial save/company verification confirmed by Svein Tore.

## Implemented Behavior

- Admin connection page with explicit integration.tripletex_manage permission.
- Per-connection expected company/environment, encrypted token and optimistic settings version.
- Verified company identity cannot be rebound to another company or environment.
- Read-only company verification and complete bounded employee/activity/project candidates.
- Hardcoded trusted provider origins, TLS verification, no redirects, short-lived in-memory sessions.
- Company checks before data operations and no cross-connection session reuse.
- Guarded provider create/update/delete helpers with external read-back, versions and lock checks.
- DELETE includes the documented version query; read-back requires 404 plus fresh identity and
  a complete authorized date/employee list. Access failure does not prove deletion.
- Unknown write outcomes are not retried blindly; errors do not retain raw provider exceptions.
- Whole-request/HTTP telemetry suppression and no secret reflection or validation flash.
- Generic integration toggle cannot activate Tripletex; all write/runtime activation remains off.

These are provider building blocks, not an implemented Workday synchronization engine.
The UI deliberately exposes only implemented setup/read operations. No employee mapping is saved,
no background sync runs, and current Workday confirmation behavior has not changed.

## Files

New code:
- config/tripletex.php
- app/Modules/Integration/Exceptions/TripletexException.php
- app/Modules/Integration/Services/Tripletex/TripletexClient.php
- app/Modules/Integration/Support/TripletexAccess.php
- app/Modules/Integration/Http/Middleware/ProtectTripletexCredentials.php
- app/Modules/Integration/Controllers/Admin/TripletexController.php
- app/Modules/Integration/Views/Tech/Admin/System/Integrations/tripletex/{index,form,candidates}.blade.php
- app/Modules/Integration/Tests/Feature/TripletexConnectionTest.php
- database/migrations/2026_10_05_200000_deploy_tripletex_setup_permission.php

Narrow shared edits: Integration module routes; tripletex exclusion from generic toggle; hub card;
PermissionSeeder definition. Existing contributor changes were preserved.
Knowledge: app/Modules/Integration/Docs/knowledge/tripletex-connection.md.
RFC/ADR/plan/TODO/human-review record explicit bidirectional deletion and implementation approval.

## Verification

On authoritative Dev, PHP 8.3.35 / Laravel 12.69.1, isolated SQLite :memory: test harness:

```sh
umask 0002
HOME=/tmp php artisan test app/Modules/Integration/Tests/Feature/TripletexConnectionTest.php app/Modules/Integration/Tests/Feature/IntegrationModuleTest.php --filter='TripletexConnectionTest|admin_can_open_integration_index_from_integration_module'
```

Result: 22 tests passed, 66 assertions, 99.45 seconds. Includes 21 new Tripletex tests and the
existing integration-hub test. HTTP is faked with preventStrayRequests; this is not live-provider proof.
A previous run exposed an overly strict cache-header assertion; it was corrected to assert the
required no-store/private directives because shared middleware adds stricter caching restrictions.
The final full focused run above passed.

Pint fixed scoped new PHP files. SensitiveParameter annotations also prevent credentials and time payloads from appearing as exception arguments; final syntax/Pint checks passed. git diff --check passed for touched tracked integration/seed files.
Five module-owned routes were read back with the expected authentication/admin/privacy middleware.
New files/directories are readable by the project/web group. Trusted HTTPS smoke to the admin URL
returned 302 to authentication; it does not establish authenticated browser completion.
Automated authenticated response tests rendered both setup and candidate Blade pages.

## Dev Migration And Runtime

Reviewed/applied only this permission migration:

```sh
php artisan migrate --path=database/migrations/2026_10_05_200000_deploy_tripletex_setup_permission.php --no-interaction
php artisan migrate:status --path=database/migrations/2026_10_05_200000_deploy_tripletex_setup_permission.php
```

Read-back: migration Ran, batch 14. Grants setup to existing Admin/Superuser roles, not API tokens.
No other pending migrations were applied. No runtime sync worker/scheduler or asset build is needed
for this setup-only portion. Both tripletex.enabled and tripletex.writes_enabled read back false.
At the initial foundation handoff, the Tripletex connection count was zero. Svein subsequently saved the encrypted token and confirmed a green check; the current read-back is documented below.
No commit, push, Main action or production deployment was performed.

Before a later approved deployment, apply the same permission migration and normal reviewed
route/config/opcache refresh as required by that environment; the full synchronization deployment
commands remain to be implemented and verified. Runtime switches must remain off until the full pilot.

## Saved Credential And Single-Account Follow-up - 2026-10-05

Svein Tore saved the token through the Nexum settings page and explicitly reported a green
company check. Sanitized Dev read-back confirms exactly one production connection for company
5258869, with matching verified_company_id and a saved identity receipt. The token remains in
the existing encrypted secret store. A copy from NexumMCP is no longer needed.

Svein then requested only one Tripletex account per Nexum installation. The RFC and ADR now
record this approved amendment. The settings page shows the existing account without an add
form; the integration hub and reference-data page use singular Settings wording. First setup
remains available for an empty installation. Updating the same account or rotating its token
remains supported; verified company/environment binding is preserved.

Controller checks reject an additional POST with HTTP 409 without modifying the existing row.
A generated database column and unique index also reject concurrent inserts and non-controller
writes. NULL generated values leave other integration types unrestricted. The additive migration
refuses legacy duplicates before schema changes and never chooses or removes an account.

Additional files:
- database/migrations/2026_10_05_210000_enforce_single_tripletex_connection.php
- Existing Tripletex controller, setup/candidate views, Integration hub, tests and Knowledge.
- RFC/ADR/delivery plan, TODO and human-review records updated in place.

Seven added tests cover empty/existing setup, rejected duplicate requests with full row
preservation, blank/rotated token updates, database uniqueness, migration reversal and safe
refusal of legacy duplicates. The session-isolation test now uses separate in-memory installation
snapshots rather than creating multiple stored accounts in one installation.

An initial duplicate-response test matched its own hardcoded fixture in Laravel's debug source
panel. It now generates the fixture at runtime; the focused rerun passed (1 test / 6 assertions).
Final scoped run on authoritative Dev: **29 tests passed, 96 assertions, 122.31 seconds**.
This includes all 28 Tripletex feature tests and the existing integration-hub regression.
Pint, PHP syntax checks and git diff --check passed. The updated settings and candidate views
render in authenticated feature tests; trusted HTTPS smoke returns 302 to login, not browser-login proof.

Applied only the new migration on Dev; read-back reports Ran, batch 15:

```sh
php artisan migrate --path=database/migrations/2026_10_05_210000_enforce_single_tripletex_connection.php --no-interaction
php artisan migrate:status --path=database/migrations/2026_10_05_210000_enforce_single_tripletex_connection.php
```

MariaDB 10.6 independently rejected a synthetic second-account INSERT through the new unique
index; the surrounding transaction was rolled back and no fixture remains. A before/after
fingerprint over every original account column (including encrypted credential/config/identity
receipt) is identical. Exactly one connection remains, matching verified company 5258869.
Both runtime flags remain false. No Tripletex time write, commit, push or Main action occurred.

No asset build, queue restart or scheduler change is needed for this setup adjustment.
Before a later approved Main deployment, apply this additive migration after the existing setup
permission migration. Rollback drops only the generated column/index; it preserves the account.
Manual UI confirmation of the single existing-account form remains in the human-review checklist.


## Remaining Provider Gate And Resume Condition

The production credential and identity check are available. Controlled precision, lock,
concurrency, activity/project and deletion write-contract tests still need an appropriate
test company or explicitly scoped synthetic pilot records. A green identity check does not prove
write capability and is not authorization to add payroll-impacting test entries to employee history.

Then finish Slice 01: provider contract evidence, explicit saved employee/activity mapping and
compatible Workday storage/API cutover design. Slices 02-04 remain not started: effective
Save/duration-only time, durable bidirectional create/update/delete reconciliation/tombstones,
and operational recovery/retention/full pilot.

Single-account UI review remains under HR-2026-10-05-WORKDAY-TRIPLETEX; full synchronization review
will be requested when that pilot is implemented. The checklist still gates Main/production.

## Direct Live Read Verification And Token Placeholder - 2026-10-05

The saved Nexum Dev connection (not the separate NexumMCP connection) was tested directly
through TripletexClient with TLS validation and telemetry suppression. Identity matched
company 5258869. Complete bounded reads returned 5 employees, 9 activities, 0 projects, and
2026-10-05 timesheet lists for all 5 employees (1 entry total). No names, token values, time
descriptions or raw provider responses were stored in this evidence. No time mutations occurred.

The API-token form now places the blank-keeps-existing hint inside the empty password input;
new installations see an enter-token placeholder. Secret storage and save behavior are unchanged.
Scoped Dev verification: 5 feature tests passed, 30 assertions, 45.30 seconds (initial/existing form rendering, empty-token preservation and rotation, company check and candidate reads). Pint and git diff --check passed. No migration, build, worker restart or deployment is required for the placeholder edit.

Svein also requested the synchronization on/off setting below the account settings. This is
recorded in the RFC/plan/TODO and review checklist. The runtime and its control remain unfinished:
a green identity check and successful reads do not prove create/update/delete synchronization.
The requested synthetic-write employee/date or test-company scope remains pending.
