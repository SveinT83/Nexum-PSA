# Feature Slice: Recurring Task Template Schedules

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-task-templates-and-scheduled-generation.md`
Owner: Codex

## Goal

Generate Task template groups automatically from simple, observable, duplicate-safe schedules.

## User-Visible Behavior

Authorized users configure daily/weekly/monthly/quarterly schedules, target scope, next run, active
state, date/assignee overrides, and Generate now; history shows outcomes and created Tasks.

## Scope

Schedule CRUD, due calculation, target resolution, one bounded catch-up, locking/idempotency,
command/scheduler registration, tests, operations docs, and UI.

## Out Of Scope

Arbitrary cron/RRULE input, backlog storms, round robin, versions, approvals, and rule actions.

## Data Touched

Task recurring templates, generation runs, Tasks, scheduler state, and permissions.

## Permissions

Reuse template management for configuration and add only a distinct manual-run ability if impact
review proves it necessary.

## Tests

Timezone/DST, next-run calculation, overlaps, duplicates, catch-up, inactive targets/templates,
failure history, Generate now, and external scheduler registration evidence.

## Documentation

Task schedules Knowledge and deployment scheduler instructions.

## Done Criteria

Schedules generate exactly once on Dev, operational state is visible, tests pass, and the external
runner remains an explicit deployment gate.

## Dev Evidence

Schedule CRUD, activate/deactivate, Generate now, locked due claims, bounded catch-up, result state,
and every-minute registration are implemented. Dev's external `schedule:run` crontab was installed
and read back; `task.templates.generate_due` completed in the scheduler log. Production runner
verification remains a deployment gate.
