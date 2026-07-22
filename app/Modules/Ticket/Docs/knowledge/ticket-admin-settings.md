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

Workflow settings use the same visual idea as Signal Rules: states are cards, requirements are grouped conditions, and each state has its own available actions.

An admin can combine `All` and `Any` groups, create several operational states that share one reporting status, choose hidden/blocked/available/conditional actions, restrict eligible technicians, define senior-review gates, set commercial tolerance, and add optional or required escalation paths to another published workflow.

`Save draft` never changes rules on active Tickets. `Publish` creates a numbered immutable version. New Tickets use the published version. Existing active Tickets can be moved only through migration preview and an explicit selection; closed Tickets are not migrated.

See `Ticket Workflow Rules, Approval And Escalation` for the complete administrator and technician guide.

## Solution Policy

Ticket Settings controls whether internal notes may be marked as ticket solutions.

When `Allow internal notes to be marked as ticket solutions` is enabled, technicians can use
`Internal solution` in the ticket composer. This records the fix without sending customer email and
can satisfy workflow solution requirements. This is useful for RMM and asset-driven tickets where
there may be no customer contact or where a customer-visible RMM reply would be noise.

When disabled, only public technician replies marked as solution satisfy workflow solution
requirements. Internal notes remain available, but they do not count as selected solutions.

## Customer Portal Policy

Ticket Settings controls the default customer visibility for manually created client tickets.

The default can be:

- Unpublished.
- Published.

Unpublished is the safe default. A manually created client ticket stays silent externally until a
technician publishes it from the ticket show page. While it is Unpublished, the ticket is still
available internally and included in reporting, but it is not visible in the Customer Portal, does
not send customer-facing portal notifications, does not allow `Reply to contact`, and cannot be
escalated to a Nexum relationship.

Published makes a new manually created client ticket visible in the Customer Portal immediately and
enables customer replies. After a ticket is Published, the normal ticket page does not allow it to
be unpublished.

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
