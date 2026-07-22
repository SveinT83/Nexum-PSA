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

The versioned lifecycle model that controls operational states, grouped requirements, available actions, assignment, review gates, escalation, Sales approval, Storage fulfilment, and close outcomes.

SLA:

Service policy applied to the ticket. SLA can come from a Ticket Rule, active contract, or global default SLA.

Work Context:

Tickets store `work_context_id` in addition to the existing `client_id`. When a new Ticket is
created with a Client, the Ticket uses that Client's Work Context and keeps `client_id`, site,
contact, and asset behavior unchanged. When a new Ticket is created without a Client, the Ticket is
internal work for the owning organization.

Historical Tickets that already had a real `client_id` are backfilled to the matching Client Work
Context. Historical Tickets with `client_id = null` remain unscoped until they are edited or handled
by a later cleanup slice.

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
- Work Context.
- Owner and creator/updater user references.
- Channel.
- Subject and description.
- Impact and urgency.
- Unread state.
- First response and resolve due timestamps.
- First response, resolved, and closed timestamps.
- Merge metadata.
- Metadata JSON.

Related tables store messages, attachments, events, time entries, actual and planned cost lines, Sales context, workflow versions/history/reviews/evidence, assignment rules, ticket assignment settings, rules, merge suggestion dismissals, and time allocation records.

## User-Facing Surfaces

Ticket list:

The list supports search, filters, sorting, unread and unassigned views, lifecycle filtering, SLA risk badges, owner display, bulk selection, merge controls, and merge suggestions.

Ticket show:

The show page is the primary work surface. It contains ticket details, conversation/activity, a workflow cockpit, missing requirements, next steps, escalation, reviews and evidence, planned commercial scope, linked Sales quote, Storage/purchase conversion, replies, notes, time and actual cost registration, assignment, SLA details, customer contact cards, and Knowledge suggestions. Buttons are shown, disabled, or hidden from the current workflow decision.

When an active Nexum relationship is available, the show page also displays a
Nexum relationship panel. The panel shows existing remote sync links and allows
authorized technicians to escalate a Published ticket to a real configured
relationship. Unpublished client tickets cannot be escalated to a Nexum
relationship because they are still externally silent. The panel is hidden when
there is no existing link and no active relationship target, so the UI does not
imply unfinished sync behavior.

Ticket create/edit:

Create and edit forms handle manual ticket creation, client/contact/site/asset scope, lifecycle fields, tags, and core ticket metadata.

Ticket API:

External systems can sync ticket conversation entries through `POST /api/v1/tickets/{ticket}/external-messages`. The endpoint is idempotent by external source and external message ID, stores the message as `author_type = external`, and avoids sending outbound customer email for imported replies. Free-form external metadata is kept for audit context, but workflow-driving fields such as reply intent and solution markers are ignored.

Workflow API routes expose the same decisions and operations as the Ticket page, including transitions, escalation, close outcome, planned scope, quotes, acceptance evidence, review, Storage conversion, purchase need, workflow definition publishing, and explicit version migration. API calls pass through the same permission and workflow guards as browser actions.

Timer start, time registration, and actual-cost registration also have API routes. They use the same
Ticket actions as the browser and therefore evaluate the same specific or **Any technician
activity** automatic transition after the business record has been saved.

Nexum-to-Nexum ticket exchange is owned by the Relationship module. Relationship
sync uses signed endpoints under `/api/v1/nexum/relationships/*`, stores local
and remote ticket identities in `nexum_sync_links`, and records audit events in
`nexum_sync_events`.

Ticket API create, list, and show responses expose `work_context_id` and `work_context`. Existing
`client_id` filters remain client-only. The list endpoint also supports `work_context_id` and
`context_type` filters for `internal` and `client`.

Customer Portal:

Tickets are hidden from the Customer Portal until they are explicitly Published, or until they are
created from the portal. Portal users can list, create, view, and reply to visible tickets for their
active Client/Site membership. The portal shows customer-safe status labels and public ticket
messages only. Internal notes, internal attachments, assignment details, time entries, cost entries,
SLA internals, workflow internals, and technician audit details stay inside the technician workspace.

Ticket Settings controls whether manually created client tickets default to Unpublished or
Published. The create form can override the default for the new ticket. Unpublished client tickets
stay silent externally: they are not visible in the Customer Portal, do not send customer-facing
portal notifications, do not allow `Reply to contact`, and cannot be escalated to a Nexum
relationship. Internal notes remain available, and the ticket remains available for reporting.

Technicians can publish an Unpublished ticket in the Customer Portal from the ticket show page.
Published tickets cannot be unpublished from the normal ticket page. Portal replies are stored as
public customer messages and trigger the customer-reply workflow without sending the customer's own
reply back to the customer as an outbound email.

Ticket settings:

Admin surface for queues, types, statuses, priorities, rules, workflows, assignment rules, ticket assignment settings, email defaults, customer portal defaults, solution policy, and merge settings.

## Important Design Rules

Tickets are for actionable work. Not every inbound email should become a ticket. Email Rules, not-ticket behavior, future Operational Signals, and future custom fields should keep noise out of the ticket queue.

Operational events that affect service delivery, customer environments, internal production systems,
security posture, availability, billing readiness, or required follow-up must be traceable as an
internal Ticket or Task. Use a Ticket when the event needs ownership, lifecycle, communication,
time/cost, billing, or reporting history. Use a Task for smaller internal follow-up that does not
need a full Ticket lifecycle.

Ticket state changes should go through shared actions and workflow runtime checks. Avoid bypassing `ChangeTicketStatus`, `TicketActionGuard`, `TicketWorkflowRuntime`, or the existing action classes.

Relationship status sync also uses `ChangeTicketStatus`. Inbound remote statuses
are mapped through relationship policy and do not receive arbitrary access to
local workflow transitions.

Ticket UI must not expose unfinished controls. If a setting or button is visible, the underlying behavior must be implemented, tested, and honest about what it does.
