# Feature Slice 06: Workday Reminders And Profile Notification Choices

Status: Done On Dev; employee pilot active, human review Pending (2026-10-03).
Date: 2026-10-01
Owner: Codex; product/reviewer: Svein Tore
Parent: [Workday RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Delivery contract: [Implementation plan](../plans/2026-10-01-workday-implementation-plan.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md) - Pending, Dev pilot active; browser verification awaits login.
Dependencies: Slices 01-05.

## Goal

Provide schedule-aware reminders controlled by the employee's existing notification profile.

## User-Visible Behavior

An employee enables/disables the reminder and chooses available channels. Near the expected day end, one reminder offers Open/Snooze; already confirmed or fully absent days are skipped.

## Scope

- Register Workday in NotificationTypeRegistry with supported emitters, defaults and target authorization. Default to in-app only; email and Web Push are opt-in and require existing channel readiness.
- Reuse Profile > Notifications and existing channel/device administration. Implement each offered channel end-to-end; never display an unfinished switch.
- Use the effective personal plan, timezone, full/partial absence and local work date. An overnight plan's reminder belongs to the correct workday.
- Add bounded scheduler discovery and a deduplicated reminder receipt; recheck current user status, preference, permission, absence and confirmation before queued delivery.
- Support Snooze and next-visit prompts without interrupting an unsaved form. Multiple tabs, worker retries and a confirmation through MCP must not repeat reminders.
- Keep payloads generic and personal; no manager escalation or absence reasons in delivery payloads.

## Out Of Scope

New providers, unsolicited test messages to employees, forced browser opening, mandatory reminders and manager escalations.

## Data Touched

Notification registry/settings and Workday reminder receipts plus scheduled command/queue jobs. Use payload-minimized jobs; no raw work descriptions in queue payloads.
Names for new storage/actions/routes are implementation proposals, not claims of existing tables.
Confirm exact migrations and existing state on authoritative Dev before runtime changes.

## Permissions

Own notification settings and an authorized own Workday target. Email/Web Push retain current active-user/device/channel checks; a queued job is not permission to deliver after opt-out.

## Tests

- Profile preference persistence, channel availability/defaults and invalid channel rejection.
- Part-time, weekends, recurrence changes, DST, overnight work and partial/full absence timing.
- Multi-tab/job retries, snooze, opt-out/disablement and API confirmation suppress duplicate or stale deliveries.
- Notification registry/ordinary channel regressions and generic payload checks.
- Scheduler/worker smoke tests with synthetic recipients; verify the external schedule:run runner separately from schedule:list.
- Run the narrow affected Laravel suites on Dev with synthetic fixtures; no local PHP fallback.
- Inspect authenticated UI/API/HTTP read-back for the implemented behavior; an unauthenticated login
  redirect does not prove the feature works.

## Documentation

Update Workday and Notification Knowledge, profile help, scheduler/queue runbook and recovery steps.
Update this slice and the parent TODO row in the same session as verification or a concrete blocker.

## Done Criteria

Synthetic reminders have exactly-once logical scheduling, current-state delivery checks and read-back; real channel receipt stays a human/device check. No production channel settings are changed.
Record changed files, exact tests/results, migrations/commands, HTTP/UI/API evidence and remaining
human checks. Passing automated tests never marks human review complete. Keep production runtime
off until the required review and separate rollout approval. Do not leave visible stubs for later slices.

## Delivery evidence

Slice 06 delivery (2026-10-02): personal plan-aware reminders, Profile notification choices and four own API operations are Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-06-verification.md) records 219 distinct tests / 2091 assertions, synthetic channel delivery, verified external scheduler and one additive Dev migration. Both switches remain off and no employee notifications were sent. Next: Slice 07. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.

Runtime, recovery and later activation commands are in the [operations guide](../plans/2026-10-02-workday-reminder-operations.md).
