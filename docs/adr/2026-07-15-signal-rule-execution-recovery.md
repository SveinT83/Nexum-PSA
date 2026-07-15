# ADR: Signal Rule Execution Recovery

Status: Accepted
Date: 2026-07-15
Decision Makers: Nexum PSA product owner and Codex

## Context

Signal actions can create records and dispatch queued work across several domains. Retrying an
entire rule after a partial failure can duplicate tickets, tasks, invitations, opportunities, or
webhook deliveries. At the same time, mutating an old execution row would erase the audit trail.

## Decision

Each rule execution is an immutable attempt. Retry creates a new execution linked to the first
attempt. Actions keep their original zero-based position in the snapshotted action list, and a
stable key derived from Signal, rule, and action position is passed to side-effecting actions.
Normal retry runs only failed or `not_run` positions that have never reached `done`, `queued`, or
`skipped` in the attempt chain. A full rerun remains an explicit advanced operation and uses the
same keys.

When an action throws, later actions in that rule are recorded as `not_run`. Other matching rules
continue. A rule's stop-processing flag applies only after that rule completes successfully.

## Rationale

Linked immutable attempts preserve operator accountability and allow the exact history to be
inspected. Stable per-action keys make retries safe at existing domain boundaries without a new
generic workflow engine or a destructive execution rewrite.

## Consequences

- Execution history contains multiple linked rows for retries.
- Side-effecting actions must query and store the stable key where practical.
- Moving an action changes its identity for future Signals, but retries use the snapshotted action
  order and therefore remain stable.
- `skipped` is treated as terminal because it represents a deliberate no-op or already-satisfied
  action, not a transient failure.

## Alternatives Considered

- Mutate the original execution: rejected because it destroys audit history.
- Rerun every action: rejected because it can duplicate external effects.
- Build a new generic workflow engine: rejected as unnecessary scope for the existing Signal
  domain.

## Follow-Up

Keep action idempotency checks covered by regression tests when adding future action types.
