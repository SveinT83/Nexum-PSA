# Ticket module - status, architecture, and remaining work

Updated: 2026-05-13

This document describes the current Ticket module as it exists in code, how it connects to the rest of tdPSA, what was completed most recently, and the logical order for the remaining work.

The older notes under `app/Modules/Ticket/Views/Admin/Settings/` are still useful product specifications for settings, rules, and workflows, but several controller namespaces and routes in those notes describe the planned system, not the current implementation.

## Current status

The Ticket module is implemented as a module under `app/Modules/Ticket/`.

Implemented now:

- Ticket list, create, show, and add-message routes.
- Module-owned routes in `app/Modules/Ticket/routes.php`.
- Module-owned controllers in `app/Modules/Ticket/Controllers`.
- Module-owned views in `app/Modules/Ticket/Views`.
- Ticket default creation for queue, status, and priorities.
- Manual ticket creation from the Tech UI.
- Client/contact scoped ticket creation.
- Internal notes.
- Customer replies from a ticket to the ticket contact by email.
- Per-message outbound email status on the ticket conversation.
- Manual mark-as-read handling for unread tickets.
- Basic lifecycle operations for status, queue, priority, category, owner, and close.
- Ticket Actions v1 with named action definitions and a shared guard for mutable ticket operations.
- Ticket Workflow v1 with workflow, state, and transition models plus runtime transition validation.
- Workflow runtime requirements for internal notes, public technician responses, and selected solution responses.
- Workflow transition controls for whether a transition is manually clickable and which ticket actions can trigger it automatically.
- Customer reply intents for update, request customer input, and send solution.
- Ticket reply recipient override, CC recipients, and internal note technician notifications.
- Ticket conversation actions for marking a public technician reply as the ticket solution.
- Dedicated ticket edit view for subject, description, and lifecycle fields.
- Asset selection on create/edit, scoped by client/contact rules.
- Explicit site selection and automatic site resolution from contact or asset.
- Admin setting for selecting the default outbound ticket email account.
- Admin management for ticket queues and ticket types.
- Admin management for ticket statuses and ticket priorities.
- Ticket Rules MVP for `on_create` field routing.
- SLA resolution on ticket creation using Ticket Rule override, active contract SLA, then default SLA.
- Ticket SLA source tracking with policy snapshot and first response / resolve due timestamps.
- Ticket show displays SLA policy, source, first response target, resolve target, and first response completion.
- Inbound email linking to existing tickets by ticket key in the subject through Email Rules or fallback processing.
- Inbound email linking to existing tickets by `In-Reply-To`/`References` matching prior outbound ticket reply `Message-ID` logs.
- Inbound email rule action for creating a new ticket from an unmatched email.
- Default inbound email policy that creates tickets automatically when the sender matches an active client contact.
- Default inbound email policy that creates Lead tickets for unknown senders unless the Email filter archived or tagged the message as not-ticket/noise first.
- Ticket tags using the shared `taggables` table, including manual ticket edit support and inbound Email tag inheritance.
- Ticket attachments with dedicated storage records, upload on ticket messages, download links, inbound Email attachment copying, and outbound customer reply sending.
- Ticket Assignment Settings for future assignment, including capacity and category/tag matching.
- Assignment Rules for explicit owner assignment by client, contact, queue, category, priority, ticket type, or channel.
- Assignment Engine that runs after Ticket Rules and can fall back to assignment settings scoring.
- Ticket show assignment panel and manual re-run assignment action.
- Ticket index filters for priority, category, unread, unassigned, and open/closed lifecycle.
- Ticket index shows compact SLA risk badges, assigned technician, active local timer row highlighting, and can sort by SLA risk.
- Ticket time registration from the ticket show page, including work date, minutes, selected time rate, invoice text, and pending billing/timebank status.
- Ticket show Activity timeline combines conversation messages and time records in the same accordion list.
- Ticket show Time rightbar widget includes a local per-ticket stopwatch with pause/resume and stop-to-register flow.
- Ticket cost registration can reserve active Storage items from a ticket, show the reservation as an editable Activity row, and leave billing decisions pending.
- Manual bulk ticket merging from the Ticket list. Technicians select two or more tickets, choose the primary ticket in a Bootstrap modal, and merge the rest into that primary ticket. Merging moves conversation messages, time, costs, attachments, linked email records, tasks, tags, and relevant allocation records onto the target ticket, records an audit event, soft-deletes the source ticket, and redirects old source-ticket links to the target.
- Ticket merge settings under Ticket Settings for exact duplicate inbound email auto-merge and AI-assisted merge suggestions. Exact duplicate inbound emails can link to an existing open ticket when global auto-merge is enabled. Merge suggestions use local subject/body similarity, shared reference or asset-like tokens in the subject, reply-prefix/internal-ticket-reference subject normalization, and client/contact context to show candidate merges in the Ticket list. Suggestions can group multiple related tickets into one primary ticket. A technician must confirm every suggested merge, and dismissed suggestion pairs are remembered.
- Feature tests for the current main flows.

