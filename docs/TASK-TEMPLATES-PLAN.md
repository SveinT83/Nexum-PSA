# Task Templates Plan

This plan captures the internal direction for Task Templates and Task Template Sets. It is a
platform-building feature that can be implemented without first publishing a GitHub idea.

## Purpose

Task Templates let admins define reusable task recipes. A template is not a real task. It becomes a
real task only when another workflow copies it into an operational context, such as a future Ticket
Template.

Task Template Sets let admins group several Task Templates into a reusable work plan with order and
simple dependencies.

## Admin Placement

Task Templates should live in Admin because they are configuration, not daily task execution.

Preferred direction:

- Add or prepare an Admin Task Settings area.
- Place Task Templates and Task Template Sets under Task Settings.
- Ticket Settings can later consume these templates through Ticket Templates.

Code ownership should remain in the Task domain:

- `app/Modules/Task/Models`
- `app/Modules/Task/Controllers/Admin`
- `app/Modules/Task/Views/Admin/TaskTemplates`
- `app/Modules/Task/Views/Admin/TaskTemplateSets`
- `app/Modules/Task/routes.php`

## Concepts

### Task Template

A single reusable task recipe.

Examples:

- Diagnose device.
- Order parts.
- Run final test.
- Contact customer.
- Return equipment.

Suggested v1 fields:

- Name.
- Title.
- Description.
- Checklist items.
- Estimated minutes.
- Sort order.
- Active/inactive.
- Required/optional.
- Assignee mode.
- Fixed user when assignee mode requires it.
- Due offset.

Suggested assignee modes:

- Unassigned.
- Ticket owner.
- Fixed user.

Suggested due offset:

- None.
- Minutes/hours/days after task creation or later parent record creation.

### Task Template Set

A reusable group of Task Templates.

Examples:

- Laptop Repair Tasks.
- New User Setup Tasks.
- Server Maintenance Tasks.
- Backup Failure Follow-up.
- Security Review Checklist.

Suggested v1 fields:

- Name.
- Description.
- Active/inactive.
- Items.
- Sort order.
- Dependency on an earlier item.
- Optional overrides per item.

### Task Template Set Item

A row that places a Task Template inside a Task Template Set.

Suggested v1 fields:

- Task Template Set ID.
- Task Template ID.
- Sort order.
- Optional dependency on another set item.
- Required/optional override.
- Assignee mode override.
- Fixed user override.
- Due offset override.

The same Task Template should be reusable in multiple Task Template Sets. Set items can override
some values because the same task may have different timing or responsibility in different work
plans.

## Defaults And Overrides

Do not build a heavy settings system in v1.

Use this inheritance direction:

1. System defaults.
2. Task Template defaults.
3. Task Template Set item overrides.
4. Future context overrides from Ticket Template, Workflow, or other consumers.

Nullable template fields can mean "use the default".

## Dependency Direction

Start with simple dependency behavior:

- A set item may depend on one earlier item.
- The generated real task can be considered blocked until the dependency task is completed.

Future versions may support:

- Multiple dependencies.
- Parallel groups.
- Finish-to-start or start-to-start dependency types.
- Conditional task logic.

## Copy Behavior

When a Task Template or Task Template Set is used, it should be copied into real Tasks.

Important rules:

- A Task Template is an instruction, not a live task.
- Updating a Task Template must not automatically change tasks already created from it.
- Generated tasks may store metadata such as source template ID, source set ID, and future template
  version for traceability.

## Out Of Scope For V1

- Ticket Template integration.
- Automatic task creation from tickets.
- Workflow enforcement.
- Advanced dependency types.
- Team/role assignment if team ownership is not ready.
- Recurring tasks.
- Reminder rules.
- SLA-style task timers.
- Task custom fields.
- Customer portal visibility.

## Future Consumers

Likely future consumers:

- Ticket Templates.
- Asset Service / Intake workflows.
- Project templates.
- Onboarding/offboarding workflows.
- Preventive maintenance workflows.

## Implementation Notes

Keep the first implementation intentionally small:

- Admin CRUD for Task Templates.
- Admin CRUD for Task Template Sets.
- Ability to add templates to sets.
- Sort order.
- One dependency on an earlier set item.
- Basic tests.
- Knowledge documentation when implemented.
