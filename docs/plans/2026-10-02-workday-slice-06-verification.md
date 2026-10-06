# Workday Slice 06 Verification - Reminders And Profile Choices

Date: 2026-10-02. Status: Done On Dev, default-off.
Owner: Codex; product/reviewer: Svein Tore. Human-review checklist: HR-2026-10-01-WORKDAY,
Pending. This blocks Main promotion, production migration/deployment and activation, not the
separately approved Dev implementation. The complete pilot is not ready for manual review yet.

## Delivered behavior

- Profile > Notifications owns one Workday preference row, shared with the employee API.
  In-app defaults on; Email and Web Push require opt-in and existing provider/device readiness.
  All channels off disables reminders. Unsupported Talk/preview channels are rejected.
- The effective personal Calendar plan determines timing: 15 minutes before the final remaining
  interval, timezone-aware, including dated/recurring work, part-time and overnight shifts.
  Full absence/off-days/unknown plans and employee-confirmed days suppress the reminder.
  Partial absence preserves remaining work. Education is work context, not absence.
- A passive header bell and personal landing page offer Open workday/Snooze 30 minutes.
  No modal, focus change, automatic navigation or submission of another form. Unread reminders
  remain on the next visit. Opening never creates or confirms time.
- Receipt uniqueness, generation-checked snooze and per-channel claims prevent duplicate logical
  scheduling. Queued jobs recheck active human identity, explicit permissions, current preferences,
  plan, absence, generation and confirmation. API/MCP confirmation uses the same actual-time record.
- Generic messages contain no work descriptions, absence reasons, customer details or escalation.
  External Email uses the frozen configured system-provider binding. Web Push uses existing
  readiness, employee device subscriptions and target authorization.
- Four employee API operations expose pending reminders, preferences and snooze with the separate
  workday-reminders.read/write abilities. Existing tokens and coordinator grants were not expanded.

## Files and domains

New runtime files:

- Workday Models/WorkdayReminder.php and WorkdayReminderDelivery.php.
- Workday Actions/WorkdayReminders.php, Support/ReminderEligibility.php,
  Jobs/DeliverWorkdayReminder.php, Controllers/Tech/ReminderController.php.
- Workday Resources/Api/V1/ReminderOpenApi.php, Views/Tech/reminders/open.blade.php
  and Tests/Feature/ReminderTest.php.
- Notification Actions/WorkdayReminderPreferences.php and Notifications/WorkdayReminderNotification.php.
- app/Console/Commands/DispatchWorkdayReminders.php and the additive migration below.

Updated runtime files:

- Calendar Queries/EffectiveWorkIntervals.php adds optional local-start-date anchoring before
  absence subtraction; existing callers preserve their previous behavior.
- Workday routes.php and WorkdayController create-date input.
- Notification registry, target authorization, settings controller/view, bell component/view,
  notification open/API list/read filtering, and existing registry/profile regression tests.
- EnforceTechRoutePermission, Integration ApiAbilityCatalog and routes/console.php.
- Generated storage/api-docs/api-docs.json.

Updated documentation: Workday/Notification/profile Knowledge, retention copy inventory,
operations runbook, TODO, Slice 06/index, RFC, ADR, implementation plan and human-review register.
Unrelated shared Dev work was preserved. No commit, push, Main or production deployment.

## Tests and read-back

**219 distinct tests passed / 2091 assertions**, including **32 new reminder tests**.

Main regression: **214 tests / 1898 assertions**:

~~~bash
umask 0002
HOME=/tmp php artisan test \
  app/Modules/Workday/Tests/Feature \
  app/Modules/Notification/Tests/Feature/NotificationSystemTest.php \
  app/Modules/Notification/Tests/Feature/NotificationTypeRegistryWebPushTest.php \
  app/Modules/Notification/Tests/Feature/WebPushChannelFoundationTest.php \
  app/Modules/Notification/Tests/Feature/EmailAccountMailChannelTest.php \
  app/Modules/UserManagement/Tests/Feature/UserWorkPlanTest.php \
  app/Modules/UserManagement/Tests/Feature/UserPreferencesTest.php \
  app/Modules/Calendar/Tests/Feature/CalendarModuleTest.php
~~~