Most recent completed work:

- Email Rules can now run `create_ticket` to create a Ticket from an unmatched inbound email and link that email as the first public customer reply.
- Known client contacts now route to Ticket by default after explicit Email Rules and subject-token linking have run; Email Rules are for exceptions and overrides.
- `CreateTicketFromInboundEmail` resolves known contacts by sender email, assigns client/site/contact context, uses an explicit queue target or recipient queue inference, and delegates ticket defaults/routing to `StoreTicket`.
- Inbound Email tags are inherited to created or linked tickets before Ticket Rules/assignment finish, so email pre-classification can drive ticket category/tag decisions.
- Ticket Rules can now set category and add tags in addition to type, queue, and priority.
- Ticket Assignment scoring now includes tag skill matches in addition to category, working hours, and capacity.
- Ticket messages can now store uploaded attachments in `ticket_attachments`; inbound Email attachments are copied to ticket-owned attachment records when the email is linked, and customer reply attachments are sent with outbound SMTP.
- Inbound replies can now match existing tickets from email headers even when the customer changes the subject.
- Unknown inbound senders now become Lead tickets by default, while archived or not-ticket/noise-tagged messages stay out of Ticket.
- Ticket Assignment Settings now exist under Ticket, with self-service settings and admin management under Ticket Settings.
- Ticket assignment matching can be tied to ticket categories and tags, preparing assignment scoring without hardcoding client-by-client rules.
- Assignment Rules now exist under Ticket Settings for explicit owner routing, such as customer/contact/queue/category to technician.
- `StoreTicket` now runs assignment after Ticket Rules so queue/category/priority are finalized before ownership is selected.
- Assignment fallback scoring can assign unowned tickets to assignable technicians based on working hours, capacity, and category skill match.
- Ticket show now displays the latest assignment decision and can manually re-run assignment with force enabled.
- Ticket settings now manage queues, types, statuses, and priorities from module-owned admin routes.
- Ticket settings moved queue/type/status/priority create and edit forms into module-owned partials and modal flows.
- Ticket settings prevent deleting statuses and priorities that are already used by tickets; priorities referenced by Ticket Rules are also protected.
- Ticket index gained priority, category, lifecycle, unread, and unassigned filters.
- Ticket time entries are now wired into the ticket show page. Entries snapshot the selected Commercial time rate and stay pending for later billing/timebank settlement instead of consuming contract minutes immediately.
- Ticket time registration now includes a local stopwatch in the rightbar. Stopping the timer opens the Add time modal with elapsed minutes prefilled, while saving still happens through the normal time entry form.
- Ticket activity now shows saved time entries as rows alongside customer replies and internal notes.
- Ticket index now shows the assigned technician or Unassigned and lightly highlights rows that have an active or paused local stopwatch for the current browser.
- Ticket cost entries can now reserve Storage items directly from the ticket show page. Reserving creates a Storage reservation, increments the item's reserved quantity, snapshots item details on the ticket cost entry, and shows the entry in Activity with edit support for quantity and invoice text.
- Inbound email can link an existing email message to an existing ticket when the subject contains a ticket key such as `TD-2026-000001`.
- Customer reply email sending was connected and tested.
- `AddTicketMessage` now queues `SendTicketReplyEmail` after commit for messages of type `customer_reply`.
- `SendTicketReplyEmail` resolves the `tickets` default email account, renders the `tickets/ticket_reply` template, sends via SMTP, and writes an outbound `EmailLog`.
- Ticket show now displays the latest outbound email status for each customer reply.
- Technician-authored customer replies no longer mark the ticket as unread.
- Unread tickets can now be marked as read from the ticket show page; the action also stamps existing unread messages with `read_at`.
- Workflow v1 now validates status transitions and shows available workflow actions on Ticket show.
- Workflow Editor v2 can create and edit workflows, states, and transitions from the admin UI.
- Workflow Editor v3 uses a Livewire builder for adding states, assigning requirements, and adding next-status transitions from each state.
- Workflow transitions now enforce configured requirements before status changes commit. Default "Mark as solved" transitions require a public technician response and a response marked as the solution.
- The default workflow no longer allows quick close from open states; tickets must move through Resolved before Closed.
- Workflow transitions now decide whether the Ticket show button is manually available. They can also list ticket actions, such as internal note or customer reply, that automatically advance the ticket when the action is completed.
- Customer replies now capture intent so workflow can distinguish a normal update from a request for customer input or a solution reply.
- Workflow can now auto-advance non-closing transitions when their requirements become satisfied, such as after marking a response as the solution. Closing remains a manual click.
- Inbound customer replies now emit a workflow action so tickets waiting on customer input can automatically resume when the customer answers.
- The Ticket show message composer now keeps message type and reply intent on one row. Customer replies can target any active contact for the same client and include CC recipients. Internal notes can notify a selected technician by email.
- Ticket show disables blocked workflow buttons and shows the requirement reason instead of allowing technicians to click through unfinished work.
- Default statuses now include New, In Progress, Waiting Customer, Resolved, and Closed.
- Ticket show includes a right-side customer card with client, site, contact, email, and clickable phone links sourced from both legacy client users and the Contact domain.
- Ticket show keeps lifecycle details in the right-side Details card, while full edits happen on `tech.tickets.edit`.
- Tests now cover missing contact email, missing outbound account, missing email template, and SMTP failure logging.
- Ticket settings can update `EmailAccount.defaults_for` so the Email module and Ticket module share the same source of truth for the ticket sender account.
- Ticket SLA v1 now stores `sla_id`, `sla_source`, `sla_source_id`, and `sla_snapshot` on tickets.
- `TicketSlaResolver` maps Commercial SLA profiles to Ticket timers and resolves SLA in this order: Ticket Rule, active Contract, default SLA.
- Ticket Rules can now use the `set_sla` action during `on_create`.
- SLA policies can be marked as the default policy from the Commercial SLA form.
- Contracts can now select a structured SLA policy, and active contracts pass that policy to new tickets for the client.
- Customer replies now stamp `first_responded_at` the first time a technician sends a public reply.
- Ticket index now surfaces response overdue, resolve overdue, upcoming SLA target, and SLA policy name.
- Ticket Actions v1 now defines shared action names for update fields, status changes, assignment, internal notes, customer replies, close, mark read, apply SLA, and Knowledge update requests.
- `TicketActionGuard` centralizes basic action checks for active users, closed-ticket restrictions, and customer reply contact-email requirements.
- Ticket show receives an allowed-action map so UI controls can hide blocked operations before workflow rules are fully implemented.
- `ApplyTicketSla` provides a reusable backend action for manually applying an SLA policy and writing an audit event.

