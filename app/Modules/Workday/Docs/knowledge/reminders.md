Choose personal reminder channels, review a planned workday and snooze without interrupting other work.

## Choose your reminders

When Workday is enabled and you have permission to view and register your own time,
open **Profile > Notifications > Workday**. In-App is enabled by default. Email and Web Push
are off until you choose them. Turn all three channels off to disable Workday reminders.

Email requires the configured system Email account. Web Push requires an available service
and a device you registered in the existing Notifications page. A missing channel is explained
beside its control. You can still disable a previously selected channel if it becomes unavailable.
Workday does not offer message previews or Nextcloud Talk.

## Timing and absence

A reminder is due 15 minutes before the last remaining planned work interval ends, or at the
start of that interval if it is shorter than 15 minutes. Your personal plan, its dated/recurring
exceptions and timezone are used. A night shift belongs to the local date on which it started.

Unknown plans and off-days do not invent working hours. Full absence suppresses the reminder;
partial absence keeps the remaining work interval. Education blocks are planned work, not
absence. Ordinary busy meetings do not change your work plan. Already recorded or legacy confirmed days are skipped,
including when you save through the employee API or receive a validated import. A later draft correction does
not undo the previous confirmation for reminder purposes.

## Open or snooze

The header bell updates quietly. It does not open a dialog, move keyboard focus, navigate away
or submit an unsaved form. An unread reminder remains available on your next visit.

Select the reminder to open a personal page with **Open workday** and **Snooze 30 minutes**.
Snooze is also available directly in the bell. Repeated clicks from another tab do not extend
the same snooze. Each explicit new snooze starts one new reminder generation.

Opening a reminder never registers or confirms time. All actual time still requires your
explicit review and confirmation.

## Privacy and delivery

Messages contain only a generic invitation to review your own day. They contain no work
description, absence category, customer data or manager escalation. The target checks your
current access again. A queued job rechecks your status, permission, plan, absence, preferences
and confirmation before delivery.

A single daily reminder is created across scheduler/job retries. New channel preferences apply
to future reminder generations; disabling a channel also suppresses pending delivery.
External reminders more than twelve hours after planned end are suppressed; the in-app reminder
can remain for your next visit. Discovery covers today and yesterday, including overnight work.
It does not retrospectively generate a backlog for a long scheduler outage.

External submission cannot prove inbox/device receipt. An uncertain external attempt is not
automatically resent. You can still review your workday directly.

## Employee API

Use an attributable personal employee token, not an automation/coordinator identity.

| Operation | Scope |
| --- | --- |
| GET /api/v1/workday-reminders | workday-reminders.read |
| GET /api/v1/workday-reminder-preferences | workday-reminders.read |
| PUT /api/v1/workday-reminder-preferences | workday-reminders.write |
| POST /api/v1/workday-reminders/{id}/snooze | workday-reminders.write |

Preference PUT replaces database_enabled, mail_enabled and web_push_enabled as booleans.
All false disables reminders. Repeating the same PUT is safe. Unsupported channels or enabling
an unconfigured channel returns 422. GET returns persisted preferences and current readiness.

Pending reads return up to ten eligible unread in-app reminders among the fifty most recent
retained unread receipts. Snooze accepts the current generation number. An immediate previous
generation retry returns current state without extending time; other stale versions return 409.
Read back after writing. The generated API specification describes these contracts.
All operations require explicit own Workday permissions and obey the default-off runtime switches.

## Availability

Slice 06 is implemented on Dev with the whole Workday pilot still disabled. Real email/device
receipt and manual browser review remain part of HR-2026-10-01-WORKDAY when the complete pilot
is ready. Production activation is separate. Tripletex synchronization remains future scope.