Integration API administration/catalog: **5 tests / 193 assertions**, using IntegrationModuleTest
with filter admin_can_open_api_management|admin_can_create_scoped_api_key|
api_key_creation_requires_explicit_scopes|ability_catalog_has_explicit_access_metadata|
broad_existing_api_keys_are_flagged.

After adapting the migration to Dev MySQL, ReminderTest was rerun: **32 tests / 142 assertions**,
overlapping the main run above. These tests are not counted twice.

Coverage includes authenticated Laravel HTTP profile/landing/API read-back, defaults and channel
readiness, unsupported settings, source-plan regression, part-time/off-days/DST/overnight,
full/partial absence, changed/cancelled recurrence, confirmation via API, inactive/system users,
permission removal, foreign owners, API scopes, coordinator-bound credentials, passive next visit,
Livewire snooze, stale-generation retries, serialized job round-trips, broker-gap recovery,
generic payloads, provider rebinding, external opt-out, ambiguous/lost external claims, catch-up
age and exact retention boundary.

Initial verification found an incorrect configured-user-table reference, an omitted browser-route
permission mapping, and a legacy test posting a Workday setting hidden by the runtime switch.
These were corrected. Dev MySQL rejected multiple non-null TIMESTAMP columns without defaults;
the failed statement created no tables. The migration now uses dateTime, matching existing Workday
storage, and passed both repeat tests and actual Dev migration. No verification failure is deferred.

Tests use isolated SQLite :memory: and synthetic recipients. SMTP/Web Push transport was replaced
in tests; no employee messages were sent. Repeated jobs and generations were exercised; this is not
a claim of external-provider exactly-once delivery or device receipt. No full application suite,
live authenticated browser/mobile review or real employee MCP action was performed.

Trusted Dev HTTPS /docs returned all four operations, bearer security and three reminder schemas.
Authenticated UI/API evidence is from Laravel HTTP/Livewire tests. External cron was inspected
separately: the current user's crontab runs php artisan schedule:run every minute in
/var/Projects/tdPSA; cron is active and default-queue workers are present.
PHP syntax, UTF-8, scoped whitespace and web-readable file permissions passed.

Evidence: /tmp/workday-slice06-84i9h1w2/ contains baseline, test logs, sanitized runtime/scheduler
read-back, published API contract and implementation hashes. No credentials or live employee
fixtures were captured. The temporary bootstrap read-back script was removed after verification.

## Dev migration and operation

Applied only on Dev:
2026_10_02_200000_create_workday_reminder_receipts.

Creates workday_reminders, workday_reminder_deliveries and workday_reminder_cursors.
Read-back: all three empty; all eight earlier Workday tables still empty. Both activation
switches remain false, settings version 0. No Workday notification preferences or token changes.

optimize:clear and l5-swagger:generate ran. No frontend build or OS scheduler edit was needed.
workday:reminders --limit=1 returned enabled=false/scanned=0/queued=0.
Shared workers were not restarted while the feature is off. Reload default-queue workers before
future approved pilot activation. See [operations and recovery](2026-10-02-workday-reminder-operations.md).

External delivery claims commit before provider I/O. A worker lost after claiming can leave an
unknown result, so attempting/unresolved claims are not automatically replayed. This deliberately
allows a missed external nudge instead of a duplicate. In-app delivery is transactional.
Discovery covers today/yesterday; unread stored reminders persist, but a long scheduler outage
does not generate an unbounded historical backlog. External catch-up stops twelve hours after
planned end. Pending UI/API results are bounded to ten among fifty recent unread receipts.

Rollback: disable Workday and preserve receipts. The down migration refuses retained data.
Three-year access expiry is enforced; physical removal of receipts, deliveries, all generated
Notification copies and restore handling is explicitly assigned to Slice 08.

## Review and next slice

HR-2026-10-01-WORKDAY remains Pending. Later checks cover real opted-in Email/Web Push receipt,
keyboard/mobile use, an unsaved form during bell refresh, snooze across tabs, full/partial absence,
and employee MCP confirmation suppressing pending reminders. Only Svein's explicit review can
complete that checklist; no partial manual review is requested now.

Next: Slice 07, explicit internal Task conversion. Slices 08-09 cover retention and complete-pilot
MCP/release verification. Tripletex remains future scope. No public website announcement is
appropriate for this incomplete, disabled pilot.