## Module structure

Current important files:

- `app/Modules/Ticket/routes.php` - all Ticket routes.
- `app/Modules/Ticket/Controllers/Tech/TicketController.php` - Tech UI entry points.
- `app/Modules/Ticket/Controllers/Admin/TicketSettingsController.php` - Ticket admin settings entry point.
- `app/Modules/Ticket/Actions/EnsureTicketDefaults.php` - creates baseline queue/status/priority records when missing.
- `app/Modules/Ticket/Actions/StoreTicket.php` - creates tickets, syncs ticket tags when provided, creates the initial message/event, and runs assignment.
- `app/Modules/Ticket/Actions/AddTicketMessage.php` - creates ticket messages and events, and dispatches outbound reply email when relevant.
- `app/Modules/Ticket/Actions/StoreTicketAttachment.php` - stores uploaded ticket files and copies inbound Email attachments into ticket-owned storage records.
- `app/Modules/Ticket/Actions/RegisterTicketTimeEntry.php` - stores billable time intent, rate snapshots, and a ticket audit event without settling billing or contract minutes.
- `app/Modules/Ticket/Actions/ReserveTicketStorageItem.php` - reserves active Storage stock for a ticket and creates the pending ticket cost entry.
- `app/Modules/Ticket/Actions/UpdateTicketStorageReservation.php` - edits a ticket cost entry and keeps the linked Storage reservation and item reserved quantity in sync.
- `app/Modules/Ticket/Actions/ChangeTicketStatus.php` - changes ticket status and owns resolved/closed timestamps.
- `app/Modules/Ticket/Actions/CloseTicket.php` - convenience close operation using the same lifecycle status change.
- `app/Modules/Ticket/Actions/MarkTicketRead.php` - clears the ticket unread flag and stamps unread messages as read.
- `app/Modules/Ticket/Actions/MarkTicketMessageSolution.php` - marks one public technician response as the ticket solution for workflow requirements.
- `app/Modules/Ticket/Actions/MergeTickets.php` - consolidates a source ticket into a target ticket, transfers related records, writes merge history, and soft-deletes the source.
- `app/Modules/Ticket/Services/TicketMergeSuggestionService.php` - builds manual merge suggestions from open tickets when merge suggestions are enabled in Ticket Settings.
- `app/Modules/Ticket/Models/TicketMergeSuggestionDismissal.php` - stores dismissed merge suggestion pairs so noisy suggestions stay hidden.
- `app/Modules/Ticket/Actions/UpdateDefaultTicketEmailAccount.php` - updates the Email module's per-scope default for `tickets`.
- `app/Modules/Ticket/Actions/UpdateTicketFields.php` - updates queue, priority, category, and owner with audit events.
- `app/Modules/Ticket/Actions/LinkInboundEmailToTicket.php` - links an Email module inbound message to an existing ticket, inherits Email tags, and creates a public customer reply.
- `app/Modules/Ticket/Actions/CreateTicketFromInboundEmail.php` - creates a new ticket from an unmatched inbound Email module message, passes Email tags into Ticket Rules, and then links the email to the ticket.
- `app/Modules/Ticket/Controllers/Tech/TicketAssignmentSettingsController.php` - technician-owned assignment settings page.
- `app/Modules/Ticket/Controllers/Admin/TicketAssignmentSettingsAdminController.php` - admin management for ticket assignment settings.
- `app/Modules/Ticket/Controllers/Admin/AssignmentRuleAdminController.php` - admin management for assignment rules.
- `app/Modules/Ticket/Jobs/SendTicketReplyEmail.php` - queued SMTP send for customer replies, including ticket message attachments.
- `app/Modules/Ticket/Queries/TicketIndexQuery.php` - filtering, sorting, and pagination for the ticket index.
- `app/Modules/Ticket/Queries/TicketTimeRateOptions.php` - resolves selectable ticket time rates from accepted client contracts and no-contract global rates.
- `app/Modules/Ticket/Services/TicketRuleEngine.php` - evaluates active Ticket Rules for ticket creation context and applies field overrides.
- `app/Modules/Ticket/Services/TicketAssignmentEngine.php` - assigns unowned tickets by assignment rules or assignment settings scoring.
- `app/Modules/Ticket/Livewire/Admin/WorkflowEditor.php` - interactive workflow builder for state selection, requirements, and transitions.
- `app/Modules/Ticket/Models/*` - Ticket data model.
- `app/Modules/Ticket/Tests/Feature/TicketModuleTest.php` - current module feature coverage.

