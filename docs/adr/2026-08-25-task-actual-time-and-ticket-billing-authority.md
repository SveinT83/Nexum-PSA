# ADR: Task Actual Time And Ticket Billing Authority

Status: Accepted
Date: 2026-08-25
Decision owners: Svein Tore / Codex
Related RFC: `docs/rfc/2026-08-25-task-stopwatch-and-time-registration.md`

## Context

A Ticket-owned Task must represent two valid but different amounts: actual technician effort and
customer-billable time. Storing the same row in both Task and Ticket already causes worklog reports
to add both records. An estimate-minimum policy makes that ambiguity materially incorrect.

## Decision

- `task_time_entries` is authoritative for technician actual time on Tasks.
- `ticket_time_entries` remains authoritative for customer billing, contract rates, timebanks, and
  Economy processing.
- A Task-originated Ticket billing row has nullable `task_id` provenance and `type = task_billing`.
- Technician worklog queries exclude Ticket rows with Task provenance and count the Task row once.
- Billing is reconciled cumulatively per Task under a locked Task row. The system creates only a
  positive delta between the configured desired total and existing linked Ticket billing total.
- Coupled Task actual and Ticket billing rows are not directly editable from Client Time Usage.

## Consequences

The same Task can safely show 5 actual minutes and 30 billable minutes. Multiple sessions do not
repeat the minimum, and later actual time above the estimate increases billing only to the new actual
total. Existing unlinked Ticket time keeps its current behavior. A future correction workflow must
reconcile both sources explicitly rather than editing one side in isolation.
