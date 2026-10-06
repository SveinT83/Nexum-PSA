# RFC: Daily Workday Confirmation

## Approved target revision - 2026-10-05

The [automatic Workday/Tripletex RFC](2026-10-05-workday-tripletex-automatic-time-sync.md)
supersedes this RFC's mandatory employee confirmation/reconfirmation and confirmation-gated
sync target. Explicit Save will record effective actual time; imports and edits will synchronize
automatically in both directions, with duration-only entries and optional clock times.
Payroll approval remains in Tripletex. This change is approved product direction, not implemented
behavior. The chronological delivery and earlier decisions below are preserved as history.
Current Dev still uses the old workflow until the new plan is implemented and activated.
Unchanged source/billing, privacy, absence/plan and retention boundaries continue to apply.

## Calendar API parity follow-up (2026-10-05, approved by Svein Tore)

Svein approved the calendar/modal UX and requested complete API support for a later Nexum PSA
MCP server. Continue Slice 09 under the existing approved API-parity scope.
Add GET /api/v1/workdays/{work_date}/entry with workdays.read and workday.view_own:
read the selected date without creating it, returning the persisted day or null, version,
record timezone, effective planned/free intervals, first free up-to-one-hour suggestion,
adjacent own reservations and edit/correction capability. Reuse the calendar timeline query.
Plan/suggestion data is never a recorded interval or employee confirmation.
Existing versioned draft replacement provides add/edit/remove of minute intervals and breaks.
Preserve idempotency, explicit preview/confirmation, ownership, retention and overlap rules.
Publish a typed OpenAPI contract and complete consumer examples; verify personal bearer
create/edit/remove/retry/conflict/read-back and route-to-spec parity.
Impact: Workday query/routes/OpenAPI/tests/docs; shared expiry calculation is reused by reads
and draft creation. No new storage, grants, credentials, scheduler, queue or external integration.
MCP implementation remains a later task. Status: Done On Dev.

API follow-up verified on Dev (2026-10-05): 35 published operations; 13 API tests /
432 assertions and 60 regression tests / 475 assertions passed (73 tests / 907 assertions).
GET workdays/{work_date}/entry supplies the calendar context without creating actual time.
Existing draft replacement was verified for minute create/add/edit/remove, retries, overlap,
stale versions, preserved breaks and read-back. UI approval by Svein Tore is recorded.
See [API parity verification](../plans/2026-10-05-workday-entry-api-verification.md).

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


## Current Dev Pilot Status (2026-10-04)

Svein authorized a synthetic Dev pilot. Employee access is active; cleanup remains off.
Svein Tore approved the calendar/modal UX on 2026-10-05. HR-2026-10-01-WORKDAY
stays In Review for the remaining explicitly listed API/device/operational checks.
See [pilot verification](../plans/2026-10-03-workday-dev-pilot-verification.md) and
[review guide](../plans/2026-10-03-workday-dev-pilot-review.md).
The dated default-off implementation evidence below describes earlier delivery state.


Status: Approved
Date: 2026-10-01
Owner: Svein Tore / Codex
Change level: Level 3 - cross-domain time, permissions, API, calendar and reminders
Related ADR: [Separate confirmed work time, evidence and billing](../adr/2026-10-01-workday-time-evidence-and-billing.md)

## Context

Internal maintenance, development, meetings and small customer jobs are easily left
unrecorded. Requiring a separate detailed task for every small activity adds friction.
Nexum needs a simple personal end-of-day workspace that helps the worker remember,
correct and confirm the day. It must work even when no activity can be imported.
This is a shared workflow for everyone, not a mechanism targeting an individual.
Its purpose is internal follow-up and a named overview of who works on what, grounded
in each worker's actual working intervals and breaks. Workday records are not an
invoice basis. Contracted hours and calendar plans remain context, not actual hours.

Svein agreed the product direction and requested this RFC and ADR on 2026-10-01.
Svein Tore approved the full RFC, ADR and concrete implementation plan on 2026-10-01
and explicitly authorized implementation. This approval does not claim runtime completion.

## Goals

- Confirm actual daily working intervals and breaks with little administrative work.
- Reuse existing recorded time and offer editable calendar/activity suggestions.
- Allow one description across several hours and several activities within one hour.
- Keep internal work, customer billing, absence and activity evidence distinguishable.
- Support the internal-task policy without entering the same description/time twice.
- Make confirmation, corrections, source provenance and missing evidence explicit.
- Give Superuser access to confirmed worker time and activities through dedicated permissions
  that can also be assigned to future HR roles.
- Let employees read, register, correct and explicitly confirm their own time through the
  domain API, including later authorized use from LiteLLM/MCP and AI tools.
- Provide Workday reminder choices in Profile > Notifications.
- Include simple individual work plans and explicit self-registered absence, reusing
  existing UserManagement working hours and Calendar availability and display.
- Complete the Nexum workday through the employee's own confirmation, without a second
  manager approval step.
