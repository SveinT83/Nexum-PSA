# Ticket module - status, architecture, and remaining work

Updated: 2026-05-12

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
- Admin setting for selecting the default outbound ticket email account.
- Feature tests for the current main flows.

Most recent completed work:

- Customer reply email sending was connected and tested.
- `AddTicketMessage` now queues `SendTicketReplyEmail` after commit for messages of type `customer_reply`.
- `SendTicketReplyEmail` resolves the `tickets` default email account, renders the `tickets/ticket_reply` template, sends via SMTP, and writes an outbound `EmailLog`.
- Ticket show now displays the latest outbound email status for each customer reply.
- Technician-authored customer replies no longer mark the ticket as unread.
- Unread tickets can now be marked as read from the ticket show page; the action also stamps existing unread messages with `read_at`.
- Workflow implementation was intentionally deferred. The Ticket module now has lower-level lifecycle actions that future workflows can validate instead of replacing.
- Default statuses now include New, In Progress, Waiting Customer, Resolved, and Closed.
- Tests now cover missing contact email, missing outbound account, missing email template, and SMTP failure logging.
- Ticket settings can update `EmailAccount.defaults_for` so the Email module and Ticket module share the same source of truth for the ticket sender account.

## Module structure

Current important files:

- `app/Modules/Ticket/routes.php` - all Ticket routes.
- `app/Modules/Ticket/Controllers/Tech/TicketController.php` - Tech UI entry points.
- `app/Modules/Ticket/Controllers/Admin/TicketSettingsController.php` - Ticket admin settings entry point.
- `app/Modules/Ticket/Actions/EnsureTicketDefaults.php` - creates baseline queue/status/priority records when missing.
- `app/Modules/Ticket/Actions/StoreTicket.php` - creates tickets, initial message, and creation event.
- `app/Modules/Ticket/Actions/AddTicketMessage.php` - creates ticket messages and events, and dispatches outbound reply email when relevant.
- `app/Modules/Ticket/Actions/ChangeTicketStatus.php` - changes ticket status and owns resolved/closed timestamps.
- `app/Modules/Ticket/Actions/CloseTicket.php` - convenience close operation using the same lifecycle status change.
- `app/Modules/Ticket/Actions/MarkTicketRead.php` - clears the ticket unread flag and stamps unread messages as read.
- `app/Modules/Ticket/Actions/UpdateDefaultTicketEmailAccount.php` - updates the Email module's per-scope default for `tickets`.
- `app/Modules/Ticket/Actions/UpdateTicketFields.php` - updates queue, priority, category, and owner with audit events.
- `app/Modules/Ticket/Jobs/SendTicketReplyEmail.php` - queued SMTP send for customer replies.
- `app/Modules/Ticket/Queries/TicketIndexQuery.php` - filtering, sorting, and pagination for the ticket index.
- `app/Modules/Ticket/Models/*` - Ticket data model.
- `app/Modules/Ticket/Tests/Feature/TicketModuleTest.php` - current module feature coverage.

Routes are loaded through the existing Tech route loader, so the current public route names are prefixed with `tech.`:

- `tech.tickets.index`
- `tech.tickets.create`
- `tech.tickets.store`
- `tech.tickets.show`
- `tech.tickets.messages.store`
- `tech.admin.settings.tickets`
- `tech.admin.settings.tickets.default-email-account.update`
- `tech.admin.settings.tickets.rules`
- `tech.admin.settings.tickets.workflows`

## Data model

Ticket tables are created by the `2026_05_11_10000*` migrations.

Main tables:

- `ticket_queues` - logical work queues. Has `email_address` and `settings`, but those are not fully used yet.
- `ticket_statuses` - status definitions. Has `state`, `is_default`, `is_closed`, and ordering fields.
- `ticket_priorities` - priority definitions. Current default levels are Critical, High, Normal, and Low.
- `ticket_categories` - nested ticket categories.
- `tickets` - core ticket record.
- `ticket_messages` - conversation messages and internal notes.
- `ticket_events` - audit/history events for ticket activity.
- `ticket_time_entries` - time registration model, currently not wired into the UI.
- `ticket_watchers` - watcher model, currently not wired into the UI.

Important current `tickets` relationships:

