# Workday Dev Pilot Activation And Verification

Date: 2026-10-03. Owner: Codex; reviewer: Svein Tore.
Status: Dev employee pilot active; authenticated browser verification awaits user login.
Scope: existing approved Workday Slices 01-09; no new feature, permission or schema changes.
Authorization: Svein asked to prepare the combined Dev pilot for testing on 2026-10-03.
Human review: HR-2026-10-01-WORKDAY remains Pending. No human check is marked complete.

## Activation and persisted read-back

Authoritative checkout /var/Projects/tdPSA, branch Dev, application URL https://dev.nexumpsa.eu.
Existing shared working-copy changes were inventoried and preserved.
WORKDAY_ENABLED was false/absent and is now true in Dev's ignored .env.
The stored manual_workflow setting changed from enabled=false/version=0 to enabled=true/version=1.
The existing versioned MutateWorkday settings action performed the change as the sole existing
permitted Dev administrator (Admin User, id 3), with a stable idempotency key and operator origin.
This was a Codex deployment operation under Svein's instruction, not an employee time action
or human-review approval. No login/session/API token was created.

Fresh read-back: deployment_enabled=true, enabled=true, effective_enabled=true,
retention_years=3, version=1; WORKDAY_RETENTION_ENABLED=false.
All eight Workday migrations were already applied; no migration was needed or run.
No real or synthetic employee day, absence, Task or TaskTimeEntry was created during preparation.
Only the workflow setting and its normal mutation receipt were persisted.
No role, scope, credential or notification preference was changed.

## Verification performed

On authoritative Dev, using the isolated synthetic Laravel harness:

~~~bash
umask 0002
HOME=/tmp php artisan test app/Modules/Workday/Tests/Feature \
  app/Modules/UserManagement/Tests/Feature/UserWorkPlanTest.php \
  app/Modules/Calendar/Tests/Feature
~~~

**196 tests passed, 1912 assertions, 198.55 seconds.**
Includes browser-route form behavior, API personal bearer workflows, ownership/scopes,
confirmation/correction, absence, source reconciliation, Task conversion, reminders,
retention and Calendar/profile regression.
The SSH status wrapper had a quoting error after the successful test summary; the test log
itself records all 196 passes and no failed test. No application failure was deferred.
The repository-wide suite was not run; this is a Dev pilot, not a Main release candidate.

Commands completed: config:clear, view:clear, queue:restart and l5-swagger:generate.
Commands that may render views used umask 0002. No frontend build or web-server restart.
Default queue connection is database. After activation, a fresh cron-managed database worker
for email,default was observed at 12:09:01 Europe/Oslo; unrelated Redis workers were not
force-killed. External cron runs this checkout's schedule:run every minute; cron is active
and the scheduler runtime log modification time advanced. Workday reminder and independent
retention schedules are registered.

Trusted HTTPS /docs returned 200. Anonymous protected page requests redirect to /login:
My workdays, Work plan, My absences, Confirmed workdays and Profile notifications.
This verifies anonymous protection and TLS, not authenticated page rendering.

The existing browser initially displayed cached Admin User content from My Day. Navigating
to current settings exposed an expired session and the login page. The user has been asked
to sign in. No browser writes, fixture creation or screenshot-based layout approval are claimed.

## Reminder state

The sole active permitted employee at preflight was Admin User (Superuser), with all seven
explicit Workday permissions. Profile defaults/current state: in-app=true, email=false,
Web Push=false. These remain unchanged. No email/push was sent or opted in.
Actual external receipt remains a human-review check with a deliberately opted-in test user/device.

## Retention, backups and archives

The read-only retention restore check returned restore_ready=false: 105 historical diagnostic
copies, zero expired roots/reminders/detached receipts/notifications and zero untracked
notification copies. No purge or archive deletion was run. Cleanup stays independently off.

Session driver is database, lifetime 120 minutes; cache driver is database. The configured
daily log retention is 14 days, but this alone does not establish active stack-file or archive
rotation. No application-specific /var/Projects/tdPSA rule was found in readable
/etc/logrotate.d files. scheduler-runtime.log and laravel.log exist as sizeable append-only
files; their contents were not exported. External database/filesystem backup destinations,
encryption, rotation and restore remain unverified.

This activation is for synthetic Dev testing under Svein's explicit instruction. Production
or real-employee rollout and retention execution still require reconciliation of diagnostic
copies and actual backup/log/session/archive handling. No restore certification is claimed.

## Handoff and remaining work

Use [the pilot review guide](2026-10-03-workday-dev-pilot-review.md) for direct links and a short
test scenario. Next: sign into Dev, complete authenticated desktop/mobile/keyboard browser
verification, fix any defects, then carry out the existing eleven human-review checks.
No partial approval is requested while that browser verification is blocked.
MCP/LiteLLM implementation remains deferred and does not block this pilot.

Main promotion/merge and production migration/deployment/activation remain with Svein and
are blocked by HR-2026-10-01-WORKDAY until explicitly reviewed.

## Rollback

Set WORKDAY_ENABLED=false in Dev and clear configuration to close employee access without
deleting records. Alternatively disable the stored setting through its versioned settings
workflow. Leave the independently controlled retention switch unchanged. Do not run down
migrations or delete employee records as rollback.

Evidence directory: /tmp/workday-pilot-RNRvSq (sanitized JSON, baseline manifest and test log).
No commit, push, Main change, production deployment or public website handoff occurred.
