# Feature Slice: Task Ticket Billing Minimum And Time Authority

Status: Done on Dev / Reviewed
Date: 2026-08-25
Parent RFC: `docs/rfc/2026-08-25-task-stopwatch-and-time-registration.md`
ADR: `docs/adr/2026-08-25-task-actual-time-and-ticket-billing-authority.md`

## Outcome

Task Settings choose whether Ticket-owned Tasks bill actual time or use the estimate as a cumulative
minimum. Task time always remains actual technician time, while linked Ticket rows contain the
customer-billable delta.

## Included

- Task setting and explanatory Admin UI.
- Nullable Task provenance on Ticket time entries.
- Transactional, Task-locked cumulative delta calculation across multiple registrations.
- Worklog exclusion of Task billing projections.
- Clear Client Time labels and direct-edit protection for coupled rows.
- Task, Ticket, Report, Client, RFC, ADR, Knowledge, TODO, and human-review updates.

## Excluded

- Historical backfill.
- Negative billing adjustments or an audited correction UI.
- Changes to Economy settlement, timebank allocation, public APIs, queues, or schedulers.

## Verification

- 30-minute estimate plus 5 actual minutes yields 5 tracked and 30 billed.
- A later 55 actual minutes yields 60 tracked and 60 billed in total.
- Actual mode yields equal tracked and billed totals.
- Technician worklog counts actual Task time once.
- Existing Ticket stopwatch and Economy behavior remain covered by regression tests.
