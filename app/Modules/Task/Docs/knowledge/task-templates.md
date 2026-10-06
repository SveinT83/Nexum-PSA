Task Templates create repeatable operational work without adding a publication or approval process.
An authorized technician edits one current template definition directly. Saving it affects only
future applications; Tasks that already exist are never migrated or rewritten.

## Manage Templates

Open **Admin > Tickets > Task Templates**, or use the Task Templates button in Task Settings.
`task.manage_templates` is required to create, edit, activate, deactivate, or remove templates.

A template contains one or more Task items. Each item may define:

- title and description, including `{client}`, `{ticket.key}`, `{ticket.subject}`, and `{date}`;
- parent Task nesting and required dependencies;
- checklist items and Taxonomy tags;
- default assignee, estimate, Queue, Priority, and Category;
- due, scheduled-start, and scheduled-end offsets; and
- whether the Task blocks completion of its owner.

There are no Draft, Published, approval, version, rollback, or template-migration controls. Deactivate
a template to stop future use. An unused template may be deleted; a template with schedules or
generation history is retained and must be deactivated instead.

## Apply From A Ticket Or Client

Users need `task.create` plus visibility of the Ticket or Client. Open its Tasks area, choose
**Apply Task template**, review the projected group and date anchor, then confirm once. Preview does
not write Tasks. The confirmation key is idempotent, so a repeated submit returns the original run
instead of creating another group. Applying from a Ticket returns to that Ticket so the generated
Tasks are visible in its Tasks area.

Generation copies the current template into real Tasks in one transaction. Parent relationships,
checklists, tags, dependencies, dates, assignees, and Work Context are copied together. If the graph
is invalid or one item fails, no partial Task group remains.

## Recurring Schedules

Template managers can add, edit, activate, deactivate, remove, or run a schedule from the template
page. Supported intervals are daily, weekly, monthly, and quarterly. A schedule selects its owner,
timezone, next run, optional assignee override, and optional due offset.

The owner is selected from a searchable list of active Users or Clients. Suggestions appear when the
field is focused and are filtered while typing; internal database IDs are not entered manually.
Ticket is intentionally not a schedule owner because a future Ticket does not exist yet. Use Ticket
Rules for event-driven Tickets, or select a Task template on a recurring Ticket so each generated
Ticket occurrence receives its own Task group.

Only one overdue occurrence is generated per scheduler pass. The next run advances before work is
executed under a database lock, and the occurrence key prevents duplicates. Generate now creates a
separate manual occurrence. The template page shows the latest result and recent generation runs.

Automatic schedules require the external command `php artisan schedule:run` every minute. The
Laravel schedule entry is `task.templates.generate_due` every minute. If scheduled Tasks stop,
verify both the external runner and the generation history; `schedule:list` alone is not proof that
the external runner is active.

## Rule Actions

Signal Rules, Ticket Rules, and RMM Alert Rules can select an active Task template. Each rule domain
keeps its existing permission, protected-actor, ordering, failure, and audit behavior. All three call
the shared Task application boundary and never construct Task graphs themselves.

- Signal Rules use the Signal's Client when present, otherwise the configured actor.
- Ticket Rules use the Ticket as owner and support no-write preview.
- RMM Alert Rules use the current Asset Client and retain the Task group IDs in work-item evidence.

Existing single-Task Signal and RMM actions remain compatible when no template is selected.

## Troubleshooting

Use recent generation history to distinguish a completed, failed, or duplicate-safe run. Inactive
templates cannot be previewed or executed. Missing owners, invalid dependency cycles, and inactive
rule targets fail closed with bounded operator-facing evidence. Do not edit generated Tasks to make
template history match; update the template for the next run instead.