Routes are loaded through the existing Tech route loader, so the current public route names are prefixed with `tech.`:

- `tech.tickets.index`
- `tech.tickets.create`
- `tech.tickets.store`
- `tech.tickets.edit`
- `tech.tickets.update`
- `tech.tickets.show`
- `tech.tickets.close`
- `tech.tickets.messages.store`
- `tech.tickets.messages.solution`
- `tech.tickets.read`
- `tech.tickets.assign`
- `tech.tickets.merge`
- `tech.tickets.profile.edit`
- `tech.tickets.profile.update`
- `tech.admin.settings.tickets`
- `tech.admin.settings.tickets.default-email-account.update`
- `tech.admin.settings.tickets.merge-settings.update`
- `tech.admin.settings.tickets.queues.store`
- `tech.admin.settings.tickets.queues.update`
- `tech.admin.settings.tickets.queues.destroy`
- `tech.admin.settings.tickets.types.store`
- `tech.admin.settings.tickets.types.update`
- `tech.admin.settings.tickets.types.destroy`
- `tech.admin.settings.tickets.statuses.store`
- `tech.admin.settings.tickets.statuses.update`
- `tech.admin.settings.tickets.statuses.destroy`
- `tech.admin.settings.tickets.priorities.store`
- `tech.admin.settings.tickets.priorities.update`
- `tech.admin.settings.tickets.priorities.destroy`
- `tech.admin.settings.tickets.technicians`
- `tech.admin.settings.tickets.technicians.store`
- `tech.admin.settings.tickets.technicians.edit`
- `tech.admin.settings.tickets.technicians.update`
- `tech.admin.settings.tickets.assignment-rules`
- `tech.admin.settings.tickets.assignment-rules.store`
- `tech.admin.settings.tickets.assignment-rules.destroy`
- `tech.admin.settings.tickets.rules`
- `tech.admin.settings.tickets.rules.create`
- `tech.admin.settings.tickets.rules.store`
- `tech.admin.settings.tickets.rules.edit`
- `tech.admin.settings.tickets.rules.update`
- `tech.admin.settings.tickets.rules.toggle`
- `tech.admin.settings.tickets.rules.destroy`
- `tech.admin.settings.tickets.workflows`

