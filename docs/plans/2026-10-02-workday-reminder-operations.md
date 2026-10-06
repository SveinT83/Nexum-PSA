# Workday Reminder Operations And Recovery

Applies to approved Slice 06. Dev implementation only; Workday remains disabled.
Human-review checklist HR-2026-10-01-WORKDAY is Pending and blocks Main/production rollout,
not the approved additive Dev test migration. Svein requested review at complete-pilot readiness.

## Runtime and storage

- Both WORKDAY_ENABLED and common_settings workday/manual_workflow.enabled must be true.
- Run the single additive migration 2026_10_02_200000_create_workday_reminder_receipts.
- workday_reminders is unique by user_id/work_date; explicit snooze advances generation.
- workday_reminder_deliveries is unique by reminder_id/generation/channel. Jobs contain only deliveryId.
- workday_reminder_cursors stores the bounded discovery position and last_scanned_at.
- Canonical Notification database rows use the receipt's notification_id, one per generation.
- Discovery processes at most 200 users and 200 pending deliveries per tick, today and yesterday
  in each worker's zone. A durable cursor rotates through installations larger than one batch.
- Pending delivery rows survive broker-dispatch failure and are rediscovered on subsequent ticks.
- Per-user locks serialize receipt changes, own preference writes, actual-time confirmation and
  absence updates. Database notification creation and delivery completion share a transaction.
- Mail freezes the configured system-account/provider binding at generation creation and uses
  EmailAccountMailChannel. Web Push reuses readiness, own devices and target authorization.
- External I/O follows a committed attempt claim. Submitted means the channel call returned,
  not proof of inbox/device receipt. Claims with an unknown result are never automatically resent.

## Deployment checks

After reviewed code/schema promotion:

~~~bash
umask 0002
php artisan migrate --path=database/migrations/2026_10_02_200000_create_workday_reminder_receipts.php --force
php artisan optimize:clear
php artisan l5-swagger:generate
php artisan queue:restart
php artisan schedule:list
~~~

No frontend asset build is needed for these Blade/Livewire changes. Verify the existing process
supervisor restarts default-queue workers. A queue restart is required before future activation;
it was not sent to shared workers during the disabled Dev implementation.

Separately verify an external OS/Plesk/systemd job runs php artisan schedule:run every minute in
the application directory. schedule:list alone cannot establish that. On Dev the current-user
crontab contains the minute job for /var/Projects/tdPSA and cron is active; default-queue workers
are present. No OS scheduler or provider configuration was changed.

The isolated command php artisan workday:reminders --limit=200 is safe to inspect while disabled:
it reports enabled=false/scanned=0/queued=0. Do not invoke the global schedule:run merely to test
this feature: it also runs unrelated integration jobs.

## Synthetic verification

Use the isolated Laravel test harness and synthetic recipients for command, serialized worker,
current-state suppression and channel contract checks. Provider adapters must be replaced in
tests; do not send unsolicited employee messages. Actual email/push receipt needs a specifically
opted-in pilot user/device and explicit manual confirmation in the human checklist.

## Recovery

- pending: inspect default queue and scheduler health, then allow normal bounded recovery.
- delivered: the in-app notification was stored atomically.
- submitted: external channel submission completed; do not assume receipt.
- suppressed: current preferences, permission, user status, absence, confirmation or external
  catch-up age made delivery ineligible. Do not reset it to pending automatically.
- blocked: configuration/provider binding/device readiness prevented submission. Correct the
  existing channel configuration. A future new reminder generation uses the current configuration.
- unresolved or attempting after a worker timeout: external outcome may be unknown. Never reset
  the state or blindly retry. Investigate provider evidence without exposing employee payloads.
  The employee can still open Workday directly or explicitly snooze an eligible reminder.

Restore must not replay old submitted, unresolved or attempting deliveries. Keep Workday disabled,
retain claims, remove expired owned data through Slice 08's reviewed procedure, and validate
scheduler/worker state before activation. Do not delete source Calendar/Task/Ticket records.

## Retention and rollback

All reminder generations, delivery receipts and linked canonical Notification copies share the
three-calendar-year deadline anchored to work_date. Snooze/delivery/retry does not extend it.
Expired reminders are inaccessible now; physical cleanup and restore orchestration belong to
Slice 08. Queued stale IDs must become harmless no-ops after cleanup.

Disable Workday first to stop discovery and deliveries. Preserve receipts and Notification copies
for the approved retention procedure. The down migration refuses to erase retained reminders.
Do not roll back the ledger while leaving a delivery path enabled.