- Prepare confirmed time for a future Tripletex transfer/sync where approval takes place
  in Tripletex under its own workflow; preserve this distinction in the data contract.

## Non-Goals

- Employee ranking, productivity scoring or inferences about whether someone worked.
- Monitoring every page visit, login, keystroke, presence heartbeat or external session.
- Inferring hours from first/last activity, task creation timestamps or session duration.
- Automatically approving time, billing customers, closing tasks or creating tasks.
- Inferring sickness from inactivity or calendar titles; importing diagnoses or private details.
- A new payroll engine, overtime entitlement calculation or PSA-owned MCP server.
- Leave applications/approval, first-come-first-served allocation, maximum simultaneous
  holiday rules, mandatory holiday periods, annual holiday allowances/carryover balances,
  flex-time accrual/balances, advanced rota planning or automated phone-provider queue
  control in the current delivery. These are deferred target capabilities.

## Current Behavior

Authoritative Dev inspection on 2026-10-01 found:

- Task has manual time registration and a browser-local stopwatch. Stopping opens a
  form; time is persisted only when saved. See the approved 2026-08-25 Task stopwatch RFC.
- Task actual minutes and Ticket billing projections can differ. Five actual minutes
  may create a thirty-minute billing minimum. Task-originated Ticket projections are
  excluded from technician worklog totals to avoid duplication.
- Tasks can also receive estimate-derived time on completion. An estimate is not
  independently verified actual working time.
- Report provides a cross-domain worklog read model; the 2026-09-27 controlled-history
  work defines bounded queries and separate Commercial timebank consumption.
- Warroom already provides My Day; Calendar owns events and UserManagement owns
  working-hour preferences. None proves that an event was attended or a shift worked.
- Profile > Notifications already uses a Notification-owned event registry for supported
  channels, descriptions, defaults, server validation and current delivery preferences.
- The existing role is named `Superuser`. RoleSeeder currently assigns it all ordinary
  permissions; the new domain must still enforce record ownership and source visibility.
- There is no verified day-confirmation ledger in the inspected scope. Historical
  worklog API work is not evidence that this proposed feature is deployed.
- The profile form already exposes weekly working hours with enabled/start/end per day.
  Calendar has recurring events and availability rules/overrides. These are reusable
  foundations, not proof of a completed rota or absence-register workflow.
- Current company policy, reported by Svein, is to record absence in personal calendars.
  There is no separate absence source to import. The inspected Telephony scope covers
  call intake, not verified automatic queue membership control.
- Existing DataExchange plans reserve Tripletex provider profiles for future work. This
  planning review does not verify an operational Workday-to-Tripletex time connector
  or its employee/activity mapping, write permissions or approval-status API.

Related specifications:

- [Task time registration](2026-08-25-task-stopwatch-and-time-registration.md)
- Dev-only reference: `docs/rfc/2026-09-27-controlled-history-and-commercial-time-export.md`
  was read in the authoritative working copy but is not present on the published
  Dev branch at this documentation baseline; it is not included in this commit.