## Data model

Ticket tables are created by the `2026_05_11_10000*` migrations.

Main tables:

- `ticket_types` - configurable ticket type definitions such as Support and Lead.
- `ticket_queues` - logical work queues. Has `email_address` and `settings`, but those are not fully used yet.
- `ticket_statuses` - status definitions. Has `state`, `is_default`, `is_closed`, and ordering fields.
- `ticket_priorities` - priority definitions. Current default levels are Critical, High, Normal, and Low.
- `categories` - shared Taxonomy categories used by tickets through `tickets.category_id`.
- `tickets` - core ticket record.
- `ticket_messages` - conversation messages and internal notes.
- `ticket_events` - audit/history events for ticket activity.
- `ticket_time_entries` - time registration records. Stores technician, work date, minutes, invoice text, internal note, pending billing/timebank status, and a snapshot of the selected Commercial time rate. Entries are not settled when saved.
- `ticket_cost_entries` - pending item/cost records for tickets. Stores the selected Storage item, reservation link, quantity, item snapshot, invoice text, internal note, and pending billing status.
- `ticket_time_entry_allocations` - Economy calculation records for ticket time. Stores covered versus billable minutes per ticket time entry so contract timebank decisions do not need to be recalculated repeatedly.
- `ticket_watchers` - watcher model, currently not wired into the UI.
- `ticket_attachments` - stored files linked to ticket messages, including uploaded files and copied inbound Email attachments.
- `ticket_assignment_settings` - per-technician ticket assignment settings, capacity, and notes.
- `ticket_assignment_setting_categories` - assignment matching links to ticket categories.
- `ticket_assignment_setting_tags` - assignment matching links to tags.
- `ticket_assignment_rules` - explicit owner assignment rules evaluated after Ticket Rules.
- `taggables` - shared polymorphic table used by Email tags and Ticket tags.

Important current `tickets` relationships:

- `queue_id` -> `ticket_queues`
- `ticket_type_id` -> `ticket_types`. The legacy `tickets.type` string is still kept for compatibility while newer code moves toward type records.
- `status_id` -> `ticket_statuses`
- `priority_id` -> `ticket_priorities`
- `category_id` -> Taxonomy module `categories`
- `client_id` -> Client module client record
- `site_id` -> Client module site record. Contact selection wins; otherwise selected site, asset site, or the client's only/default site can set it.
- `contact_id` -> Client module contact record
- `asset_id` -> Asset model. When a contact is selected, contact assets are listed before site assets; without a contact, all client assets are available.
- `owner_id`, `created_by`, `updated_by` -> user IDs

Ticket route model binding uses `ticket_key`, not numeric ID. URLs therefore use keys such as `TD-2026-000001`.

## Current flows

### Manual ticket creation

1. Technician opens `tech.tickets.create`.
2. `EnsureTicketDefaults` makes sure baseline queue, status, and priorities exist.
3. The form can optionally be scoped by `client_id`.
4. If a client is selected, available contacts are limited to active contacts for that client's sites.
5. Open tickets for the selected client are shown on the create page to reduce duplicate tickets.
6. `StoreTicket` creates the ticket.
7. If a description is provided, it is stored as the first internal note.
8. A `created` event is written to `ticket_events`.

### Internal note

1. Technician adds a message with type `internal_note`.
2. `TicketController@addMessage` forces visibility to `internal`.
3. `AddTicketMessage` creates the message.
4. A `message_added` event is written.
5. No customer email is sent.

### Customer reply by email

1. Technician adds a message with type `customer_reply`.
2. The ticket must have a contact with an email address.
3. `TicketController@addMessage` forces visibility to `public`.
4. `AddTicketMessage` creates the message and writes a `message_added` event.
5. `SendTicketReplyEmail` is dispatched after the database transaction commits.
6. The job resolves the active Email account configured for `defaults_for = tickets`.
7. If no ticket-specific account exists, the Email module resolver falls back to the active global default account.
8. The job loads the active Email template with `scope = tickets` and `key = ticket_reply`.
9. The template is rendered with ticket/contact/message variables.
10. `SmtpAccountMailer` sends the message using the selected account's SMTP settings.
11. Success or failure is recorded in `email_logs`.

## Connections to other modules

### Email module

The Email module is the main dependency for outbound customer replies.

Used by Ticket:

