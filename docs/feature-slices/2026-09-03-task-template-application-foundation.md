# Feature Slice: Task Template Application Foundation

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-task-templates-and-scheduled-generation.md`
Owner: Codex

## Goal

Provide the Task-owned data and action boundary that atomically expands the current template into a
Task graph and records idempotent generation evidence.

## User-Visible Behavior

No UI is exposed. Later UI and automation receive one verified application service.

## Scope

Generation-run data, Task provenance, template date offsets/tags, graph validation, parent/checklist/
dependency copying, direct current-template semantics, idempotency, rollback, models, tests, and
developer documentation.

## Out Of Scope

CRUD UI, schedules, manual Ticket/Client controls, rule actions, API/MCP, versions, and approvals.

## Data Touched

Task template tables, Tasks, Task dependencies/checklists/tags, and new Task generation runs.

## Permissions

No new route permission. Callers remain responsible for authorization; the action requires an actor
and uses Task-owned persistence.

## Tests

Single/group generation, nesting, checklist/dependency/tag copying, idempotency, graph rejection,
transaction rollback, and mutable-template future-only behavior.

## Documentation

RFC, ADR, TODO, Task domain plan, Knowledge wording, and human-review registration.

## Done Criteria

Migration runs on Dev with schema read-back; focused tests, Task regression, lint, Pint, and diff
checks pass; no UI or scheduler is exposed.

## Dev Evidence

Migration `2026_09_03_150000_create_task_template_generation_foundation` ran in Dev batch 25 with
schema read-back. Atomic graph, mutable future-only, idempotency, and rollback coverage passes in
`TaskTemplateApplicationTest`; the complete Task module passes 41 tests / 244 assertions.