- [Email reporting privacy](../adr/2026-08-16-email-privacy-preserving-reporting-and-personal-productivity.md)
- [Internal-work policy](https://doc.tronderdata.no/books/policyer/page/policy-for-registrering-av-internt-arbeid-i-nexumpsa)

## Proposed Change

### Personal daily workflow

Add a Workday workspace reachable from My Day. Use English interface labels until
localization is explicitly in scope. The worker selects a date and confirms actual
start/end intervals and breaks. Planned working hours may prefill a visibly unconfirmed
draft, never a completed day. Support split shifts, overnight work and local time zones.

Display an hourly timeline as a convenient view, not a requirement for one comment
per hour. A block may cover multiple hours. An hour may include multiple labels or
time allocations; labels do not each count as sixty minutes. General day-level work
such as "Work - unspecified" remains valid when the chosen workflow permits it.
Specific Ticket/Task documentation is still required for billing or explicit follow-up.

The worker reviews the following source classes:

| Source | Presentation | Authority |
| --- | --- | --- |
| Manually recorded Task/Ticket actual minutes | Existing time, linked once | Source domain |
| Task completion estimate | Unconfirmed estimate | Worker must correct/confirm |
| Calendar occurrence | Suggested meeting block | Planned, not proof of attendance |
| Allowed business action, e.g. completed goods receipt | Suggested label with source | No inferred duration |
| Manual description | Worker-authored block | Worker confirmation |
| Explicit absence record | Separate restricted absence block | Authorized absence workflow |

Existing entries without interval timestamps remain date-level minutes until the
worker places them. Never manufacture a start time from created_at or a comment.
Examples: a two-hour development block needs one description; a mixed hour can contain
twenty minutes of customer support and forty minutes of internal maintenance.

"Confirm day" records the worker's confirmation, actual intervals, break treatment,
descriptions and source allocations. Svein confirmed on 2026-10-01 that this employee
confirmation is sufficient in Nexum: there is no additional manager approval queue
or requirement. Superuser and explicitly authorized roles use the oversight view.

Confirmation does not imply payroll export, overtime authorization, completed customer
work or permission to invoice. Future Tripletex transfer and Tripletex approval have
their own states; neither is a prerequisite for a confirmed Nexum workday.

### Simple work plans and absence: agreed current scope

Svein explicitly limited this extension on 2026-10-01 to simple absence and a simple
work plan, with the remaining workforce/holiday capabilities deferred. The previously
agreed actual-time workflow, API/MCP parity, oversight permissions and profile reminders
remain in scope.

Reuse UserManagement as the owner of individual normal weekly working hours and time
zone. Build the simple plan from those hours plus Calendar-owned recurring plan blocks
and dated exceptions. Allow a recurring education day and explicit operational availability,
such as exemption from phone duty that day. Planned education, actual worked time and
phone availability are separate facts: an education block must not automatically create
absence, actual hours or a salary entitlement. Plan revisions must not rewrite historical
confirmed days. This is a weekly plan with exceptions, not a rotating-shift optimizer.

Workday owns the simple absence register. The employee explicitly records the absence
type and date/time range, with full-day or partial-day support, and can correct/cancel
their own record with history. Include illness, already agreed holiday, agreed time off
in lieu and other absence. Recording illness is a notification of absence, not a leave
application waiting for manager approval. Recording agreed holiday/time off does not
grant leave or calculate an available balance.

Reuse Calendar for displaying linked absence blocks and effective availability, so the
current calendar-based overview continues. A Workday absence record is the authoritative
source of its linked block; creating, editing and cancelling it must be idempotent and
keep the calendar representation consistent. Calendar edits to a linked block must
delegate to the same authorized absence action or direct the user to the source record.
Do not maintain two independently editable copies or infer medical absence from calendar
titles. Existing calendar-only events remain Calendar-owned unless explicitly linked
through an authorized action; do not silently convert or backfill them.

Expose these supported plan and absence operations through domain-owned APIs with the
same permissions as the UI. Reuse existing profile and Calendar actions for their data;
Workday owns absence actions and API. Ordinary shared calendar/work views receive only
the required unavailable interval. Absence reasons require dedicated authorization;
the general confirmed-work oversight grant does not reveal sickness details. Do not
collect diagnoses or medical attachments for this simple register.

Use the effective plan and explicit absence to calculate expected availability and
reminder timing. Full-day absence suppresses the day reminder; partial-day absence
preserves the remaining work interval. Confirmed actual work remains employee-authored.
Show contradictory work/absence overlaps for correction rather than silently adjusting
either record. Any future phone-system adapter must distinguish configured queue
availability from a provider-confirmed queue change; automatic queue control is deferred.

### Deferred workforce and holiday capabilities

Retain the full future target: leave requests and approval; configurable first-come-first-
served priority and maximum simultaneous holiday absences; mandatory holiday periods;
annual holiday allowances, carried-over/remaining holiday, flex-time accrual and balances;
advanced rota planning; and Tripletex synchronization. Do not implement their settings,
calculators, approval queues or stub controls in the current scope.

Before those capabilities are implemented, agree which system owns each balance and
approval, verify Tripletex capabilities and decide provider-independent behavior.
Simple absence records must retain stable IDs/types/dates for later mapping. They do
not claim to be official holiday balances or a complete HR/sickness case-management system.

### Named oversight and reusable permissions

Svein confirmed on 2026-10-01 that Super User must see other workers' confirmed time
and activities, with dedicated rights that can later be assigned to HR roles.
Use the existing `Superuser` role and permission system. Access checks must use
permissions rather than hardcoded role names.

Proposed permission names for the implementation contract:

| Permission | Scope |
| --- | --- |
| `workday.view_own` | Read the worker's own drafts, confirmed days and revision history. |
| `workday.manage_own` | Register and correct the worker's own intervals, breaks, descriptions and allocations. |
| `workday.confirm_own` | Explicitly confirm the worker's own reviewed day/version. |
| `workday.view_all` | Read identified confirmed days, actual totals, activity descriptions and confirmed revision history within the installation. |

Grant the oversight right to Superuser as part of this feature. Other roles receive it
only through explicit assignment; future HR roles can reuse it without changing the
domain. Own-record operations always enforce worker identity, including for Superuser.
The oversight right does not grant editing or confirmation on another person's behalf,
manager approval, private drafts, raw suggestions, private source content or restricted
absence details. Source links continue to require their source-domain permissions.
Broader correction or recipient scoping must be an explicit later decision. A Nexum
manager-approval step is not part of the agreed workflow.

The overview shows the confirmed date, worker, actual time, breaks and what the time
was allocated to. Unallocated time remains visible without implying that no work took
place. It is a record of confirmed work, not a live presence or productivity score.

### Employee API, MCP and AI tools

API support is part of the required delivery, not an optional read-only follow-up.
The API must expose the supported employee workflow: list/read own days and history;
create/update draft intervals, breaks and activity allocations; review sources and
dismiss suggestions; preview and explicitly confirm a specific version; and correct
a confirmed day through an auditable revision. Explicit task conversion must use the
same guarded domain action when that workflow is delivered. Include the simple absence
workflow and authorized simple-plan operations using their respective owning domains.

Use separate read, write and confirmation abilities and the corresponding user
permissions. Other-worker confirmed reads additionally require the oversight grant
and a deliberately authorized API scope. Generic report/worklog access is insufficient.
Resolve the worker from the authenticated employee identity; accepting an arbitrary
`user_id` or a shared privileged token is not employee delegation. Every API consumer must
carry an authenticated, attributable employee context that PSA can verify.

UI and API use the same validation, ownership, source access, reconciliation,
version/conflict checks and domain actions. Writes require idempotency and return a
stable identity, version and persisted state for read-back. Confirmation applies to
the exact reviewed version and requires the employee's explicit instruction; saving
an AI-generated draft never silently confirms it. Corrections retain history.

Svein revised the external direction on 2026-10-02: LiteLLM replaces NexumMCP and MCP
implementation/testing is deferred. PSA delivers the complete domain APIs, OpenAPI, scopes,
errors, workflow-specific retry semantics and a consumer handoff with personal bearer tests.
No live gateway or tool session is required for API completion. PSA owns no MCP server;
the earlier [ownership decision](../adr/2026-10-01-separate-nexummcp-ownership.md) retains that boundary.

### Reminders and profile choices

Use the worker's configured working day/end time and time zone. Show one in-app prompt
near day end, with Open and Snooze actions. Do not interrupt an unsaved form or active
customer operation. A closed browser cannot be forced open; show the pending prompt at
the next visit. Off-days, explicitly recorded full-day absence and already confirmed
days suppress reminders; a sickness notification does not wait for manager approval.
Deduplicate per user, local date and reminder generation. Register a Workday reminder
event in Notification's authoritative registry and expose it under Profile > Notifications.
Workers can enable/disable the reminder and select its supported, configured delivery
channels, including in-app, email and Web Push where available. Default to in-app;
external channels remain opt-in. Reuse channel readiness and device registration rules.
Only expose a channel when the complete emitter, delivery and authorization path exists.

Derive reminder timing from the worker's configured working days, end time and time zone,
with Snooze for the current reminder. Planned hours control reminder timing only;
actual time still requires worker confirmation. Do not assume all workers follow the
same full-time schedule. Partial absence must not suppress a remaining work interval.

Immediately before delivery, recheck the preference, employee status, relevant permission
and whether the day still needs confirmation. Opt-out or API/UI confirmation suppresses
queued reminders. Delivery content is generic and links to the authorized personal day.
No manager escalation is introduced by the oversight permission.

### Sources, matching and optional AI

Start with existing time, explicit calendar selection and manual text. Business-action
adapters may later supply bounded, authorized facts. A saved stock movement can suggest
"Inventory handling"; merely opening inventory cannot. Automation/service accounts and
shared-account events cannot be assigned to a worker without reliable identity evidence.

Suggestions are private to the worker by default and retain source type, stable ID,
revision and reason. Allow edit, dismiss, split, merge and link to existing work.
Missing, forbidden, truncated or unavailable sources appear as incomplete; absence of
evidence never becomes a claim of missing work. Use existing pagination/completeness contracts.

AI is optional for grouping labels, drafting short descriptions and suggesting matches.
The full workflow must work without AI. Built-in suggestion generation cannot execute
domain mutations or autonomously confirm time. Separately, an employee may instruct an
authenticated MCP/AI tool to save or confirm their own reviewed time through the guarded
API above. The employee remains the authorizing actor; tool use does not bypass scopes,
permissions or explicit confirmation.
Any provider use must pass current installation/workload/data-egress policy; introducing
this feature does not authorize exporting employee or calendar data to a model.

### No duplicate actual time or billing

Daily actual duration is derived from confirmed working intervals with explicit break
treatment, not from summing events, descriptions, calendar blocks and billing rows.
Reconcile imported actual-minute allocations against that duration. Flag over-allocation
and overlaps for correction; do not silently add, clamp or guess. Allow documented concurrent
activities without counting elapsed time twice. Unallocated working time remains visible.

Preserve Task/Ticket provenance, exclude Task-originated billing mirrors, and keep direct
Commercial timebank consumption and minimum billing quantities outside actual-time totals.
An eight-hour day with two imported task hours contains eight hours, not ten.

"Create task from activity" is an explicit worker action through Task-owned guards.
Reuse description and time, link an existing entry when supported, or atomically replace
the manual allocation with the new source reference. Use an idempotency key and read-back.
Neither page refresh nor repeated confirmation may create another task/time entry.
Changing the day alone never rewrites source Task/Ticket time or billing.

### Future Tripletex time transfer and approval

Svein requested future sending/synchronization of recorded hours to Tripletex so that
employees do not have to enter the same time again. The employee confirms in Nexum;
the transferred time must still follow approval in Tripletex. Nexum confirmation must
never be translated into Tripletex approval or automatically invoke an approval action.
This is a future integration requirement, not authorization to activate live sync now.

Workday owns confirmed actual time, allocations, revisions and the permission-aware
source contract. UserManagement continues to own employee identity and normal working
hours. Reuse Integration's connector/credential ownership and DataExchange's existing
mapping/run/delivery contracts under the accepted
[Data Exchange ownership ADR](../adr/2026-07-03-data-exchange-platform-ownership.md).
The future connector contract must explicitly identify the target Tripletex company,
employee and time/activity mapping. Do not infer identity from names or reuse billing
projections as worked hours. Missing mappings must stop the affected transfer visibly.

Design the confirmed-time contract with stable record and revision IDs, work date/time
zone, actual duration, relevant allocations and provenance. Keep Nexum confirmation,
transfer/delivery state and external approval state distinct. A successful request alone
does not prove a saved entry or approval. Link created external entry IDs and verify the
persisted result. Display Tripletex approval only from an authoritative supported
read-back; unavailable approval information remains unknown.

Use a stable source-to-external mapping so retries do not create duplicate hours.
Reconcile edits against the exact previously transferred revision. A Nexum correction
does not silently rewrite an already approved or locked Tripletex entry; surface the
difference for the provider's supported correction/reapproval workflow. External edits
or approval do not overwrite the employee's confirmed Nexum history. Transfer failure
does not undo Nexum confirmation. Reuse actual time once and exclude mirrored Ticket
billing, minimum billable quantities and duplicate Task allocations.

Before implementing the connector, verify current Tripletex time-entry and approval
capabilities and settle the mapping granularity, manual/automatic trigger, recipient
scope and conflict handling in its integration contract. Initial direction is confirmed
time outward from Nexum, with persisted entry/approval status read back where supported;
general two-way time editing is not implied. Keep the connector disabled until configured
and verified. The full Workday UI/API workflow must work while the connector is absent.

### Calendar, absence and correction

Import only explicitly selected, permitted work calendars. Identify recurrence occurrences,
handle cancellations and changed durations, and omit declined meetings. Overlapping events
are alternatives to review, not cumulative hours. A calendar update after confirmation
flags stale evidence; it never overwrites the confirmed day.

Absence is not actual work time. The agreed simple Workday register is the source for
new explicit absence records, with linked Calendar display as described above. Sickness
requires employee registration through the restricted absence action; no diagnosis or
medical calendar text enters the general worklog. Existing Calendar-only absence events
keep their source ownership until explicitly linked. Do not expand ordinary calendar,
manager or report permissions through this feature.

Confirmed days use auditable revisions. A worker correction creates a new revision with
actor/time and changed values; optimistic concurrency prevents lost updates. Source changes
mark affected references stale and require reconciliation. Retain confirmation history
for the agreed three-year retention period rather than silently rewriting it.

### Retention: three years

Svein set the retention period to three years on 2026-10-01. Apply it to Workday
working-time records, absence records and their associated descriptions, allocations,
source snapshots and revision history. The separate time/absence retention settings
both use the agreed three-year policy.

Use three calendar years from the relevant work date or the end of the absence period,
in the record's time zone. A correction, re-confirmation or synchronization retry must
not restart the retention clock. Define and test the exact cutoff, including leap dates,
in the implementation slice.

Implement a bounded, repeatable expiry workflow that removes the expired Workday data
and its owned copies, including linked absence projections, without retaining the same
personal details indefinitely in revision history or export artifacts. Source references
do not authorize deleting original Task/Ticket time, unrelated Calendar events, user
profiles or Tripletex entries; those remain subject to their owning systems' policies.
Current recurring work plans are not expired merely because they were created three
years ago. Include backup/restore handling so expired Workday data is not silently
reintroduced into the active application.

This records the product retention decision. No existing records are deleted and no
cleanup job is activated as part of this documentation update.

## Impact Analysis

Proposed ownership: a singular Workday domain owns daily drafts, confirmed intervals,
allocations, revisions, confirmation actions and the simple absence register. It must
not become a second Ticket/Task
time or billing engine. Report composes read-only totals; Warroom links to the workspace.
Ticket, Task, Calendar, Commercial and any business-action adapter retain their own
authorization and source semantics. Notification owns delivery. UserManagement owns
identity and existing schedule preferences. Notification's registry also owns Workday
event preferences displayed in the existing profile. Integration owns API authentication,
abilities and consumer contracts; external tooling is deferred (LiteLLM direction). Svein accepted
the Workday/UserManagement separation in the follow-up discussion. Workday reads existing
profile and schedule data rather than creating another user profile. The simple plan
reuses Calendar recurrence/availability and UserManagement weekly hours; it does not
introduce a competing schedule engine. Linked absence display and its API contract
affect Calendar, while actual absence source records and actions belong to Workday.

Future Tripletex delivery reuses DataExchange mapping/run ownership and Integration
connector ownership, with Workday as the authoritative source of confirmed actual time.
The connector's concrete implementation contract remains future work.

UI, API and future external consumers must use the same domain actions, revision checks,
idempotency and per-user authorization. Define exact routes/scopes in the first approved
Feature Slice; do not broaden current coordinator/worklog grants automatically.
Workers can manage their own days. Superuser receives the dedicated confirmed-time
oversight grant; future HR roles can receive the same permission explicitly. Raw
suggestions and restricted absence remain separate. API parity is required for every
delivered employee operation, including write and explicit confirmation actions.
Existing Email self-only reporting and no-ranking decisions remain unchanged.

The feature is employee-data processing even when helpful. Before activation, document
purpose, lawful basis, employee information/consultation as applicable, recipient scope,
the agreed three-year retention policy and source necessity. Daily confirmation does not itself authorize surveillance.

## Data And Migration Plan

Propose additive Workday storage for user/date/time-zone, work/break intervals, status,
versioned confirmation and source allocations. Keep stable source/revision identifiers so
a future transfer can link external entries without making Tripletex fields mandatory
for ordinary Workday use. Do not build speculative connector tables in the initial
Workday migration. Include the simple absence identity, classification, interval,
revision and Calendar link needed by the approved scope. Reuse existing schedule data;
do not add holiday/flex balance or advanced rota tables.
Store source references and minimal snapshots, not raw security logs, mailbox contents,
tokens, diagnoses or medical attachments. Absence classification has separate access controls. Use a unique
worker/day identity, generation deduplication and transactional confirmation.

No historical time is silently converted into confirmed days. Optional historical drafts
must be explicitly requested and retain unknown intervals/estimate provenance. Roll out
behind a default-off feature setting after additive migrations, permissions and tests.
Disabling hides prompts and stops imports while preserving confirmed records and export
access. Do not use destructive down-migrations on confirmed time. No migration, deployment,
backfill, provider change or runtime activation is part of this documentation change.

## Testing Plan

- Eight-hour day plus two imported hours still totals eight; linked billing mirrors count zero extra.
- Five actual minutes with thirty billed minutes contributes five actual minutes.
- Multiple activities in one hour and one description across several hours preserve elapsed totals.
- Estimate-only entries, timestamps without duration and missing sources remain unconfirmed.
- Calendar recurrence/cancellation, duplicate imports, declined meetings and overlaps are handled.
- Breaks, overnight intervals, DST, split shifts and non-working days are covered.
- Confirmation/retry/concurrent edits and task conversion are idempotent and transactional.
- Post-confirmation source edits preserve history and flag stale references.
- Other-user, calendar, source-domain, absence and model-egress access is denied appropriately.
- Reminder snooze, multi-tab deduplication, missed visits and unsaved forms behave safely.
- AI disabled or unavailable still permits full manual confirmation.
- Superuser can read named confirmed work through the dedicated permission; an explicitly
  granted HR-style test role can do the same and an ungranted role cannot.
- Oversight never permits another worker's draft, mutation, confirmation or restricted source.
- Employee API registration, correction and confirmation match UI results; foreign user IDs,
  wrong scopes, unattributable actors and stale versions are rejected.
- Repeated MCP/API writes and confirmation retries produce one recorded change, verified by read-back.
- Profile preferences persist; unsupported channels cannot be enabled; opt-out, permission removal
  and a day confirmed through the employee API suppress queued delivery.
- Part-time, split-shift, overnight and partial-absence schedules produce appropriate reminders.
- Employee confirmation finishes the Nexum workflow without a manager approval or external-sync gate.
- A weekly plan plus a recurring education block can mark phone duty unavailable without
  creating absence or actual time; changes preserve historical confirmed days.
- Simple plan/absence UI and API enforce the same own-record and administrative permissions.
- Self-registered full/partial-day sickness affects availability/reminders immediately,
  without requiring manager approval or exposing its reason to ordinary calendar viewers.
- Create/update/cancel/retry keeps one linked Calendar representation per absence record;
  Calendar edits cannot bypass source-domain authorization or create conflicting copies.
- Existing Calendar-only absence is not automatically converted; work/absence overlap
  remains visible for correction.
- Recording agreed holiday or time off in lieu does not approve a request, calculate a
  balance or trigger Tripletex transfer/phone-provider actions.

- Retention keeps unexpired records and removes expired Workday data with its owned
  copies/history at the three-year cutoff; corrections and retries do not extend it.
- Expiry is repeatable, handles time zones/leap dates and preserves original source-domain
  records, external Tripletex entries and active recurring work plans.
- Backup restoration cannot silently restore expired Workday records into active use.

Future Tripletex connector acceptance checks:

- Only confirmed, authorized actual time is eligible, with explicit company/employee/activity mapping.
- Transfer never calls approval; Nexum confirmation and external approval remain separate.
- Read-back proves stored external time; missing approval evidence is reported as unknown.
- Retry, partial failure and correction do not duplicate hours or overwrite locked/approved entries.
- Missing mappings and conflicts remain visible; failed delivery preserves the confirmed Nexum day.
- Billing minimums and mirrored Task/Ticket rows never inflate transferred actual time.

Before runtime release, create the human-review checklist required by the project and
verify worker/manager roles, the mixed-activity day, correction flow and billing regression.
This documentation-only change requires link/content checks, not Laravel runtime tests.

## Documentation Plan

Maintain this RFC, its ADR, both indexes and TODO. After implementation, add Workday
Knowledge and a short SOP, update My Day and Task/Ticket help, and clarify that internal
task documentation complements daily work-time registration. Document the permission matrix,
profile reminder choices and full employee API contract; prepare a provider-independent
consumer guide and personal bearer verification. LiteLLM/MCP adapter work follows separately. Add a simple-plan/absence SOP explaining the
existing profile hours, linked Calendar display, partial-day absence, education blocks,
reason visibility and deferred holiday/rota features. Document the future Tripletex transfer boundary
and employee mapping, including separate confirmation/delivery/approval states, when its
connector contract is prepared. Do not describe proposals
as available functionality in BookStack. Keep policy text generic and free of employee cases.

## Implementation Plan

The [implementation plan](../plans/2026-10-01-workday-implementation-plan.md) records the
verified Dev starting point, domain/data/API/permission contracts, execution order,
tests, operations and rollback. The target is agreed; all implementation slices below
are approved for sequential implementation; Slices 01-08 are Done On Dev (default-off) and 06-09 are Ready.

- [01 - Simple Work Plan And One Working-Hours Source](../feature-slices/2026-10-01-workday-01-simple-work-plan.md) (Done On Dev, default-off)
- [02 - Manual Workdays, Confirmation And Employee API](../feature-slices/2026-10-01-workday-02-manual-days-and-api.md) (Done On Dev, default-off)
- [03 - Simple Absence And Consistent Calendar Display](../feature-slices/2026-10-01-workday-03-absence-and-calendar.md) (Done On Dev, default-off)
- [04 - Existing Time And Calendar Evidence Reconciliation](../feature-slices/2026-10-01-workday-04-source-reconciliation.md) (Done On Dev, default-off)
- [05 - Confirmed-Time Oversight And Reusable Rights](../feature-slices/2026-10-01-workday-05-confirmed-oversight.md) (Done On Dev, default-off)
- [06 - Workday Reminders And Profile Notification Choices](../feature-slices/2026-10-01-workday-06-profile-reminders.md) (Done On Dev, default-off)
- [07 - Explicit Internal Task Conversion Without Duplicate Time](../feature-slices/2026-10-01-workday-07-explicit-task-conversion.md) (Done On Dev, default-off)
- [08 - Three-Year Retention And Recovery Operations](../feature-slices/2026-10-01-workday-08-three-year-retention.md) (Ready)
- [09 - Employee API Contract And Consumer Handoff](../feature-slices/2026-10-01-workday-09-mcp-and-release-verification.md) (Done On Dev, API scope; default-off)

The plan includes an explicit prerequisite: reconcile the existing profile weekly hours
and preference-driven Calendar defaults without overwriting custom employee schedules.
API parity is delivered within each slice. The planned human-review checklist is
[HR-2026-10-01-WORKDAY](../human-review.md); it is not ready to execute before implementation.
It gates Main promotion/merge and production migration/deployment/activation, while
separately approved Dev-only implementation and tests can proceed.

## Open Questions

Superuser oversight through dedicated reusable permissions, employee API/MCP write support,
profile reminder choices and the Workday/UserManagement boundary were settled by Svein
on 2026-10-01. He also confirmed that employee confirmation is sufficient in Nexum and
that future Tripletex time transfer must retain approval in Tripletex. He subsequently
chose simple work plans and explicit Workday absence registration with linked Calendar
display; holiday administration, balances and advanced rota features wait until later.
Svein also set Workday time/absence retention to three years. Do not reopen these as
missing product decisions.

The product decisions raised in this discussion are settled. The detailed
[implementation contract and delivery plan](../plans/2026-10-01-workday-implementation-plan.md)
was approved on 2026-10-01. Its nine implementation slices retain the full
agreed target, including employee API parity, reusable oversight rights and
profile-controlled reminders. Optional AI suggestions, additional business-action
sources and the future Tripletex connector must not be prerequisites for manual/API time entry.

Before the future Tripletex connector is implemented, separately settle transfer timing,
target mappings and provider correction/locking behavior against verified capabilities.
This does not introduce a manager approval step inside Nexum.

Norwegian legal context, checked 2026-10-01: actual working time generally needs a written,
current record, including start/end and breaks; task comments per hour are not a general
statutory requirement. Exemptions and overtime treatment need installation-specific review.
Sources: [Arbeidstilsynet](https://www.arbeidstilsynet.no/arbeidstid-og-organisering/arbeidstid/registrering-av-arbeidstid/)
and [Datatilsynet](https://www.datatilsynet.no/personvern-pa-ulike-omrader/personvern-pa-arbeidsplassen/overvaking-av-ansattes-bruk-av-elektronisk-utstyr/nar-er-overvakingen-forbudt/).

## Approval

Svein Tore agreed the conversational product direction and requested documentation on
2026-10-01. In the follow-up, Svein explicitly confirmed Superuser oversight with dedicated
permissions reusable by future HR roles, employee time entry through API/MCP/AI tools,
and notification choices in the user profile. He accepted the Workday/UserManagement
boundary and confirmed that the employee's own confirmation is sufficient in Nexum.
He also requested future Tripletex time transfer/sync, with approval performed in
Tripletex. He then explicitly limited the added workforce scope to simple absence and
a simple work plan, with holiday administration, balances and advanced rota deferred.
He also set three-year retention for Workday time and absence records and associated
history. These product requirements are agreed.

Svein requested the concrete implementation plan on 2026-10-01. That documentation
request is fulfilled by the linked plan and nine Feature Slices. Svein then explicitly
approved it and authorized implementation on 2026-10-01. Slices 01-08 are Done On Dev (default-off);
retention is settled. Production and human-review gates remain separate.
Tripletex implementation remains a future integration
slice and is not a dependency for releasing the complete manual/API Workday workflow.
Implement complete slices covering confirmed manual days and API parity;
reusable oversight permissions; source-time reconciliation; simple plans and absence
with Calendar consistency; calendar/profile reminders; explicit task conversion; and
optional governed activity/AI assistance. Deferred holiday/rota/saldo work is not a
prerequisite for these slices.

Implementation progress (2026-10-02): Slices 01-08 are Done On Dev, default-off. [Slice 01 verification](../plans/2026-10-01-workday-slice-01-verification.md) records the work-plan delivery; [Slice 02 verification](../plans/2026-10-02-workday-slice-02-verification.md) records 74 passing tests / 596 assertions, the manual UI/API and two additive Dev migrations. Slice 09 is Done On Dev with the approved API scope. Full pilot, human review and Main/production remain separate.

Slice 03 delivery (2026-10-02): own absence and neutral Calendar projection are implemented/tested on Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-03-verification.md) records 121 tests / 960 assertions and six API operations. Two additive Dev migrations were applied; no other-worker reason access, leave approval/balance, external sync, phone action or Tripletex runtime was added. HR-2026-10-01-WORKDAY remains Pending.

Slice 04 delivery (2026-10-02): source reconciliation is Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-04-verification.md) records 271 distinct passing tests / 2236 latest assertions, two source API operations and one additive Dev migration. Source minutes stay within actual totals; original Task/Ticket/Calendar and billing data are unchanged. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending; Svein requested manual review only when the complete pilot actually needs it.

Slice 05 delivery (2026-10-02): confirmed overview, detail/history, Report discovery and reusable oversight rights are Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-05-verification.md) records 136 passing tests / 1392 assertions, three API reads and the Superuser-only Dev permission migration. Existing tokens were not changed. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. Human review remains Pending until the complete pilot is ready.

