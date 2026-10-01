# ADR: Separate Confirmed Work Time, Activity Evidence And Billing

Status: Proposed
Date: 2026-10-01
Decision Makers: Svein Tore (product direction); Codex (proposed architecture)
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

## Decision

Propose the following architecture under the linked Draft RFC:

1. A singular Workday domain owns daily working/break intervals, worker confirmation,
   source allocations and auditable revisions. Report remains a cross-domain read model;
   Warroom provides navigation. Reuse current domains rather than duplicating their time engines.
2. Keep three distinct facts: worker-confirmed actual working time; unconfirmed activity/
   calendar evidence; and customer-billable time/Commercial consumption. Never silently
   convert one into another. Absence is separately classified and access-controlled.
3. Calculate daily actual totals from confirmed intervals and break treatment. Reference
   existing actual entries once; labels and billing projections never add elapsed minutes.
   An hourly display is a presentation choice, not one mandatory comment per hour.
4. Calendar and approved business actions may suggest labels/blocks with source provenance.
   Login, page-view, presence and session telemetry do not infer time. Missing evidence
   does not imply missing work. Workers can edit, dismiss and confirm suggestions.
5. AI is optional assistance. It cannot approve days, assign invented durations, create
   tasks, close work or bill customers. Provider use retains current egress controls.
6. Explicit task creation delegates to existing Task/Ticket actions, with idempotency and
   allocation reconciliation. Daily confirmation does not mutate billing or source time.
7. Confirmations are versioned. Corrections and changed upstream facts remain visible;
   source refresh cannot overwrite an approved worker statement.
8. Enforce current source authorization, self-only draft visibility and explicit grants
   for other-worker confirmed time. Do not inherit raw employee evidence access from a
   generic manager/report/admin role. Activation requires a documented privacy purpose,
   recipient/retention choices and the applicable employee-information process.

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
Task/Ticket allocations. Payroll/export integration remains a separate approved contract.

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

Review and approve the RFC and this Proposed ADR, settle access/retention/absence decisions,
and define bounded Feature Slices before implementation. Update TODO and indexes together.
Runtime work requires the project's human-review checklist; this documentation publication
does not mark any existing review complete and requires no migration or activation.

Related decisions: [Email privacy](2026-08-16-email-privacy-preserving-reporting-and-personal-productivity.md)
and [Task time RFC](../rfc/2026-08-25-task-stopwatch-and-time-registration.md).
