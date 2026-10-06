# Tripletex Time Synchronization Pilot Verification

Date: 2026-10-05. Authoritative checkout: `/var/Projects/tdPSA`, branch `Dev`.
Human review: **HR-2026-10-05-WORKDAY-TRIPLETEX — In Review**.
No Main commit, merge, promotion or deployment was performed.

## Authorization and scope

Svein authorized implementation, two-way deletion, automatic propagation without employee
confirmation, one account, and the switch under account settings. In this turn he explicitly
authorized Admin User as his own Tripletex employee for testing.
The persisted mapping is Nexum user 3 to Tripletex employee 1034672, default activity 3695275,
from 2026-10-05, timezone Europe/Oslo, company 5258869.
Other Tripletex employees were not mapped and their time was not changed.

## Live provider and integration evidence

The direct provider contract test created a one-minute entry. Read-back returned 0.02 hours,
proving hundredth-hour rounding rather than minute-exact or whole-hour storage. A 0.75-hour
update, stale-version rejection and versioned deletion with direct/list absence verification passed.
The temporary row 194158503 was removed.

The completed Workday/DataExchange pipeline then passed:
- Nexum create 0.25 hours and remote read-back.
- Nexum update to 0.50 hours and remote read-back.
- Provider update to 0.75 hours and automatic effective Workday import.
- Provider deletion and automatic local zero-time revision.
- Provider creation and automatic import without acceptance.
- Editing the imported row in Nexum and remote read-back.
- Nexum removal, provider absence read-back and final local actual time **0 minutes**.

No synthetic provider time remains. Zero-time Workday revision history is deliberately retained
as an audit trail. The sanitized runtime report is `/tmp/tripletex-engine-live-verification.json`.
No API token was copied into code, tests, logs, documentation or output.

## Implementation and boundaries

Workday has an explicit effective `save` operation, state `recorded`, and duration rows stored in
integer hundredth-hour units. The existing `confirmed_revision_id` is the compatibility pointer
for effective actual time; a recorded revision is not an employee confirmation or payroll approval.
The oversight projection includes recorded and legacy confirmed states, but never private drafts.
The API adds `PUT /api/v1/workdays/{work_date}/save` with `workdays.write`, personal permission,
optimistic version and idempotency-key checks. The existing draft API remains private.

DataExchange owns `tripletex_workday_sync_states`: one company/user/date baseline, durable
multi-row write intent, immutable revision provenance and deletion tombstones represented by
an empty synchronized baseline. HTTP writes occur after intent commits, not inside retried DB
transactions. Imported revisions use a disabled, non-login system actor with no broad grants.
Changing one side propagates; incompatible concurrent changes stop the date with an exception.
Partial lists, permission failures, missing local records and retention expiry are not deletion.
Source allocation conflicts stop import rather than discarding attribution.

The on/off control is authorized, audited and versioned. It shares the worker's per-connection
lock, so a successful off response fences prior work. Runtime state is checked before imports
and outgoing mutations. Account saves also use this lock and pause delivery.

## Dev operations

Applied migrations:
- `2026_10_05_200000_deploy_tripletex_setup_permission.php` (previous work).
- `2026_10_05_210000_enforce_single_tripletex_connection.php` (previous work).
- `2026_10_05_220000_create_tripletex_workday_sync_states.php` (this turn).

The sync-state migration uses a DATETIME expiry; MariaDB rejected an implicit TIMESTAMP
default during the first attempt. The failed CREATE left no table; the corrected migration
applied successfully. No destructive migration or data reset was used.

The Dev deployment flags `TRIPLETEX_ENABLED` and `TRIPLETEX_WRITES_ENABLED` are enabled so
the account switch can operate. Config/view caches were refreshed.
`tripletex:sync-time` is registered every five minutes. The user's existing crontab runs
`php8.3 artisan schedule:run` every minute under flock; queue workers alone were not treated
as scheduler evidence. The account is restored to paused after verification unless Svein
changes the switch himself. Enable it in settings to begin routine delivery.

For later promotion, apply the additive migrations, refresh caches, enable the deployment
flags, verify the external scheduler and choose explicit mappings/start dates. Preserve
baselines and intent when pausing or rolling back application code. No destructive down
migration is allowed while delivery evidence exists.

## Automated verification

The targeted final regression run passed 77 tests / 523 assertions, including both directions,
ambiguous-create recovery, switch state/version/permissions, duration rendering/editing,
fractional reports, empty-date import, private drafts, reminders and retention.
The complete final connector/Workday run passed **223 tests / 2021 assertions** in 292.28 seconds.
This includes every Workday feature test and both Tripletex feature suites. Earlier failures
were fixed: schema compatibility and old confirmation-only test expectations.
Blade compilation, route registration and targeted formatting checks passed.

Browser automation could not connect to the desktop browser (two tool timeouts). HTTP feature
tests render the authenticated forms, but they are not named-human visual acceptance.

## Remaining rollout work

- Named-human review of the actual switch and calendar/duration editing, desktop and mobile.
- Automatic overnight splitting across provider reporting dates; the pilot explicitly rejects
  a crossing-midnight clock interval and requires separate date entries.
- Dedicated operator conflict-choice UI and established-mapping migration workflow; current
  conflicts can converge by authorized matching edits, without silently choosing a winner.
- Large multi-employee/project volume, long-running lock lease/timeout behavior, provider
  rate-limit/backoff policy and full restore drills beyond the tested bounded employee pilot.
- Real approved/locked payroll-period behavior and project-specific entitlements; tests enforce
  fail-closed locks, but the live own-employee company had no projects and no locked test row.
- Commercial multi-customer authentication/distribution terms before customer rollout.

## Final independent read-back

The normal OS scheduler ran the connector at **2026-10-05 21:00:07 UTC**, updating the mapped
date to synced with no error. The setting controller then returned 302 and persisted disabled
(version 7). A subsequent worker invocation left the complete state row unchanged and made
**zero provider requests**. The account switch is left off, with runtime readiness enabled.

The live Dev HTTP kernel rendered the settings route as Admin User with HTTP 200: exactly one
switch, unchecked after pausing, an empty token value, and the requested in-field placeholder.
This is server-side authenticated rendering evidence, not a browser screenshot or human review.

## Main changed files

- Integration: TripletexController, TripletexClient, TripletexTimeMapping, module routes and account/time-sync views.
- DataExchange: SyncTripletexWorkdays and TripletexWorkdaySyncState.
- Workday: MutateWorkday, WorkdayDuration, WorkdayTime, ReadWorkday, WorkdayEditor, ReadWorkdayEntry, ConfirmedWorkdays, ReminderEligibility, PurgeExpiredWorkday, controller/routes, duration/calendar views and API schemas.
- Runtime: SyncTripletexTime command, console schedule, Dev deployment flags, additive sync-state migration.
- Notification: Workday reminder wording; API permission map; connector/Workday feature tests.
- Documentation: approved RFC/ADR status, functional pilot slice, TODO, human review and affected Knowledge articles.