Slice 06 delivery (2026-10-02): personal plan-aware reminders, Profile notification choices and four own API operations are Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-06-verification.md) records 219 distinct tests / 2091 assertions, synthetic channel delivery, verified external scheduler and one additive Dev migration. Both switches remain off and no employee notifications were sent. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.

Slice 07 delivery (2026-10-02): explicit internal Task conversion is Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-07-verification.md) records 187 distinct tests / 1618 assertions, three API operations, transactional duplicate prevention and one additive Dev migration. No Task/time/billing fixtures or token changes were retained. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.

Slice 08 delivery (2026-10-02): three-year cleanup and restore operations are Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-08-verification.md) records 228 distinct passing tests after the reminder metadata assertion update, original-source preservation, synthetic restore rehearsal and a fresh native employee-row lock probe. Metadata-only UI/API preview is implemented. WORKDAY_RETENTION_ENABLED is independent and false; no live purge, migration, employee activation or token change occurred. Dev inventory has zero Workday roots and 105 historical diagnostic copies, so restore_ready correctly remains false pending approved cleanup. External backup rotation/archive handling remains an explicit pre-activation check. Next: combined Dev pilot and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY stays Pending until complete-pilot review.

Slice 09 scope revision and delivery (2026-10-02, approved by Svein): MCP is deferred; LiteLLM
replaces NexumMCP as the intended external direction. Complete PSA APIs are the immediate
requirement. [Verification](../plans/2026-10-02-workday-slice-09-api-verification.md) records 34 published operations with exact
scope metadata and 196 passing tests / 1912 assertions. The provider-independent API consumer
guide is delivered; no adapter, live tool connection or production activation is claimed.
HR-2026-10-01-WORKDAY remains Pending for the combined pilot and Main/production review.