- `EmailAccount` - stores SMTP/IMAP settings and the `defaults_for` array.
- `DefaultEmailAccountResolver` - resolves the account for the `tickets` scope.
- `EmailTemplate` - stores the `tickets/ticket_reply` template.
- `EmailTemplateRenderer` - renders simple `{{ variable }}` templates.
- `SmtpAccountMailer` - sends the rendered email through the selected SMTP account.
- `EmailLog` - stores outbound success and failure logs.
- `EmailMessage` - stores inbound messages that can be linked to existing tickets.
- `InboundEmailRuleEngine` - can run the `link_ticket_by_subject_token` and `create_ticket` actions or fall back to subject-token linking.

Current shared setting:

- The Ticket settings page updates `EmailAccount.defaults_for`.
- This intentionally avoids a second ticket-only settings table for outbound sender selection.

Current inbound behavior:

- If an inbound email references a previous outbound ticket reply `Message-ID`, or its subject contains a ticket key such as `TD-2026-000001`, the Email module can link it to that ticket.
- Linking creates a public `customer_reply` ticket message, marks the ticket unread, stores source email metadata on the message, marks the email message as linked, and writes an `inbound_email_linked` ticket event.
- The link action is idempotent for the same email message and ticket.
- Email Rules can create a new ticket from an unmatched inbound email with the `create_ticket` action.
- If no prior rule stops processing and the sender matches an active client contact, the Email module creates a ticket by default.
- The optional `create_ticket` action value can target a queue by id or slug; without a value, the engine can infer a queue from `To`/`Cc` recipients matching `ticket_queues.email_address`.
- New inbound tickets are created through `StoreTicket`, so Ticket Rules can still set type, queue, priority, category, and tags from the email context.
- Tags added by Email Rules before ticket creation are inherited to the resulting Ticket and exposed to Ticket Rules through the `email_tags` condition field.

Not completed:

- Matching inbound replies by recipient address.
- Showing email send/log status directly inside the ticket timeline.

### Client module

Ticket creation currently uses Client data for customer context.

Used by Ticket:

- `Client` - selected customer account.
- `ClientUser` - selected contact.
- Client sites are used indirectly because contacts belong to sites.

Current behavior:

- Contacts are only selectable after a client is selected.
- A selected contact must belong to the selected client.
- Open tickets for the selected client are shown on the create page.

Not completed:

- Embedded ticket list inside the Client detail page.
- Stronger client/site/contact relation model inside Ticket views.
- Client portal ticket creation and customer-visible ticket views.

### User Management

Ticket uses the core user model and Spatie roles.

Current behavior:

- Ticket owner can be selected from active users with known technician/admin-style roles.
- If expected roles do not exist, the query falls back to active users.
- The current authenticated user is included as a technician fallback.

Not completed:

- Dedicated ticket permissions and policies.
- Fine-grained permissions for view, create, edit, assign, reply, close, manage rules, manage workflows, and manage settings.

### Asset module

The `tickets.asset_id` column exists, and Asset views mention future related tickets.

Not completed:

- Selecting/linking assets on tickets.
- Showing related tickets on asset detail pages.
- Creating a ticket from an asset alert.

### Sales and Commercial modules

Sales documentation says that many work processes should eventually be represented as tickets.

Not completed:

- Sales/order tickets backed by the Ticket module.
- Commercial contract/SLA linkage to ticket SLA calculations.
- Billing or delivery workflows driven from ticket status/workflow.

### Documentation and Knowledge modules

The intended support workflow should be able to reference documentation and knowledge articles.

Not completed:

- Linking knowledge articles to ticket replies.
- Suggesting articles while replying.
- Creating documentation follow-ups from resolved tickets.

## Settings

Implemented:

- Admin page at `tech.admin.settings.tickets`.
- Default outbound ticket email account.
- The selected account is stored by adding `tickets` to `EmailAccount.defaults_for`.
- Only one account should have the `tickets` scope after saving through the Ticket settings action.
- Queue management.
- Ticket type management.
- Status management.
- Priority management.
- Protection against deleting queues, types, statuses, or priorities that are already in use by tickets or Ticket Rules where supported.

Planned but not implemented:

- Category management.
- Per-queue inbound email address behavior.
- Ticket key format settings.
- Email rule actions for selecting ticket type and queue.
- Email Rule action for `route_to_ticket_module`; Ticket Rules should handle type/queue/priority after that.
- Contract-aware inbound routing: registered customer with active contract routes to support; registered customer without active contract routes to lead/sales ticket type.
- Validation that ticket types referenced by email rules cannot be deleted.
- SLA defaults.
- Time tracking settings.
- Notification toggles.
- Permission settings.
- Autosave settings UI described in the older settings specification.

## Rules and workflows

Current state:

