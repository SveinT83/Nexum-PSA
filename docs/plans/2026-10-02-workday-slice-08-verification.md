# Workday Slice 08 Verification - Three-Year Retention And Recovery

Date: 2026-10-02. Status: Done On Dev, default-off.
Owner: Codex; product/reviewer: Svein Tore.
Human-review checklist entry HR-2026-10-01-WORKDAY remains Pending and blocks Main
promotion/merge and production migration/deployment/activation. Svein requested manual review
only when the complete pilot is ready. Slice 09 employee MCP verification and final handoff remain.

## Subsequent scope decision

Svein deferred MCP and selected LiteLLM on 2026-10-02. The next slice now verifies the PSA
API contract and personal bearer workflow. The earlier MCP prerequisite below is superseded;
see [Slice 09](../feature-slices/2026-10-01-workday-09-mcp-and-release-verification.md).

## Delivered behavior

Workday settings offers a metadata-only retention preview. The same POST API requires the
settings scope and explicit permission, works while employee access is disabled, and rejects
cutoff/policy/identity overrides. No HTTP purge operation is exposed.

The workday:retention command previews by default. --execute removes 1-200 selected roots/copies,
with complete root transactions, source ownership checks and employee-first locks matching
normal mutation/delivery. --restore-check fails while expired/orphaned/untracked copies remain.
A five-minute schedule uses its own WORKDAY_RETENTION_ENABLED switch, which remains false.
Employee disablement does not silently disable approved retention.

Stored three-year deadlines are fixed to the original timezone/date, including leap-day behavior.
Exact-deadline reads/replays/creation are denied. Calendar hides expired owned projections at
the deadline. Cleanup removes Workday history, previews, allocations, receipts, owned absence
Calendar projections and every reminder/notification generation. Original Task/time, Ticket,
independent Calendar, current plans and profile configuration remain untouched.

Current notification copies carry a stable source UUID and original expiry. Legacy copies map
through the stable owner/landing URL. Unknown legacy provenance blocks restore instead of
inventing a deadline. Missing reminder receipts after purge cause queued deliveries to no-op.

Workday forms render validation in the current request instead of saving work/absence input in
the session. HTTP responses are no-store; exceptions and failed reminder jobs are sanitized.
Telescope excludes new Workday entries and request data; cleanup removes historical matching
batches and exact Workday failed-job copies. Only counts/fixed reason codes leave cleanup.

See [retention and restore runbook](../runbooks/workday-retention-and-restore.md) for the full
owned-copy inventory, bounded cleanup, restore sequence and external backup/archive checks.

## Changed files

New runtime:
- app/Modules/Workday/Actions/PurgeExpiredWorkday.php
- app/Modules/Workday/Actions/PurgeWorkdayDiagnostics.php
- app/Modules/Calendar/Actions/PurgeWorkdayAbsenceProjection.php
- app/Modules/Notification/Actions/PurgeWorkdayReminderCopies.php
- app/Console/Commands/PurgeWorkdayRetention.php
- app/Modules/Workday/Controllers/Admin/RetentionController.php
- app/Modules/Workday/Resources/Api/V1/RetentionOpenApi.php
- app/Modules/Workday/Views/Admin/retention.blade.php
- app/Modules/Workday/Support/WorkdayPrivacy.php
- app/Modules/Workday/Middleware/PreventWorkdayCopies.php
- app/Modules/Workday/Tests/Feature/RetentionTest.php

Updated runtime:
- Workday Actions/MutateWorkday.php, Actions/MutateAbsence.php, Jobs/DeliverWorkdayReminder.php,
  routes.php, Views/Admin/settings.blade.php and Tests/Feature/ReminderTest.php.
- Calendar Models/CalendarEvent.php.
- Notification Notifications/WorkdayReminderNotification.php.
- app/Providers/TelescopeServiceProvider.php and bootstrap/app.php (narrow Workday boundaries).
- config/workday.php, .env.example and routes/console.php.
- Generated storage/api-docs/api-docs.json.

Documentation: new operator runbook and Workday Knowledge retention article; updated Workday
overview/API, TODO, RFC, ADR, implementation plan, Slice 08/index and the same human-review entry.
The local review mirror contains documentation only; no local implementation or code sync occurred.
Shared unrelated Dev changes were preserved. No commit, push, Main or production action.

## Automated verification

**228 distinct tests passed across the selected suites and final focused reruns**, including
19 retention tests. Tests use Dev's isolated SQLite in-memory harness.

Main regression command:

~~~bash
umask 0002
HOME=/tmp php artisan test app/Modules/Workday/Tests/Feature \
  app/Modules/Calendar/Tests/Feature \
  app/Modules/Notification/Tests/Feature/NotificationSystemTest.php \
  app/Modules/Notification/Tests/Feature/WebPushChannelFoundationTest.php \
  app/Modules/Notification/Tests/Feature/EmailAccountMailChannelTest.php
