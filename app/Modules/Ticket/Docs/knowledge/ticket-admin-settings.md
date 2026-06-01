Ticket Settings is the admin control surface for the Ticket domain.

The settings page is owned by the Ticket module and is available through the admin ticket settings routes.

## Email Settings

Ticket Settings can select the default outbound Email account for ticket replies.

This updates the Email module account default for the `tickets` scope. Ticket replies and internal notification behavior then use the same shared Email account source of truth.

## Ticket Queues

Queues are operational work buckets.

Admins can create, edit, and delete queues. Queues used by tickets or rules are protected from unsafe deletion.

Queues can be selected manually, inferred from inbound email recipients, or set by Ticket Rules.

## Ticket Types

Ticket Types are configurable high-level classifications.

Admins can create and update types. Protected system types and types referenced by tickets or rules should not be deleted.

Ticket Types are important future extension points for custom fields and ticket templates.

## Statuses

Statuses define ticket lifecycle labels.

Statuses include:

- Name and slug.
- State.
- Active flag.
- Default flag.
- Closed flag.
- Sort order.

Statuses used by tickets are protected from deletion.

## Priorities

Priorities define urgency and sorting weight.

Priority level is used by the Ticket list and SLA risk context. Priorities referenced by tickets or rules are protected from deletion.

## Ticket Rules

Ticket Rules classify and route tickets on creation.

Rules can set type, queue, priority, category, tags, and SLA. Rules should be ordered from specific to broad.

## Assignment Rules

Assignment Rules explicitly assign tickets to technicians based on configured criteria.

Use Assignment Rules when ownership must be deterministic.

## Ticket Assignment Settings

Ticket Assignment Settings control assignment engine scoring.

Settings support assignable state, capacity, category matching, and tag matching.

Technicians can maintain their own assignment settings where allowed. Admins can manage all ticket assignment settings.

## Workflows

Workflow settings define state transitions, requirements, manual buttons, and automatic action triggers.

The Livewire workflow editor is the primary admin UI for workflow configuration.

## Merge Settings

Merge settings include:

- Exact duplicate inbound email auto-merge.
- AI-assisted merge suggestions.
- Similarity threshold.

Exact duplicate auto-merge can link duplicate inbound emails automatically. Similarity suggestions only create UI suggestions and require technician confirmation.

## Deletion Protection

Settings records that are used by tickets or rules should be protected from deletion.

This keeps historical tickets readable and prevents rules from referencing missing configuration.

## Implementation References

Important files:

- `app/Modules/Ticket/Controllers/Admin/TicketSettingsController.php`
- `app/Modules/Ticket/Controllers/Admin/AssignmentRuleAdminController.php`
- `app/Modules/Ticket/Controllers/Admin/TicketAssignmentSettingsAdminController.php`
- `app/Modules/Ticket/Livewire/Admin/WorkflowEditor.php`
- `app/Modules/Ticket/Views/Admin/Settings/index.blade.php`
- `app/Modules/Ticket/Views/Admin/AssignmentRules/index.blade.php`
- `app/Modules/Ticket/Views/Admin/TicketAssignmentSettings/index.blade.php`
