# Feature Slice 02: Manual Workdays, Confirmation And Employee API

Review update (2026-10-05): Svein Tore approved the calendar/modal UX.
Slice 09 now covers the date-entry API context and complete minute-edit workflow;
see [API parity verification](../plans/2026-10-05-workday-entry-api-verification.md).
Remaining combined pilot checks stay In Review in HR-2026-10-01-WORKDAY.

## Modal entry correction (2026-10-05, Svein)

Svein requested actual time entry in a modal opened by clicking the day calendar.
Use the existing Bootstrap modal for new free-time selection and editing saved blocks.
The calendar takes the full available width. Opening or closing the modal does not save;
closing preserves unsaved input on the page, and switching intervals retains the existing
discard guard. Server validation reopens the modal with submitted values and errors.
Minute precision, first-free-hour defaults, overlap rejection, confirmation and API
contracts remain unchanged. Implemented and tested on Dev: all 60 focused Laravel tests
pass across the recorded runs, plus 8 JavaScript tests. Browser fixture checks passed for
open/edit/close, focus return, retained input, overlap, payload preservation and validation reopening.
See [modal verification](../plans/2026-10-05-workday-modal-verification.md).
Human review remains In Review under HR-2026-10-01-WORKDAY.

Minute-precision clarification (2026-10-04, Svein): the one-hour value is only an initial
selection. Arbitrary whole-minute durations are supported. Render each saved interval once,
at its actual vertical start and with height proportional to elapsed duration, including
45-minute meetings. Clicking a block edits its start/end/activity. Reject overlapping time
on both creation and editing; retain exact minutes without hour rounding.

## Calendar timeline correction (2026-10-04, Svein)

Svein clarified that the prior direct Start/End form did not match the intended workspace.
Replace it with a navigable month calendar at the top (today selected initially), followed
by an hourly day timeline showing saved work and selectable free time.
Default selection is the first free interval of up to one hour from the employee's planned
start, after recorded work and absence; 09:00-10:00 becomes 10:00-11:00 once saved.
Selection is separate from persistence. Saving an interval preserves other saved intervals
and breaks through the existing versioned draft action, then selects the next free interval.
Existing confirmed days require the existing correction workflow before editing.
Unknown plans have no invented default start; the employee may choose a visible hour manually.
Retain profile timezone, overnight/DST correctness, private ownership, API contracts, retention
and explicit confirmation. No domain, migration or new API operation is required.
Implemented/tested on Dev: 60 Laravel tests / 467 assertions and 6 JavaScript tests passed.
Synthetic browser edit/overlap/proportion checks passed. Human review is In Review.
See [calendar timeline verification](../plans/2026-10-04-workday-calendar-timeline-verification.md).
The earlier picker-only correction below is superseded for the entry UX.


## Pilot UX correction (2026-10-04, Svein)

The employee workspace opens directly on a selected date with Start/End date-time pickers.
There is no separate create-workday step. Opening a date is read-only; the first explicit save
creates the draft. Saved work takes precedence over suggestions. The existing canonical work
plan, dated Calendar exceptions and simple absence provide editable suggestions only.
Use the same planned intervals for reminders and initial entry; never confirm planned time.
Existing saved profile hours without Calendar projection are read for today/future dates only;
dated rules take precedence and historical schedules are never guessed. Reads create no Calendar.
UserManagement, Calendar and Workday keep their existing ownership. No migration, new
permission or API write contract is needed. Implemented/tested on Dev: 204 tests / 1981 assertions.
See [direct-entry verification](../plans/2026-10-04-workday-direct-entry-verification.md).
HR-2026-10-01-WORKDAY is In Review pending the human recheck; no approval is inferred.