- Ticket Rules have an MVP admin UI at `tech.admin.settings.tickets.rules`.
- Active `on_create` rules are evaluated by `TicketRuleEngine` before a ticket is stored.
- Current actions can set ticket type, queue, priority, SLA, category, and tags.
- Current conditions can use channel, subject, description/body, sender email/domain, email tags, known-client flags, and active-contract flags when those context values are provided.
- Routes exist for `tech.admin.settings.tickets.workflows`.
- Default workflow is generated from active ticket statuses.
- Tickets can store `workflow_id`; new tickets use the active global default workflow.
- Ticket show displays available workflow transitions for the current state.
- Status changes are blocked when the active workflow has no matching transition, and the blocked attempt is logged.
- Admin workflow editor can persist workflow metadata, enabled states, initial/terminal flags, transition rows, and stored transition requirements.
- Specification files exist under `Views/Admin/Settings/rules/` and `Views/Admin/Settings/workflows/`.

Not implemented:

- Ticket Rule execution audit/history population.
- Ticket Rule dry-run/test harness.
- Ticket Rule actions for owner, status, workflow, notes, notifications, and webhooks.
- Automatic population of `client_known` and `client_has_active_contract` context from inbound email sender.
- Workflow binding to queues/categories.
- Runtime enforcement for stored transition requirements such as note, resolution text, and Knowledge update.
- Workflow bindings to queues/categories.

## Known gaps and risks

- Inbound email processing can link replies to existing tickets by reply headers or ticket key in the subject, create tickets by default for known client contacts, and create tickets through explicit Email Rule actions.
- Customer reply email is queued, so production needs a working queue worker unless `QUEUE_CONNECTION=sync` is used for development.
- Failed outbound sends are logged to `email_logs` and surfaced on the relevant customer reply in the Ticket UI.
- Ticket messages still have a legacy `attachments` JSON column, but new file handling uses `ticket_attachments`.
- Time entries and watchers have tables and models, but no current UI/action flow.
- Some future workflow actions, such as required Knowledge updates and transition-specific validation, still need first-class execution handlers.
- Some older documentation still references legacy namespaces such as `App\Http\Controllers\...`; new Ticket work must stay inside `app/Modules/Ticket`.
- `routes/tech.php` currently loads module routes by glob. This works today, but `module-architecture.md` recommends explicit module route loading for AI compatibility.

## Logical remaining work

### 1. Stabilize the current MVP

- Add a clear operational note in deployment docs that ticket email requires queue processing.
- Consider adding a dedicated outbound email event to `ticket_events`, in addition to the current per-message status badge.
- Decide how future inbound/customer-authored replies should mark `is_unread`.

### 2. Finish basic ticket operations

- Add actions and UI for changing client, contact, site, and asset.
- Expand `TicketEvent` display so before/after field changes are easier to read.
- Add filters for type, site, asset, and technician once those are needed in daily operations.

### 3. Harden attachment support

- Add malware scanning/validation hooks before stored files become available to technicians.
- Add attachment deletion/retention rules.
- Add a per-message/per-file toggle if we later need stored internal files that should not be sent on customer replies.

### 4. Connect inbound email to tickets

- Improve header matching edge cases for malformed `In-Reply-To`/`References` values and missing outbound logs.
- Route registered customers with active contracts to a support ticket type, and registered customers without active contracts to a configurable lead/sales ticket type.
- Expand lead routing settings for unknown senders beyond the current default Lead ticket type.
- Expand inbound attachment tests for inline attachments and missing source files.
- Add dedupe/idempotency tests so repeated IMAP processing does not duplicate ticket messages.

### 5. Build ticket settings properly

- Add ticket key format settings and migration-safe key generation rules.
- Keep category management in the Taxonomy module and reuse shared `categories`.
- Keep settings controllers under `app/Modules/Ticket/Controllers/Admin`.
- Keep settings views under `app/Modules/Ticket/Views/Admin`.
- Replace or update old specification files so they match the actual module namespaces and routes.
- Add audit events for settings changes.
- Prevent deletion of ticket queues/types that are referenced by ticket rules, email rules, workflows, or existing tickets.

### 6. Implement Ticket Rules

- Expand beyond the current `on_create` MVP to reply, internal note, field change, status change, timer, SLA warning, and SLA violation triggers.
- Populate Ticket Rule execution logs and surface them in ticket history.
- Add dry-run tests and a UI test harness for sample ticket/email context.
- Implement condition and action handlers in small module-owned classes.
- Add actions for owner, status, workflow, notes, notifications, and webhooks.
- Add deterministic ordering and conflict/collision tests.

### 7. Implement Workflows

- Enforce transition requirements for note, resolution text, and Knowledge update.
- Bind workflows by queue, category, or Ticket Rule.
- Add override handling with justification and audit.

