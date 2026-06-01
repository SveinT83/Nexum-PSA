The Ticket domain is the operational work engine in Nexum PSA. A ticket represents work that needs attention from technicians, whether it was created manually, generated from inbound email, or created by future automation.

Tickets are used for support requests, lead-style requests from unknown senders, service work, internal follow-up, and other work that needs ownership, lifecycle, communication, SLA tracking, time registration, cost registration, and audit history.

The Ticket domain is module-owned and lives under `app/Modules/Ticket`. Routes are defined in `app/Modules/Ticket/routes.php`, controllers live in `app/Modules/Ticket/Controllers`, and views live in `app/Modules/Ticket/Views`.

## Main Workflows

Technicians work from the Ticket list, open a ticket, read activity, reply to customers, add internal notes, register time, reserve stock items, change workflow state, update assignment, and close the ticket when resolution requirements are met.

Administrators configure the ticket system from Ticket Settings. The current settings area controls queues, types, statuses, priorities, ticket rules, workflows, assignment rules, ticket assignment settings, outbound email account selection, and merge behavior.

Inbound email can create new tickets or link messages to existing tickets. Ticket Rules and Assignment Rules then classify, route, and assign the work.

## Core Concepts

Ticket Type:

High-level classification for the ticket, such as Support or Lead. Ticket Types are configurable in Ticket Settings and can be used by Ticket Rules.

Queue:

Operational work bucket. Queues can be selected manually and can be set by Ticket Rules or inbound email routing.

Status:

Lifecycle label for where the ticket is in the process. Statuses also carry state information such as open, resolved, or closed.

Priority:

Urgency signal used by technicians, sorting, SLA risk visibility, and Ticket Rules.

Category:

Shared Taxonomy category used to classify the ticket. Categories are also used by assignment scoring and ticket assignment matching.

Tags:

Shared tags stored through the `taggables` table. Tags can be added manually and inherited from Email when inbound messages create or update tickets.

Workflow:

The configured lifecycle model that controls valid transitions, requirements, manual buttons, and automatic action triggers.

SLA:

Service policy applied to the ticket. SLA can come from a Ticket Rule, active contract, or global default SLA.

Assignment:

Ownership can be set explicitly by assignment rules or scored from ticket assignment settings plus User Management profile availability.

Activity:

The human-readable ticket timeline. It includes customer replies, internal notes, time entries, cost entries, and relevant system events.

## What A Ticket Stores

The main `tickets` table stores:

- Ticket key.
- Type and ticket type.
- Queue, status, priority, category, workflow, and SLA.
- Client, site, contact, and asset links.
- Owner and creator/updater user references.
- Channel.
- Subject and description.
- Impact and urgency.
- Unread state.
- First response and resolve due timestamps.
- First response, resolved, and closed timestamps.
- Merge metadata.
- Metadata JSON.

Related tables store messages, attachments, events, time entries, cost entries, assignment rules, ticket assignment settings, rules, workflow configuration, merge suggestion dismissals, and time allocation records.

## User-Facing Surfaces

Ticket list:

The list supports search, filters, sorting, unread and unassigned views, lifecycle filtering, SLA risk badges, owner display, bulk selection, merge controls, and merge suggestions.

Ticket show:

The show page is the primary work surface. It contains ticket details, conversation/activity, workflow actions, replies, notes, time registration, cost registration, assignment information, SLA details, and Knowledge suggestions.

Ticket create/edit:

Create and edit forms handle manual ticket creation, client/contact/site/asset scope, lifecycle fields, tags, and core ticket metadata.

Ticket settings:

Admin surface for queues, types, statuses, priorities, rules, workflows, assignment rules, ticket assignment settings, email defaults, and merge settings.

## Important Design Rules

Tickets are for actionable work. Not every inbound email should become a ticket. Email Rules, not-ticket behavior, future Operational Signals, and future custom fields should keep noise out of the ticket queue.

Ticket state changes should go through shared actions and workflow runtime checks. Avoid bypassing `ChangeTicketStatus`, `TicketActionGuard`, `TicketWorkflowRuntime`, or the existing action classes.

Ticket UI must not expose unfinished controls. If a setting or button is visible, the underlying behavior must be implemented, tested, and honest about what it does.
