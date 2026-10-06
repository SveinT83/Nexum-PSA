# Feature Slice: Searchable Owners And Recurring Ticket Tasks

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-task-templates-and-scheduled-generation.md`
Owner: Codex

## Goal

Remove internal owner-ID entry from Task Template administration and let recurring Tickets select a
Task Template for every generated Ticket occurrence.

## User-Visible Behavior

Task Template application and schedules expose User or Client plus a searchable, filtering lookup.
Ticket is not offered as a future Task schedule owner. A recurring Ticket can select one active Task
Template under its Schedule settings, and each generated Ticket receives a fresh Task group.

## Scope

Task Template owner lookup UI and validation, Ticket schedule Task Template reference, recurring
occurrence application, idempotency, migration, tests, Knowledge, and human review.

## Out Of Scope

Arbitrary future Ticket IDs, cloning Tasks from the recurring parent, multiple templates per Ticket
schedule, Task Template versions, and changes to Ticket Rule matching.

## Data Touched

`ticket_schedules.task_template_group_id`, Task Template generation runs, generated Tasks, and the
existing schedule forms.

## Permissions

Existing `task.manage_templates`, Ticket create/update, and Task application boundaries remain
authoritative. No new permission is introduced.

## Tests

Searchable lookup markup, User/Client validation, rejection of Ticket schedule owners, recurring
Ticket selection persistence, occurrence Task generation, current-template semantics, and duplicate
prevention.

## Documentation

Task and Ticket Knowledge, parent RFC, ADR, TODO, and `HR-2026-09-03-001`.

## Done Criteria

No owner ID is typed in ordinary UI, future Ticket is absent from Task schedules, each recurring
Ticket occurrence gets exactly one selected Task group, focused and cross-module tests pass, and the
new migration is read back on Dev.

## Dev Verification

- Migration `2026_09_03_160000_add_task_template_group_to_ticket_schedules` ran in Dev batch 26.
- Blade templates compile successfully and Laravel Pint passes for the changed PHP files.
- The owner-picker script is rendered through the layout's `scripts` section, so User/Client
  suggestions are connected to every lookup field and filtered while typing.
- The focused owner, recurring Ticket, scheduled Ticket, Task application, and occurrence-parity
  package passes 23 tests / 146 assertions.
- The complete affected Task, Signal, RMM, and focused Ticket Rule matrix passes 154 tests / 1,534
  assertions.
- The Task Template delivery is committed separately from unrelated Dev work. Push, Main
  promotion, and production deployment remain separate actions.
