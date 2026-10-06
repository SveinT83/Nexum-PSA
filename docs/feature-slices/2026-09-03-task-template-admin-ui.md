# Feature Slice: Simple Task Template Admin UI

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-task-templates-and-scheduled-generation.md`
Owner: Codex

## Goal

Let authorized users list, create, directly edit, activate, deactivate, and safely delete unused
Task templates through a compact Bootstrap UI.

## User-Visible Behavior

One Task Templates area supports one or many Task items, nesting, checklists, dependencies, tags,
defaults, and date offsets without publishing, approval, or version controls. Add-item inputs keep
visible labels, while saved Template Task editors are collapsed by default to keep groups scannable.
The item just created is expanded for immediate follow-up editing.

## Scope

Routes, controllers/actions, validation, template list/editor, usage guards, navigation, permissions,
tests, Knowledge, and responsive review.

## Out Of Scope

Schedules, owner application, rule actions, template versions, and approvals.

## Data Touched

Task template groups/items/checklists/dependencies/tags.

## Permissions

Reuse `task.manage_templates`; ordinary Task permissions remain unchanged.

## Tests

CRUD, graph validation, permissions, active/reference guards, direct-save behavior, and responsive UI.

## Documentation

Task template Knowledge and human review.

## Done Criteria

Authorized UI works on desktop/narrow layouts, forbidden users fail closed, tests pass, and no
unfinished controls are visible.

## Dev Evidence

The Bootstrap admin surface supports direct template and item CRUD, activation, guarded deletion,
graph inputs, persistent add-field labels, default-collapsed saved-item editors, one-time expansion
of the newly created item, navigation, history, and permissions. Focused UI/domain coverage is
included in the passing Task module matrix. Human responsive review remains
`HR-2026-09-03-001`.
