# RFC: Scheduled Tickets and SLA Deferral

## Problem

Planned work can currently be represented as a normal ticket and receive immediate SLA due timestamps before the scheduled work should begin.

## Goals

- Support one-time scheduled tickets.
- Support recurring scheduled tickets.
- Show schedule metadata in UI and API.
- Prevent premature SLA timers.
- Preserve existing SLA due dates unless a user explicitly recalculates them.
- Link Calendar events deterministically and auditably.
- Define scheduler behavior for missed, cancelled, and completed occurrences.

## Non-goals

- Replacing the Calendar recurrence engine.
- Replacing Ticket workflow.
- Full dispatch/field-service optimization.
- Automatic route planning.

## Issue #236 assessment

This is a **Level 3 change** for Nexum PSA because it affects:

- Ticket data model.
- SLA calculation semantics.
- Calendar linkage.
- Scheduler behavior.
- API/UI contract.
- Auditing/history.
- Existing ticket due-date preservation.

So I would **not implement this directly without an approved RFC/feature-slice plan**. The requested behavior is absolutely valid, but it touches too many cross-module contracts to safely treat as a narrow fix.

## Recommended target behavior

Support scheduled tickets/work orders as a first-class ticket scheduling layer, not as a calendar-only workaround.

A ticket should be able to have:

- `schedule_type`: none, one-time, recurring.
- `planned_start_at`.
- `planned_end_at` or planned duration.
- `timezone`.
- Optional recurrence metadata.
- Optional deterministic calendar event linkage.
- SLA mode:
    - **defer SLA until planned start**, or
    - **non-SLA scheduled/waiting state** until explicitly started.
- Audit trail for schedule creation, update, cancellation, occurrence generation, calendar linking, and SLA decisions.

## Suggested RFC direction

Create an RFC like:

```markdown
# RFC: Scheduled Tickets and SLA Deferral

## Problem

Planned work can currently be represented as a normal ticket and receive immediate SLA due timestamps before the scheduled work should begin.

## Goals

- Support one-time scheduled tickets.
- Support recurring scheduled tickets.
- Show schedule metadata in UI and API.
- Prevent premature SLA timers.
- Preserve existing SLA due dates unless a user explicitly recalculates them.
- Link Calendar events deterministically and auditably.
- Define scheduler behavior for missed, cancelled, and completed occurrences.

## Non-goals

- Replacing the Calendar recurrence engine.
- Replacing Ticket workflow.
- Full dispatch/field-service optimization.
- Automatic route planning.
```


## Proposed implementation slices

### Slice 1 — One-time scheduled tickets with SLA deferral

**Goal:** Fix the immediate pain safely.

Scope:

- Add schedule fields to tickets or a dedicated `ticket_schedules` table.
- Add UI controls for one-time scheduled work.
- Add API fields/resources.
- Add schedule display to ticket show/index.
- Add SLA calculation rule:
    - If ticket is scheduled for the future, do not assign new first-response/resolve due timestamps before `planned_start_at`, unless explicitly requested.
- Do **not** overwrite existing SLA timestamps silently.
- Add audit events.

Recommended first-pass data model:

```plain text
ticket_schedules
- id
- ticket_id
- schedule_type: one_time|recurring
- planned_start_at
- planned_end_at
- timezone
- recurrence_rule nullable
- recurrence_ends_at nullable
- calendar_event_id nullable
- sla_mode: defer_until_planned_start|non_sla_until_start|normal
- status: scheduled|active|completed|cancelled|missed
- metadata json nullable
- created_by
- updated_by
- timestamps
```


Why a separate table? It avoids bloating tickets, makes recurrence/occurrence expansion cleaner, and gives a proper auditable scheduling aggregate.

### Slice 2 — Calendar linkage

**Goal:** Deterministic ticket/calendar relationship.

Scope:

- Link a scheduled ticket to a specific calendar event.
- Store the linkage on the schedule record.
- Ensure updates happen through a defined action/service.
- Record audit events when:
    - calendar event created,
    - linked,
    - rescheduled,
    - unlinked,
    - cancelled.

Important rule:

> Calendar linkage should not be “best guess by same date/title”. It should use stored IDs and explicit work context metadata.

### Slice 3 — Recurring scheduled tickets

**Goal:** Support recurring work orders without duplicating uncontrolled tickets.

Two possible models:

#### Recommended model: recurring template + generated occurrences

- Parent ticket or schedule template defines recurrence.
- Scheduler generates concrete ticket occurrences within a lookahead window.
- Each occurrence has its own lifecycle, assignments, SLA, notes, and completion state.
- Completion/cancellation does not mutate the recurrence template except where explicitly requested.

This is safer than trying to make one ticket represent many visits.

Example behavior:

