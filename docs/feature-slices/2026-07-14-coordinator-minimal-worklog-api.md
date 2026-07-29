# Feature Slice: Coordinator Minimal Worklog And Stale Activity API

Status: Done
Date: 2026-07-14
Parent: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`
Owner: Codex

## Goal

Expose useful read-only coordination data through aggregate/pseudonymized responses without names or
free text by default.

## User-Visible Behavior

An approved coordinator can query technician worklog summaries, recent time entries, stale Tickets,
and stale Tasks within configured limits. Default responses use aggregates or workload-scoped aliases
and omit natural identifiers and free text.

## Scope

- Add `GET /api/v1/worklog/technicians` and `GET /api/v1/worklog/time-entries` through Report.
- Add stale Ticket and Task read endpoints owned by their source modules.
- Add `worklog.read` and `time-entries.read` abilities with explicit read metadata.
- Apply policy evaluation, workload-token binding, context filters, limits, and audit.
- Return stable documented pagination and filtering contracts.

## Out Of Scope

- Names, Client names, Ticket/Task titles, messages, notes, descriptions, invoice text, attachments,
  write actions, rankings, or productivity scores.

## Data Touched

Read-only access to Ticket and Task time/activity data; no source-record migration or mutation.

## Permissions

Sanctum abilities, workload policy, installation/provider/model/agent limits, Work Context, and the
authenticated actor's existing visibility all apply; the most restrictive result wins.

## Tests

- Scope, policy, context, authorization, pagination, filter, limit, and audit coverage.
- Contract tests for aggregate/pseudonymized projections.
- Negative assertions for all names, titles, contact details, free text, billing text, attachments,
  rankings, credentials, and secrets.
- Report, Ticket, Task, Integration, and API regression tests.

## Documentation

Add OpenAPI and Knowledge contracts for the four endpoint families and privacy profiles.

## Done Criteria

- Daily coordination questions can be answered within the minimal profile.
- Source domains retain ownership and no endpoint bypasses policy/audit.
- Focused Dev tests and authenticated HTTP smoke tests pass.
