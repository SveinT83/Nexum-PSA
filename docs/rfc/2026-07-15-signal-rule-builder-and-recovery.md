# RFC: Signal Rule Builder And Recovery

Status: Approved
Date: 2026-07-15
Owner: Nexum PSA product owner and Codex

## Context

The Signal domain already records cross-module events and executes ordered automation rules, but
the feed becomes difficult to use as the table grows and the rule editor exposes every condition
and action field at once. Action failures are recorded, but later actions currently continue and
there is no safe operator retry flow.

## Goals

- Default the Signal feed to the last 30 days while allowing search, filters, custom dates, all
  history, and explicit sorting.
- Replace the fixed rule form with a compact condition and action builder modelled on Intake.
- Support `all` and `any` matching within independent condition groups.
- Keep action order explicit and allow actions to be added, removed, and reordered.
- Allow a successful rule to stop evaluation of lower-priority rules.
- Stop the remaining actions in one rule when an action fails, without blocking other rules.
- Record per-action status and allow safe retry of failed or unstarted actions.
- Keep existing rules and JSON definitions working during the transition.

## Non-Goals

- Signal does not replace Email or Ticket rules. Those domains emit Signals only through an
  explicit `emit_signal` action.
- This change does not add new Signal producers or action types.
- This change does not redesign the queue worker or webhook delivery retry policy.

## Current Behavior

The feed always shows all Signals newest-first. Rule conditions are a legacy JSON-shaped map with
implicit AND matching. The editor renders fixed condition fields and at least three action blocks,
each containing fields for every action type. All matching rules run, and a failed action does not
stop later actions in the same rule.

## Proposed Change

The feed uses a 30-day default query with whitelisted filters and sort fields. The rule editor
stores new condition definitions as a versioned group tree while continuing to evaluate the legacy
map format. Each group selects all or any of its rows, and multiple groups select all or any at the
root. Advanced JSON remains available in a collapsed opt-in panel.

Rules gain a `stop_processing` flag. Rule executions snapshot ordered actions and per-action
results. A failed action marks later actions as `not_run`. A retry creates a new linked audit
attempt and executes only actions that have not previously reached a terminal successful state.
An explicitly warned full rerun is also available, with stable per-action idempotency keys used by
side-effecting actions.

## Impact Analysis

- Module: Signal controllers, actions, models, support classes, views, routes, tests, README, and
  Knowledge documentation.
- Database: `signal_rules` and `signal_rule_executions` receive additive columns.
- Permissions: existing `signal.action.execute` protects retry routes; no new permission is added.
- Related modules: Ticket, Task, Sales, Customer Portal, Taxonomy, and webhook delivery are touched
  only through existing Signal action boundaries and stable idempotency metadata.
- Queue: webhook jobs continue to use the current queue.
- UI: Signal feed, rule create/edit, and Signal detail execution history change.

## Data And Migration Plan

Add nullable/self-referential retry audit metadata to executions and a boolean stop flag to rules.
No backfill is required. Legacy condition maps remain valid and are converted to builder rows only
for presentation. Rollback drops only the additive columns.

## Testing Plan

- Feature tests for the 30-day default, date override, search/filter, and sorting.
- Regression tests for legacy conditions and new grouped all/any matching.
- Tests for action failure stop behavior, lower-priority rule continuation, and stop-processing.
- Tests for retry selection, linked audit attempts, and idempotent side effects.
- UI response assertions for the dynamic builder and action log.
- Focused Signal suite and affected integration tests on the Dev server, followed by authenticated
  or redirect-safe HTTP smoke checks.

## Documentation Plan

Update the Signal README, Knowledge overview, TODO where relevant, and `docs/human-review.md` with
the exact views that require a new human review.

## Open Questions

None. Product behavior was reviewed point-by-point before implementation.

## Approval

Approved by Svein Tore on 2026-07-15 in the Codex review conversation, including the instruction
to apply the recommended behavior and complete the implementation on the Dev server.