Status: Done On Dev; calendar modal correction tested, human review In Review (2026-10-05).
Date: 2026-10-01
Owner: Codex; product/reviewer: Svein Tore
Parent: [Workday RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Delivery contract: [Implementation plan](../plans/2026-10-01-workday-implementation-plan.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md) - In Review; revised Dev pilot ready for recheck.
Dependencies: Slice 01; approved schema/permission contract in the parent plan.

## Goal

Deliver the complete manual employee workflow through both the browser and personal API.

## User-Visible Behavior

From My Day an employee records intervals, breaks and a description, saves a draft, previews and confirms the day, then makes traceable corrections. A general description across several hours is valid.

## Scope

- Create singular app/Modules/Workday with module-owned routes/controllers/views/actions/queries/tests. Register it through existing loaders; new domain routes never go in routes/web.php or a new routes/ file.
- Implement unique worker/work-date identity, UTC instants plus record timezone/work date, versioned revisions and explicit confirmation. Read proposed logical storage in the parent plan before selecting exact migrations.
- Use confirmed interval duration and explicit included/excluded break treatment for totals. Reject invalid overlaps, including overlap with the worker's adjacent-date records; require explicit offsets for ambiguous local timestamps.
- Keep overnight work attached to its selected work date and show actual start/end dates. Do not duplicate an interval on the next date.
- Provide own day list/read/history, draft upsert, preview, confirm and correction UI/API. A correction preserves the prior confirmed version while a replacement draft is prepared.
- Implement narrowly scoped mutation receipts, optimistic version checks and exact preview/version confirmation; retries return the same identity and persisted result.
- Add complete default-off Workday settings for activation and the agreed three-year retention values. Do not expose unfinished future features. Self-service only; no manager approval or billing changes.

## Out Of Scope

Automatic source imports, absence registration, manager editing, payroll, automatic confirmation, billing, live presence and server-side stopwatch synchronization.

## Data Touched

Proposed Workday day/revision/allocation/receipt records and common settings. Additive migrations; no automatic historical backfill or Task/Ticket changes.
Names for new storage/actions/routes are implementation proposals, not claims of existing tables.
Confirm exact migrations and existing state on authoritative Dev before runtime changes.

## Permissions

workday.view_own, workday.manage_own, workday.confirm_own and workday.manage_settings; scopes workdays.read/write/confirm/settings. Grant employee self-service through explicit internal-user role mapping; keep portal-only/service users out. Settings require the administrative grant. Register scopes only with implemented routes and tests.

## Tests

- An eight-hour interval with an excluded half-hour break totals 450 minutes; included/excluded treatment is explicit and reversible through a revision.
- Cross-midnight/DST duration, duplicate intervals and overlaps with adjacent work dates are verified.
- Version conflict, simultaneous UI/API save, retry and stale preview cannot overwrite or confirm a different draft.
- Ordinary employee/Superuser attempts to mutate another worker's day are denied; no wildcard token or submitted user_id defeats ownership.
- Confirmed correction preserves history and the last confirmed view until explicitly reconfirmed.
- No Task/Ticket/billing/timebank rows change when saving or confirming a day.
- Run the narrow affected Laravel suites on Dev with synthetic fixtures; no local PHP fallback.
- Inspect authenticated UI/API/HTTP read-back for the implemented behavior; an unauthenticated login
  redirect does not prove the feature works.

## Documentation

Create Workday overview/time-registration/API Knowledge; update Warroom My Day links, settings help and the parent human-review checklist.
Update this slice and the parent TODO row in the same session as verification or a concrete blocker.

## Done Criteria

A human employee can complete and correct a manual day entirely through UI or API on Dev, with equivalent persisted read-back and passing authorization/concurrency tests. Feature stays default-off for production.
Record changed files, exact tests/results, migrations/commands, HTTP/UI/API evidence and remaining
human checks. Passing automated tests never marks human review complete. Keep production runtime
off until the required review and separate rollout approval. Do not leave visible stubs for later slices.

## Delivery Evidence (2026-10-02)

Done on authoritative Dev, default-off. See [verification](../plans/2026-10-02-workday-slice-02-verification.md)
for the complete file/data inventory, exact commands, 74 passing tests / 596 assertions, real bearer
and browser HTTP checks, MySQL row-lock probe and nine published API operations.
Both additive Workday migrations were applied on Dev; four new tables are empty. The saved
installation choice and deployment switch remain false. No employee time or production data changed.

All revisions append snapshots. Receipts retain only Workday-owned request/result data with expiry.
Expiry is midnight after the work date's third anniversary in its original timezone; correction
does not extend it. Retention cleanup remains Slice 08 and is required before activation.
HR-2026-10-01-WORKDAY remains Pending; actual interactive browser/MCP and full pilot review remain.