~~~

The initial main run completed 226 tests: 225 passed, one old reminder assertion expected only
the former four notification fields. It was updated to assert both new provenance fields and their
exact original UUID/expiry. The complete ReminderTest rerun passed **32 tests / 144 assertions**.
The final retention rerun passed **19 tests / 138 assertions**, including an additional
valid-versus-orphaned legacy-copy restore test and authentication-response regression.
Counts above are distinct tests, not a sum that double-counts reruns. No failing test is deferred.

Coverage:
- Before/at/after expiry, leap day, timezone and multi-day absence.
- Correction/conversion preserves original expiry; all owned source reservations/results disappear.
- Created Task and actual source time remain byte-for-byte unchanged; independent event/plan survives.
- Calendar integrity mismatch rolls back one root while the next root succeeds.
- Injected late failure restores already deleted children and prints no exception payload.
- All reminder generations, legacy/current copies, partial-restore orphan and post-purge queued job.
- Separate retention while employee workflow is off, disabled execution, limits, resume/idempotency.
- Synthetic snapshot/restore rehearsal closes the gate until expiry cleanup runs again.
- Explicit permission and token scope, real personal bearer, metadata-only admin UI/API.
- Browser work/absence validation without session copies, no-store headers, redacted debug exception,
  unchanged 401 for absent/invalid bearer and login redirect for anonymous browser access.
- Bounded diagnostic batch cleanup preserves its marker until siblings are gone; unrelated
  diagnostic batches and unrelated failed jobs remain.

The first 14-test retention run exposed three fixture issues: missing required confirmed-day
conversion reason, an omitted web guard in a role lookup and a test route shadowed by {id}.
Those fixtures were corrected; the repeated run passed. Production permission/validation
requirements were retained. Final review also caught and fixed the new exception boundary
intercepting authentication exceptions; explicit anonymous/invalid-token regression now passes.

A fresh read-only native MySQL probe used two independent connections. The second employee-row
FOR UPDATE timed out while the first held it; both transactions rolled back with zero writes.
This verifies the serialization primitive, not a claim of simultaneous end-to-end browser races.

## Runtime read-back

- Branch Dev, authoritative /var/Projects/tdPSA; shared work preflight and baseline captured.
- WORKDAY_ENABLED=false; stored manual workflow enabled=false/version=0.
- WORKDAY_RETENTION_ENABLED=false.
- All eleven personal Workday tables counted by the read-back are empty. Task, TaskTimeEntry
  and Ticket time tables are also empty; tests did not retain live fixtures.
- Live preview: zero expired roots/receipts/notifications and zero untracked notifications;
  **105 historical diagnostic copies**. No content was dumped and no live purge was executed.
- --restore-check correctly exits 1 with restore_ready=false for those copies. This is an
  expected closed gate, not a successful restore certification.
- optimize:clear and l5-swagger:generate succeeded. Trusted HTTPS /docs returned 200 and
  included workdayRetentionPreview, workdays.settings and WorkdayRetentionPreview schema.
- Existing external minute cron invoking this Dev installation's schedule:run was verified.
  The new retention schedule is registered but intentionally disabled.
- Changed PHP syntax/Pint and UTF-8/readability/whitespace checks completed.

Working evidence logs: /tmp/workday-slice08-ffimos5o on Dev. No credential values or employee
content were exported. Synthetic test assertions and read-only inventory are separate from
actual browser approval, real external notification delivery and employee MCP integration.

## Deployment, risks and next work

No migration or frontend build is required by Slice 08. Reload existing workers before future
activation for the notification metadata/error-boundary changes. Refresh caches/generated
OpenAPI and verify the actual minute runner after an approved deployment.

Do not activate purge as part of this implementation handoff. First complete Slice 09 and the
named human-review checklist, confirm external backup rotation/log archive/session/cache
handling, reconcile legacy diagnostic copies, then obtain Svein's rollout/activation decision.
The application fixture rehearsal does not verify external backup recovery or production deletion.

Purge is physically irreversible in the active database. Integrity errors leave the root intact
but expired to ordinary readers. Historical diagnostic inventory scans depend on Telescope volume;
batch limits bound writes/root attempts, not the cost of scanning old diagnostic JSON. External
SMTP/device/API-consumer copies cannot be recalled by this server. Operational summaries have
a separate 30-day maximum documented in the runbook; infrastructure log rotation remains a
pre-activation check.

Next: Slice 09 employee-bound NexumMCP proof and release handoff. Tripletex, holiday entitlement/
approval, advanced rota and phone-provider queue automation remain outside this slice.
