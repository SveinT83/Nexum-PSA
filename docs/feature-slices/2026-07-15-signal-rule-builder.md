# Feature Slice: Signal Rule Builder

Status: Done
Date: 2026-07-15
Parent: `docs/rfc/2026-07-15-signal-rule-builder-and-recovery.md`
Owner: Codex

## Goal

Make Signal rules understandable and maintainable without hand-editing JSON.

## User-Visible Behavior

Admins add, remove, expand, and reorder compact condition/action rows; select all/any matching;
create independent groups; keep Rule Reference in the right sidebar; and opt into advanced JSON.

## Scope

Condition schema compatibility, matching, rule form, stop-processing setting, and tests.

## Out Of Scope

New action types and Signal producers.

## Data Touched

`signal_rules.conditions`, `signal_rules.actions`, and `signal_rules.stop_processing`.

## Permissions

Existing `signal.rule.manage` permission.

## Tests

Legacy definitions, grouped all/any definitions, builder validation, action order, and rule stopping.

## Documentation

Signal README, Knowledge overview, ADR, and human review checklist.

## Done Criteria

- [x] Builder supports dynamic conditions, groups, and actions.
- [x] Legacy rules remain executable and editable.
- [x] Stop-processing behavior is covered by tests.
- [x] Focused tests pass on Dev.
