# RFC: Daily Workday Confirmation

Status: Draft
Date: 2026-10-01
Owner: Svein Tore / Codex
Change level: Level 3 - cross-domain time, permissions, calendar and reminders
Related ADR: [Separate confirmed work time, evidence and billing](../adr/2026-10-01-workday-time-evidence-and-billing.md)

## Context

Internal maintenance, development, meetings and small customer jobs are easily left
unrecorded. Requiring a separate detailed task for every small activity adds friction.
Nexum needs a simple personal end-of-day workspace that helps the worker remember,
correct and confirm the day. It must work even when no activity can be imported.
This is a shared workflow for everyone, not a mechanism targeting an individual.

Svein agreed the product direction and requested this RFC and ADR on 2026-10-01.
This draft records the full target and implementation boundaries; it does not claim
that the feature exists or authorize implementation of newly proposed architecture.

## Goals

- Confirm actual daily working intervals and breaks with little administrative work.
- Reuse existing recorded time and offer editable calendar/activity suggestions.
- Allow one description across several hours and several activities within one hour.
- Keep internal work, customer billing, absence and activity evidence distinguishable.
- Support the internal-task policy without entering the same description/time twice.
- Make confirmation, corrections, source provenance and missing evidence explicit.

## Non-Goals

- Employee ranking, productivity scoring or inferences about whether someone worked.
- Monitoring every page visit, login, keystroke, presence heartbeat or external session.
- Inferring hours from first/last activity, task creation timestamps or session duration.
- Automatically approving time, billing customers, closing tasks or creating tasks.
- Inferring sickness from inactivity or calendar titles; importing diagnoses or private details.
- A new payroll engine, overtime entitlement calculation or PSA-owned MCP server.

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
- There is no verified day-confirmation ledger in the inspected scope. Historical
  worklog API work is not evidence that this proposed feature is deployed.

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
descriptions and source allocations. It does not imply manager approval, payroll export,
overtime authorization, completed customer work or permission to invoice.

### Reminders

Use the worker's configured working day/end time and time zone. Show one in-app prompt
near day end, with Open and Snooze actions. Do not interrupt an unsaved form or active
customer operation. A closed browser cannot be forced open; show the pending prompt at
the next visit. Off-days, approved absence and already confirmed days suppress reminders.
Deduplicate per user, local date and reminder generation. Push/email requires separately
configured existing notification channels; default is in-app only, with no manager escalation.

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
The full workflow must work without AI. AI cannot confirm time or issue domain mutations.
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

### Calendar, absence and correction

Import only explicitly selected, permitted work calendars. Identify recurrence occurrences,
handle cancellations and changed durations, and omit declined meetings. Overlapping events
are alternatives to review, not cumulative hours. A calendar update after confirmation
flags stale evidence; it never overwrites the confirmed day.

Absence is not work time. Sickness requires explicit registration through an approved
restricted workflow; no diagnosis or medical calendar text enters the general worklog.
Do not expand ordinary calendar, manager or report permissions through this feature.

Confirmed days use auditable revisions. A worker correction creates a new revision with
actor/time and changed values; optimistic concurrency prevents lost updates. Source changes
mark affected references stale and require reconciliation. Retain confirmation history
according to the adopted retention policy rather than silently deleting or rewriting it.

## Impact Analysis

Proposed ownership: a singular Workday domain owns daily drafts, confirmed intervals,
allocations, revisions and confirmation actions. It must not become a second Ticket/Task
time or billing engine. Report composes read-only totals; Warroom links to the workspace.
Ticket, Task, Calendar, Commercial and any business-action adapter retain their own
authorization and source semantics. Notification owns delivery. UserManagement owns
identity and existing schedule preferences. This ownership is proposed for review.

UI, API and future external consumers must use the same domain actions, revision checks,
idempotency and per-user authorization. Define exact routes/scopes in the first approved
Feature Slice; do not broaden current coordinator/worklog grants automatically.
Workers can manage their own days. Access to another worker's confirmed time requires
an explicit role/scope decision; raw suggestions and restricted absence remain separate.
Existing Email self-only reporting and no-ranking decisions remain unchanged.

The feature is employee-data processing even when helpful. Before activation, document
purpose, lawful basis, employee information/consultation as applicable, recipient scope,
retention and source necessity. Daily confirmation does not itself authorize surveillance.

## Data And Migration Plan

Propose additive Workday storage for user/date/time-zone, work/break intervals, status,
versioned confirmation and source allocations. Store source references and minimal
snapshots, not raw security logs, mailbox contents, tokens or medical data. Use a unique
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

Before runtime release, create the human-review checklist required by the project and
verify worker/manager roles, the mixed-activity day, correction flow and billing regression.
This documentation-only change requires link/content checks, not Laravel runtime tests.

## Documentation Plan

Maintain this RFC, its ADR, both indexes and TODO. After implementation, add Workday
Knowledge and a short SOP, update My Day and Task/Ticket help, and clarify that internal
task documentation complements daily work-time registration. Do not describe proposals
as available functionality in BookStack. Keep policy text generic and free of employee cases.

## Open Questions

Before implementation approval, settle access to other workers' confirmed days, any
manager approval/export locking, the approved absence source and retention periods.
Recommended initial scope is worker self-service, in-app reminders and no AI/external
business-activity collection. Exact fields/indexes/API contracts belong to Feature Slices.

Norwegian legal context, checked 2026-10-01: actual working time generally needs a written,
current record, including start/end and breaks; task comments per hour are not a general
statutory requirement. Exemptions and overtime treatment need installation-specific review.
Sources: [Arbeidstilsynet](https://www.arbeidstilsynet.no/arbeidstid-og-organisering/arbeidstid/registrering-av-arbeidstid/)
and [Datatilsynet](https://www.datatilsynet.no/personvern-pa-ulike-omrader/personvern-pa-arbeidsplassen/overvaking-av-ansattes-bruk-av-elektronisk-utstyr/nar-er-overvakingen-forbudt/).

## Approval

Svein Tore agreed the conversational product direction and requested documentation on
2026-10-01. This RFC remains Draft because the concrete ownership, authorization,
retention and implementation contract have not yet been approved. No coding is started.
After approval, implement complete slices: confirmed manual days; source-time reconciliation;
calendar/reminders; explicit task conversion; optional governed activity/AI assistance.