- `queue_id` -> `ticket_queues`
- `status_id` -> `ticket_statuses`
- `priority_id` -> `ticket_priorities`
- `category_id` -> `ticket_categories`
- `client_id` -> Client module client record
- `contact_id` -> Client module contact record
- `owner_id`, `created_by`, `updated_by` -> user IDs
- `site_id` and `asset_id` are present for future module links, but are not fully connected in UI yet.

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

Current shared setting:

- The Ticket settings page updates `EmailAccount.defaults_for`.
- This intentionally avoids a second ticket-only settings table for outbound sender selection.

Not completed:

- Inbound email to ticket creation/update.
- Matching inbound replies to existing tickets by `Message-ID`, `In-Reply-To`, `References`, recipient address, or ticket key in subject.
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

Planned but not implemented:

- Queue management.
- Status management.
- Priority management.
- Category management.
- Per-queue inbound email address behavior.
- SLA defaults.
- Time tracking settings.
- Notification toggles.
- Permission settings.
- Autosave settings UI described in the older settings specification.

## Rules and workflows

Current state:

- Routes exist for `tech.admin.settings.tickets.rules` and `tech.admin.settings.tickets.workflows`.
- The controller attempts to load future views and falls back to the main settings view when the namespaced view does not exist.
- Specification files exist under `Views/Admin/Settings/rules/` and `Views/Admin/Settings/workflows/`.

Not implemented:

- Ticket rules data model.
- Rule editor.
- Rule execution engine.
- Rule audit/history.
- Workflow data model.
- Workflow state machine.
- Workflow transition validation.
- Workflow binding to queues/categories.
- Runtime workflow panel on the ticket view.

## Known gaps and risks

- Inbound email processing is still separate from Ticket. `EmailMessage.ticket_id` exists on the Email side, but the linking/creation flow is not implemented.
- Customer reply email is queued, so production needs a working queue worker unless `QUEUE_CONNECTION=sync` is used for development.
- Failed outbound sends are logged to `email_logs` and surfaced on the relevant customer reply in the Ticket UI.
- Ticket messages have an `attachments` JSON column, but file upload/attachment handling is not implemented in the Ticket UI.
- Time entries and watchers have tables and models, but no current UI/action flow.
- Status changes, assignment changes, priority changes, close/resolution, and SLA dates are not yet first-class actions.
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
- Add filters for priority, category, unread, unassigned, and closed/open.

### 3. Build attachment support

- Add upload support to ticket messages.
- Decide whether ticket attachments should use a dedicated `ticket_attachments` table or continue with `ticket_messages.attachments` JSON.
- Scan, store, and display attachments consistently with the Email module's storage rules.
- Include attachments in outbound customer replies only after explicit design approval.

### 4. Connect inbound email to tickets

- Extend `ProcessInboundRules` or add a Ticket-specific inbound action that can create or update tickets.
- Match existing tickets by ticket key in subject, `In-Reply-To`, `References`, and stored RFC message IDs.
- Create new tickets from unmatched inbound messages sent to ticket queues.
- Link `email_messages.ticket_id` when an email becomes a ticket or ticket message.
- Convert inbound email bodies and attachments into `TicketMessage` records.
- Add dedupe/idempotency tests so repeated IMAP processing does not duplicate ticket messages.

### 5. Build ticket settings properly

- Implement queue, status, priority, and category management inside the Ticket module.
- Keep settings controllers under `app/Modules/Ticket/Controllers/Admin`.
- Keep settings views under `app/Modules/Ticket/Views/Admin`.
- Replace or update old specification files so they match the actual module namespaces and routes.
- Add audit events for settings changes.

### 6. Implement Ticket Rules

- Create rule models/migrations.
- Build the admin rule list and editor.
- Define triggers for create, reply, internal note, field change, status change, timer, SLA warning, and SLA violation.
- Implement condition and action handlers in small module-owned classes.
- Log rule execution into ticket history.
- Add dry-run tests and deterministic ordering tests.

### 7. Implement Workflows

- Create workflow, version, state, transition, and binding models/migrations.
- Bind workflows by global default, queue, category, or Ticket Rule.
- Add allowed transition checks to ticket status changes.
- Add a workflow panel to the ticket show view.
- Log blocked transitions, overrides, and workflow changes.

### 8. Add SLA and time tracking

- Decide how Commercial SLA records map to Ticket SLA policy.
- Populate `first_response_due_at` and `resolve_due_at` when tickets are created.
- Set `first_responded_at` when the first public technician response is sent.
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
