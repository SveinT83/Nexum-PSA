# ADR: Separate Confirmed Work Time, Activity Evidence And Billing

## Supersession - 2026-10-05

The [save-and-sync ADR](2026-10-05-workday-tripletex-save-and-sync.md) supersedes the
mandatory confirmation/correction workflow, confirmed-only effective-time reads and
confirmation-dependent Tripletex transfer in this decision. Preserve the original accepted
decision below as history. Source/billing separation, privacy, no inferred time, plan/absence
ownership and three-year retention remain applicable. Current code still implements the
older flow; the new ADR is documentation only until implementation and activation.

## Current Dev Pilot Status (2026-10-03)

Svein authorized a synthetic Dev pilot. Employee access is active; cleanup remains off.
Update 2026-10-05: Svein Tore approved the calendar/modal UX. The date-entry API reuses
the calendar query; 35 operations are verified. HR-2026-10-01-WORKDAY stays In Review
for remaining explicit pilot/operational checks. No domain ownership decision changed.
See [pilot verification](../plans/2026-10-03-workday-dev-pilot-verification.md) and
[review guide](../plans/2026-10-03-workday-dev-pilot-review.md).
The dated default-off implementation evidence below describes earlier delivery state.


Status: Superseded in part on 2026-10-05 (workflow clauses; see supersession above)
Historical status: Accepted on 2026-10-01
Date: 2026-10-01
Decision Makers: Svein Tore (product direction); Codex (architecture)
Related RFC: [Daily Workday Confirmation](../rfc/2026-10-01-daily-workday-confirmation.md)

## Context

Workers need an easy daily account of meetings, development, internal maintenance
and customer work. Nexum already has Task actual time, Ticket billing projections,
calendar plans and activity facts. These describe different things. Treating every
source as elapsed work would double-count time and could turn ordinary logging into
employee monitoring. Page visits and login duration cannot prove working time.

The accepted Task stopwatch design separates actual effort from customer billing.
The accepted Email reporting ADR protects personal evidence and prohibits employee
ranking. A daily workspace must preserve those boundaries.
Svein clarified that this is internal oversight of who works on what using actual
working time, not an invoice basis. On 2026-10-01 he also required Superuser access
through dedicated rights reusable by future HR roles, employee API/MCP time entry,
and notification choices in the user profile. He accepted Workday as the owner of
actual daily registrations alongside the existing UserManagement profile domain.
The employee's own confirmation is sufficient in Nexum; future Tripletex time transfer
must retain the approval step in Tripletex. Svein then limited the current workforce
extension to simple absence and a simple work plan; holiday administration, balances
and advanced rota capabilities are explicitly deferred. Svein set three-year retention
for Workday time/absence records and their associated history.

## Decision

Svein Tore accepted this architecture with the implementation plan on 2026-10-01:

1. A singular Workday domain owns daily working/break intervals, worker confirmation,
   source allocations and auditable revisions. Report remains a cross-domain read model;
   Warroom provides navigation. UserManagement retains identity, profile and normal
   working hours. Reuse current domains rather than duplicating profiles or time engines.
2. Keep three distinct facts: worker-confirmed actual working time; unconfirmed activity/
   calendar evidence; and customer-billable time/Commercial consumption. Never silently
   convert one into another. Absence is separately classified and access-controlled.
3. Calculate daily actual totals from confirmed intervals and break treatment. Reference
   existing actual entries once; labels and billing projections never add elapsed minutes.
   An hourly display is a presentation choice, not one mandatory comment per hour.
4. Calendar and approved business actions may suggest labels/blocks with source provenance.
   Login, page-view, presence and session telemetry do not infer time. Missing evidence
   does not imply missing work. Workers can edit, dismiss and confirm suggestions.
5. AI suggestion generation is optional and cannot autonomously confirm days, invent
   durations or execute domain actions. Employee-directed MCP/AI tools may register,
   correct and explicitly confirm the worker's own reviewed time through the guarded
   domain API, with verifiable employee identity and the same permissions and audit.
   Provider use retains current egress controls.
6. Explicit task creation delegates to existing Task/Ticket actions, with idempotency and
   allocation reconciliation. Daily confirmation does not mutate billing or source time.
   Employee confirmation completes the Nexum day without a second manager approval.
7. Confirmations are versioned. Corrections and changed upstream facts remain visible;
   source refresh cannot overwrite an approved worker statement.
8. Enforce current source authorization and self-only draft visibility. Grant Superuser
   a dedicated permission to read other workers' confirmed time and activities; future
   HR roles may receive that same permission. Use permission checks rather than role-name
   checks. Oversight does not confer other-worker editing, confirmation or raw evidence access.
   Activation requires a documented privacy purpose, recipient/retention choices and
   the applicable employee-information process.
9. Deliver operational employee API parity, including reads, draft writes, corrections
   and explicit version-specific confirmation with idempotency and read-back. PSA owns
   domain APIs and verifies personal bearer access. External LiteLLM/MCP adapter work is
   deferred by Svein on 2026-10-02 and cannot block API completion. API-only time entry must
   not require visiting the browser to finish.
10. Add Workday reminders to Notification's existing registry and Profile > Notifications.
    Honor worker opt-out and supported channel choices, use individual schedules/time
    zones, and recheck preferences and confirmation state before queued delivery.
