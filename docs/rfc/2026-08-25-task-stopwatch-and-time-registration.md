# RFC: Task Stopwatch And Time Registration

Status: Approved / Implemented
Date: 2026-08-25
Owner: Svein Tore / Codex
Change level: Level 3 Task/Ticket billing, reporting, data, and shared UI behavior
GitHub issue: #232
Implementation approval: Approved by Svein Tore on 2026-08-25

## Context

Ticket details provide a browser-local stopwatch that prefills the Ticket time form. Task details
already have Task time records and documented manual-time behavior, but expose neither a stopwatch
nor a manual registration endpoint. Ticket-owned Tasks additionally need Ticket to remain the
billing, contract, and timebank source of truth.

## Goals

- Give Task details the same start, pause, resume, and stop experience as Ticket details.
- Reuse one shared Blade component for stopwatch presentation and browser-draft behavior.
- Keep Ticket and Task validation, persistence, activity, and billing rules domain-owned.
- Let standalone and Client-owned Tasks save non-billable Task time.
- Let Ticket-owned Tasks save pending Ticket time and mirror it to Task time.
- Keep Task time authoritative for technician actual time while Ticket time remains authoritative for
  customer billing.
- Let Task Settings choose actual customer billing or the Task estimate as a cumulative minimum.
- Avoid requiring or creating a duplicate Ticket time entry when a timed Task is later completed.
- Keep elapsed timer state browser-local until the technician submits the registration form.

## Non-Goals

- No server-side running-timer table, cross-device synchronization, queue, or scheduler.
- No Task-index active-timer highlighting.
- No new public API endpoint, permission redesign, billing settlement, or timebank deduction.
- No automatic Task completion when the timer stops or time is saved.

## Proposed Change

Add a shared `time.stopwatch` Blade component. It owns only the timer display, local-storage state,
button behavior, elapsed-minute rounding, target-modal opening, and optional audited start request.
Ticket keeps its existing start action, time form, and storage key. Task uses its own storage key and
does not create a server record until its Task-owned form is submitted.

Add a Task time-registration action and route. Standalone and Client-owned Tasks create a manual,
non-billable `task_time_entries` row plus internal Task activity. For Ticket-owned Tasks, the Task
row always records the technician's actual session minutes. A linked pending Ticket entry records
only the customer-billable delta and remains available for later billing/timebank handling.

Task Settings expose two billing modes for Ticket-owned Tasks: `actual`, or `estimate_minimum`.
The minimum calculation locks the Task and compares cumulative actual Task minutes with cumulative
linked Ticket billing minutes. With a 30-minute estimate, a first 5-minute session creates 5 Task
minutes and 30 Ticket billing minutes. When cumulative actual time reaches 60 minutes, only the
additional 30 Ticket minutes are created. The estimate is never applied separately to every session.

Add nullable `task_id` provenance to `ticket_time_entries` and use `type = task_billing` for new
projections. Economy continues to consume the Ticket rows. Technician worklog reporting excludes
Task-originated Ticket rows because the linked Task rows already hold actual work. The Client Time
surface labels the two meanings separately and prevents direct edits that could break the coupling.

A Ticket-owned Task that already has actual registered time may complete without registering the
same time again. A Ticket-owned Task without actual time keeps the existing completion form and
must supply its work date, minutes, rate, and invoice text.

## Impact Analysis

### Affected Areas

- Shared Blade components: stopwatch presentation and local browser behavior.
- Task: settings, route, controller, action, detail view, tests, and Knowledge documentation.
- Ticket: replace duplicated stopwatch markup/script with the shared component while preserving
  its existing persistence and audited start behavior; add Task provenance to billing rows.
- Report: exclude Task billing projections from technician worklog totals.
- Clients: distinguish Task tracked time from Task billing and protect linked rows from direct edits.
- Economy: no code change; linked Ticket rows remain normal pending billable input.

### Permissions And Security

- The Task time route requires the existing `task.update` permission in addition to authenticated
  Task access.
- Ticket-owned Task time still passes through `TicketActionGuard` and `ticket.register_time`.
- The server resolves rate options for the owning Ticket; browser-supplied rate metadata is never
  trusted.
- Completed Tasks reject new timer-backed time registration server-side.

### Data And Runtime

- Add nullable `ticket_time_entries.task_id` with a null-on-delete foreign key and lookup index.
- Existing Ticket time rows remain unlinked and retain their current reporting/billing behavior.
- No data backfill, queue, scheduler, frontend build, or new package.
- The existing Vite bundle is unchanged because the shared script is emitted once by Blade.

### Risks

- Shared UI refactoring could regress the Ticket timer; Ticket feature coverage must verify the
  preserved storage key, audited start URL, modal target, and submit-time reset.
- Ticket-owned Task registration could duplicate billing time; completion tests must prove existing
  actual time is not registered again.
- Multiple Task sessions could apply the estimate more than once; cumulative totals and a locked Task
  row prevent repeated minimum billing.
- Direct edits could desynchronize actual and billable time; coupled rows are read-only in Client Time
  until an audited reconciliation workflow is implemented.
- Local browser state is not cross-device state and must continue to be described as a draft.

## Testing Plan

- Task detail renders the shared stopwatch, Task-specific storage key, modal target, and form.
- Standalone Task registration creates one manual non-billable Task entry and internal activity.
- Estimate-minimum mode proves 5 actual / 30 billed and later 60 actual / 60 billed across sessions.
- Actual mode proves 5 actual / 5 billed even when the estimate is 30 minutes.
- Task billing rows carry Task provenance and remain eligible for Economy billing.
- Worklog endpoints count Task actual time once and exclude the linked billing projection.
- Completing a timed Ticket-owned Task creates no duplicate time entry.
- Completed Tasks reject further time registration.
- Existing Ticket stopwatch and time-registration regressions pass.
- Task and Ticket Blade views compile successfully.

## Documentation And Human Review

Update Task time Knowledge documentation, Ticket time documentation, `docs/TODO.md`, the RFC index,
and `docs/human-review.md`. Human review must verify both Task ownership modes, Ticket regression,