### 8. Add SLA and time tracking

- Add SLA reporting and breach trend views.
- Add time entry UI using `ticket_time_entries`.
- Add reports and billing handoff only after the basic time entry flow is reliable.

### 9. Add cross-module surfaces

- Client module: show active/recent tickets on client detail pages.
- Asset module: show related tickets and allow ticket creation from assets/alerts.
- Knowledge module: suggest or link articles from ticket replies.
- Sales/Commercial: decide which sales and delivery flows are tickets and how their queues/statuses should be separated.

### 10. Harden access control and tests

- Add ticket-specific permissions and policies.
- Cover owner-only, team, admin, and client portal visibility rules.
- Add unit tests for Actions and Queries.
- Add feature tests for every main UI route.
- Add job tests for outbound and inbound email edge cases.
- Run the module test suite before each larger Ticket change.

## Assignment roadmap

Assignment should be a separate layer after Ticket Rules. Ticket Rules classify the work; assignment decides who should own it.

Planned order:

- Ticket Assignment Settings are implemented:
  - One setting record per assignable user.
  - Active/inactive for ticket assignment.
  - Capacity fields such as max open tickets.
  - Matching signals tied to ticket categories and tags.
  - A technician-owned settings page where each technician can maintain ticket assignment preferences.
  - Admin management where admins can maintain profiles for any technician.
- Assignment Rules are implemented:
  - Client/contact/customer-specific owner rules.
  - Queue/category/tag/priority rules.
  - Queue/category/tag/priority/ticket type/channel conditions.
  - Explicit owner overrides for special customers or users.
- Assignment Engine is implemented as an MVP:
  - Runs after inbound email creation and Ticket Rules.
  - Scores candidates by category/tag skill match, current working hours, and existing open-ticket load.
  - Leaves tickets unassigned if no valid candidate exists.
  - Logs assignment decisions for debugging and audit.
  - Can be manually re-run from the ticket show view.

Remaining assignment work:

- Add customer affinity history scoring.
- Add out-of-office/unavailable windows.
- Add richer rule editing and rule reordering.
- Add a full assignment dry-run/debug view explaining why a technician was or was not selected.

## Current near-term TODO

- Ticket tags:
  - Done: first-class `Ticket::tags()` support using the shared `taggables` table.
  - Done: inbound Email tags are inherited when email creates or links to a ticket.
  - Done: Ticket Rules can add tags and set category based on `email_tags`.
  - Done: Assignment Rules and Assignment Engine can use ticket tags and technician tag skills.
  - Done: ticket create/edit/show surfaces active tags.
  - Done: ticket create/edit uses chip-style tag entry with suggestions and creates new shared tags on save.
  - Remaining: add tag filters to the ticket index when daily usage needs it.
- Attachments:
  - Done: create dedicated `ticket_attachments` table/model.
  - Done: upload attachments on ticket messages.
  - Done: copy inbound Email attachments to Ticket attachments.
  - Done: send customer reply attachments with outbound email.
  - Remaining: add scan/delete/retention behavior.
- Inbound matching:
  - Done: match replies by `In-Reply-To` and `References` against outbound ticket reply `Message-ID` logs.
  - Done: keep subject-token fallback.
  - Remaining: add recipient-address/thread fallback for cases without outbound logs.
- Unknown senders:
  - Done: route unknown senders to the default Lead ticket type.
  - Done: skip default Lead creation when Email Rules have archived or not-ticket/noise-tagged the message.
  - Remaining: add settings for lead queue/type overrides if the default Lead type is not enough.
- Permissions:
  - Add ticket-specific permissions for settings, assignment, reply, edit, close, and run assignment.

Skill model:

- Categories represent broad domains such as Network, Printer, Email, or Server.
- Ticket category selects show active categories marked as `ticket` plus active general categories with no type.
- Tags represent specific tools, vendors, technologies, or customer traits such as Fortigate, Microsoft 365, UniFi, VIP, or onsite.
- Assignment can use both category and tag skills once Ticket Rules set category/tags on the ticket.

## Useful commands

Run Ticket feature tests:

```bash
php artisan test app/Modules/Ticket/Tests/Feature/TicketModuleTest.php
```

Check Ticket routes:

```bash
php artisan route:list --name=tech.tickets
php artisan route:list --name=tech.admin.settings.tickets
```

Process queued ticket reply emails in development:

```bash
php artisan queue:work --sleep=3 --tries=3
```

Seed default email templates if the `tickets/ticket_reply` template is missing:

```bash
php artisan db:seed --class=EmailTemplateSeeder
```
