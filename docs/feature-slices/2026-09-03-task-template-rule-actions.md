# Feature Slice: Task Template Rule Actions

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-task-templates-and-scheduled-generation.md`
Owner: Codex

## Goal

Let Signal Rules, Ticket Rules, and RMM Alert Rules apply current Task templates through the shared
Task boundary.

## User-Visible Behavior

Rule builders can choose a Task template where supported; execution history shows success, failure,
or duplicate-safe reuse without exposing technical internals.

## Scope

Backward-compatible typed actions, template selectors, owner/context projection, protected actors,
idempotency, audit/result presentation, tests, and Knowledge updates.

## Out Of Scope

One generic rule engine, template versions/approval, new RMM remediation, and external API/MCP.

## Data Touched

Existing rule definitions/audits plus Task generation runs and generated Tasks.

## Permissions

Existing rule-management/runtime permissions remain authoritative; protected actors receive only
the minimum Task ability required by the delivered action.

## Tests

Legacy compatibility, rule preview, authorization, context isolation, duplicate actions, failure
semantics, loop/retry boundaries, and Signal/Ticket/RMM regressions.

## Documentation

Signal Rules, Ticket Rules, RMM Alert Rules, and Task Knowledge.

## Done Criteria

All three rule domains call Task rather than constructing template graphs, retain their audit and
authority boundaries, and pass focused/cross-module verification.

## Dev Evidence

Signal Rules and RMM Alert Rules retain legacy single-Task behavior when no template is selected and
can now select an active Task template. Ticket Rules expose the typed `apply_task_template` action,
selector, target validation, protected `task.create` authority, no-write preview, and duplicate-safe
execution. Signal passes 36 tests, RMM passes 21 tests / 184 assertions, and the focused Ticket Rule
registry/executor/builder matrices pass.
