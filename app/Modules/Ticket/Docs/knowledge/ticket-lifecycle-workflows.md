Ticket lifecycle is controlled by a combination of statuses, workflows, workflow transitions, action guards, and ticket actions.

Statuses describe where the ticket is. Workflows define which status changes are allowed and what must be completed before the transition can happen.

## Default Statuses

The default ticket setup creates these statuses:

- New
- In Progress
- Waiting Customer
- Resolved
- Closed

Closed statuses are considered terminal for most technician actions. The action guard blocks unsafe edits on closed tickets and prevents customer replies where they do not make sense.

## Workflow Runtime

Workflows are configured under Ticket Settings. A workflow has states and transitions.

A transition may define:

- Source status.
- Target status.
- Manual availability.
- Required internal note.
- Required public technician response.
- Required selected solution response.
- Required documentation follow-up.
- Ticket actions that can trigger the transition automatically.

The workflow runtime validates transitions before status changes are committed. If a transition is blocked, the UI should show the reason instead of allowing technicians to click through invalid work.

## Documentation Follow-Up Requirement

Transitions can require a documentation follow-up before the ticket can move forward.

Technicians create the follow-up from the Knowledge panel on the ticket show page. This writes a
`documentation_requested` event to `ticket_events` with the ticket key, category, client, actor, and
reason.

This is intentionally lightweight. It makes missing documentation visible and gives workflow rules a
real marker to enforce now, while future Knowledge work can turn these requests into article drafts,
queues, or approval workflows.

## Manual Transitions

Manual transitions are shown as workflow buttons on the ticket show page when the workflow allows them.

Examples:

- Start progress.
- Wait for customer.
- Mark as solved.
- Close.

The default workflow avoids quick-closing directly from open states. Tickets should normally move through Resolved before Closed.

## Automatic Action Triggers

Workflow transitions can be linked to ticket actions. When the action completes, the workflow can auto-advance if all transition requirements are satisfied.

Examples:

- A customer reply can resume a ticket from Waiting Customer.
- Marking a technician response as the solution can satisfy a solved transition.
- Sending a solution reply can move the ticket toward Resolved when configured.

Closing remains a deliberate technician action.

## Customer Reply Intents

Customer replies from technicians can carry intent:

- Normal update.
- Request customer input.
- Send solution.

The intent helps workflow logic distinguish ordinary communication from actions that should move lifecycle state.

## Solution Responses

Public technician replies can be marked as the solution. Workflows can require a selected solution response before allowing a solved/resolved transition.

This prevents tickets from being resolved without a clear answer visible in the conversation.

## Action Guard

`TicketActionGuard` centralizes basic safety checks.

It protects against:

- Inactive users performing mutable actions.
- Customer replies on closed tickets.
- Customer replies where the selected contact cannot receive email.
- Other basic restrictions that should be consistent across UI and backend actions.

The guard is not a replacement for workflow rules. It protects universal action safety, while workflows define configurable business process.

## Lifecycle Timestamps

Tickets track important lifecycle timestamps:

- `first_responded_at`
- `resolved_at`
- `closed_at`

`ChangeTicketStatus` owns resolved and closed timestamp behavior. The first public technician reply stamps `first_responded_at`.

These fields support SLA reporting, ticket history, and future Intelligence features.

## Implementation References

Important files:

- `app/Modules/Ticket/Services/TicketWorkflowRuntime.php`
- `app/Modules/Ticket/Services/TicketActionGuard.php`
- `app/Modules/Ticket/Actions/ChangeTicketStatus.php`
- `app/Modules/Ticket/Actions/AutoAdvanceTicketWorkflow.php`
- `app/Modules/Ticket/Actions/ApplyTicketWorkflowActionTrigger.php`
- `app/Modules/Ticket/Actions/MarkTicketMessageSolution.php`
- `app/Modules/Ticket/Support/TicketAction.php`
- `app/Modules/Ticket/Livewire/Admin/WorkflowEditor.php`
