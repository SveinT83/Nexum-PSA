# Feature Slice: Signal Action Recovery

Status: Done
Date: 2026-07-15
Parent: `docs/rfc/2026-07-15-signal-rule-builder-and-recovery.md`
Owner: Codex

## Goal

Let an operator understand and safely recover a partially failed Signal rule.

## User-Visible Behavior

Signal detail shows each action's state. Operators can retry only failed/unstarted actions or use a
warned full rerun. Every retry remains visible as a linked attempt.

## Scope

Execution audit schema, failure semantics, retry route/controller behavior, idempotency metadata,
detail UI, and tests.

## Out Of Scope

Queue worker redesign and automatic retry schedules.

## Data Touched

`signal_rule_executions` and metadata on existing action target records.

## Permissions

Existing `signal.action.execute` permission.

## Tests

Failure stop, other-rule continuation, retry selection, full rerun idempotency, audit linkage, and
route authorization.

## Documentation

Signal README, Knowledge overview, ADR, and human review checklist.

## Done Criteria

- [x] Per-action statuses are persisted and readable.
- [x] Retry never reruns already successful actions.
- [x] Side-effecting actions are idempotent for the same action position.
- [x] Focused tests pass on Dev.