```plain text
Recurring scheduled ticket:
  "Monthly firewall health check"

Generated occurrence:
  "Monthly firewall health check - 2026-09-01"
  "Monthly firewall health check - 2026-10-01"
```


### Slice 4 — Scheduler behavior

Define clear rules:

| Case | Recommended behavior |
|---|---|
| Future scheduled ticket | Remains scheduled/waiting; SLA not running. |
| Planned start reached | Scheduler activates SLA or transitions ticket, depending on `sla_mode`. |
| Missed occurrence | Mark occurrence as missed or overdue-scheduled; do not silently close. |
| Cancelled occurrence | Keep audit trail; do not generate SLA timers. |
| Completed occurrence | Mark occurrence complete; next recurrence unaffected. |
| Recurrence end reached | Stop generating future occurrences. |
| Existing SLA timestamps present | Preserve unless user explicitly recalculates. |

## SLA behavior recommendation

Use an explicit policy instead of hidden magic:

```plain text
SLA mode:
- normal
- defer_until_planned_start
- non_sla_until_start
```


Recommended default for scheduled tickets:

```plain text
defer_until_planned_start
```


Then:

- SLA timestamps are calculated from `planned_start_at`, not ticket creation time.
- Existing timestamps are not overwritten unless the user chooses **Recalculate SLA from planned start**.
- If a ticket is already overdue before being scheduled, the UI should warn the user.

## UI behavior

Ticket create/edit should expose a **Schedule** section:

```plain text
Schedule
[ ] This is scheduled work

Schedule type:
(o) One-time
( ) Recurring

Planned start:
[2026-08-27 09:00]

Planned end:
[2026-08-27 13:00]

SLA handling:
(o) Start SLA from planned start
( ) Keep ticket outside SLA until manually started
( ) Use normal SLA immediately

Calendar:
[ ] Create/link calendar event
Calendar: [Technician calendar]
```


Ticket show should display something like:

```plain text
Scheduled work
Planned start: 27 Aug 2026 09:00
Planned end: 27 Aug 2026 13:00
SLA: Starts from planned start
Calendar event: Linked
Recurrence: None
```


For recurring tickets:

```plain text
Recurring scheduled work
Frequency: Monthly
Next occurrence: 1 Sep 2026 09:00
Generated through: 31 Dec 2026
```


## API fields

Ticket API/resource should expose schedule metadata:

```json
{
  "schedule": {
    "type": "one_time",
    "planned_start_at": "2026-08-27T09:00:00+02:00",
    "planned_end_at": "2026-08-27T13:00:00+02:00",
    "timezone": "Europe/Oslo",
    "sla_mode": "defer_until_planned_start",
    "status": "scheduled",
    "calendar_event_id": 123,
    "recurrence": null
  }
}
```


## Tests needed

At minimum:

- Scheduled ticket can be created.
- Scheduled ticket can be updated.
- Scheduled metadata appears in API.
- Scheduled metadata appears in UI.
- Future scheduled ticket does not receive premature SLA due times.
- Existing SLA due times are preserved when adding a schedule.
- Explicit SLA recalculation works only when requested.
- Calendar event linkage is created deterministically.
- Calendar linkage update is audited.
- Recurring schedule generates occurrences.
- Cancelled recurrence occurrence does not start SLA.
- Missed occurrence behavior is deterministic.
- Completed occurrence does not regenerate duplicate work.

## Documentation updates needed

This should update:

- Ticket Knowledge documentation.
- SLA documentation.
- Calendar/ticket integration documentation.
- Scheduler/queue operational notes.
- `docs/TODO.md` or relevant beta plan if implemented in slices.
- `docs/human-review.md` with a human review checklist.

## Human review requirement

Because this changes operational ticket and SLA behavior, it needs a human review entry with checks like:

- Create one-time scheduled ticket.
- Confirm SLA does not start before planned date.
- Confirm existing SLA due dates are preserved.
- Link scheduled ticket to calendar event.
- Reschedule and verify audit history.
- Create recurring ticket and verify generated occurrences.
- Cancel/miss/complete occurrences and verify expected scheduler behavior.

## My recommendation

Implement this in this order:

1. **RFC approval** for scheduled tickets and SLA semantics.
2. **Slice 1:** one-time scheduled tickets + SLA deferral + no silent overwrite.
3. **Slice 2:** deterministic calendar linkage.
4. **Slice 3:** recurring scheduled ticket templates and generated occurrences.
5. **Slice 4:** scheduler hardening for missed/cancelled/completed occurrences.

This avoids trying to solve recurrence, SLA, calendar, and workflow all in one risky pass.

**Focused question before implementation:**  
Should the default scheduled-ticket SLA behavior be **“defer SLA until planned start”**, or should scheduled tickets default to a **non-SLA waiting state until a technician manually starts work**?