11. Prepare stable confirmed-time/revision references for future Tripletex transfer.
    Reuse DataExchange mapping/run contracts and Integration connector ownership.
    Keep Nexum confirmation, persisted delivery and Tripletex approval as separate facts.
    Transfer must not approve time in Tripletex. Use explicit employee/activity mapping,
    duplicate prevention, external read-back and visible correction conflicts; do not
    overwrite approved/locked external entries or confirmed Nexum history silently.
    Workday remains usable without the connector.
12. Include a simple Workday absence register with explicit employee registration and
    linked Calendar display. Preserve UserManagement ownership of normal weekly hours
    and Calendar ownership of recurring plan blocks and availability. Workday must not
    duplicate a user profile or calendar engine. Linked absence display delegates to
    source-domain actions; retries and edits preserve one authoritative absence record.
    Keep education, phone-duty availability, absence and actual time separate. Medical
    absence reasons require separate access; shared views expose only unavailable time.
    Full/partial-day absence changes reminder eligibility without requiring sickness
    approval. Extend API parity to the supported plan and absence operations.
13. Defer leave requests/approval, first-come-first-served/capacity rules, mandatory holiday
    periods, annual/carryover/flex balances, advanced rota planning and automatic phone-
    provider queue control. Preserve them as future requirements alongside Tripletex
    integration; expose no unimplemented controls or balance claims in the current scope.
14. Retain Workday time and absence records, descriptions, allocations and associated
    history for three calendar years from the work date or absence-period end. Revisions
    and sync retries do not extend the period. Expiry covers owned copies/projections and
    accounts for restore handling, while preserving source-domain records, active plans
    and Tripletex data governed by their own policies. Both retention settings use three
    years. This documentation decision does not activate cleanup or delete data.

This is one cohesive architectural decision. Individual form fields, buttons, tests,
small adapters and database indexes belong to implementation tasks or Feature Slices,
not a new ADR each. A later change to authority, domain ownership, sensitive-data access
or billing coupling warrants a new decision record.

## Rationale

Worker confirmation provides a useful work-time record while suggestions reduce memory
and typing effort. Preserving provenance prevents minimum billing and mirrored Ticket
entries from inflating actual hours. Separate ownership keeps daily attendance independent
of Task completion and allows APIs to enforce the same workflow as the UI.
Self-service drafting avoids publishing uncertain inferences as employee performance facts.

## Consequences

Positive: simple daily review; no duplicate time entry; manual operation without AI;
traceable corrections; reuse of Task/Ticket billing and current privacy boundaries.

Costs: additive state, reconciliation and concurrency rules; source permissions and
staleness need explicit handling; calendar plans still need worker confirmation.
Existing worklog consumers must not start counting both confirmed days and their linked
Task/Ticket allocations. Tripletex time transfer is agreed future scope; its concrete
provider contract, approval-status capabilities and activation remain separate work.
No Tripletex API capability or live synchronization is claimed by this decision.

## Alternatives Considered

- Login/page-view duration as work time: rejected; unreliable and creates monitoring risk.
- Automatically turn each activity into a timed Task: rejected; duplicates and false precision.
- Require one detailed Task/comment per hour: rejected; administrative friction and no general
  legal requirement for hourly task descriptions.
- Copy all Ticket/Task rows into a second timesheet ledger: rejected; creates competing truth.
- Only aggregate existing Task time: insufficient; meetings, breaks and unspecified work may be absent.
- Put all write logic in Report: rejected; report composition should not own confirmation workflows.
- Require AI for daily registration: rejected; the basic workflow must work deterministically.

## Follow-Up

The [implementation plan](../plans/2026-10-01-workday-implementation-plan.md) and nine
Feature Slices define the approved delivery contract. Svein approved the full RFC,
this ADR and implementation plan on 2026-10-01. Slices 01-09 are Done On Dev (API scope; default-off). The planned
[HR-2026-10-01-WORKDAY](../human-review.md) checklist gates Main/production after Dev
implementation; full runtime review is requested when the complete Dev pilot is ready.
Three-year retention, the absence source and simple-plan scope are agreed; keep their
expiry behavior, Calendar ownership,
linked-record consistency and UI/API permissions covered by the implementation plan.
Superuser oversight, reusable rights, employee API/MCP writes, profile reminder choices,
the domain boundary and employee-only confirmation in Nexum are confirmed requirements.
Future Tripletex transfer retains approval in Tripletex; verify the provider contract
and settle transfer/correction settings before implementing that integration. Update TODO and
indexes together. Verify UI/API authorization parity, profile delivery preferences and
an attributable personal bearer API write with read-back before the API completion handoff.
MCP testing is explicitly deferred; LiteLLM is the intended external direction.
Runtime work requires the project's human-review checklist; this documentation publication
does not mark any existing review complete and requires no migration or activation.

Related decisions: [Email privacy](2026-08-16-email-privacy-preserving-reporting-and-personal-productivity.md)
and [Task time RFC](../rfc/2026-08-25-task-stopwatch-and-time-registration.md).
Future transfer ownership follows the accepted
[Data Exchange ownership ADR](2026-07-03-data-exchange-platform-ownership.md).

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
