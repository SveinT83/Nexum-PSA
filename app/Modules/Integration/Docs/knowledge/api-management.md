API Management is available from Admin -> Integrations -> API.

Nexum PSA uses Laravel Sanctum personal access tokens for API authentication.

API requests must send:

```text
Authorization: Bearer {token}
Accept: application/json
```

API-key creation is least-privilege. No scope is selected by default, an ordinary key requires at
least one explicit scope, and full access requires a separate confirmation. Existing broad keys are
flagged for review but are never changed automatically.

Coordinator tokens are created from AI Privacy & Coordinator Governance. They bind to an approved
workload, contain read abilities only, expire, and are policy-checked on every request.

## Integration Hub and MCP

The private Nexum Integration Hub uses a stricter two-identity boundary than an ordinary API key.
An interactive user or approved coordinator workload uses a narrow token only to request a
short-lived, signed, one-time execution grant for one named capability and explicit scope. The MCP
service then uses its separate bound service token plus that grant for exactly one protected read.
The service token cannot issue grants or exercise a read capability by itself, and an inbound MCP
token is never forwarded to Nexum.

Current capabilities are read-only: effective identity, scoped Clients/Sites, explicit domain
bindings, sanitized Integration health, durable Executions/audit, and one explicitly bound Plesk
site inspection. Provider credentials, server URLs, raw errors/payloads, grants, and tokens are not
returned. Missing scope or binding denies by default; a configured provider is never reported as
healthy without fresh verified evidence.

Administrators enable the Hub only after migrations, signing configuration, workload approval, and
readiness review. Service-token creation requires an explicit IP/CIDR boundary unless the operator
deliberately accepts any network. Emergency controls require a separate narrow operator token and
can stop global, capability, Integration, Client, or Site scope. See the Integration Hub Knowledge
article and deployment runbooks before issuing or rotating credentials.

## Internal Model Workloads

An internal model workload is the governed execution boundary for a Nexum feature that needs strict
structured AI output without granting an AI agent API access. Create it under **Admin > System >
Integrations > AI Privacy & Coordinator Governance**. It is separate from a coordinator workload and
cannot issue an API token.

The selected agent and provider must be active. The agent must be non-writing and have no data
sources, tools, allowed API scopes, or action-execution capability. Its model and agent governance
policies must be approved, unexpired, and exactly compatible with the workload's processing mode and
data profile. Installation policy and, for external processing, provider governance must also allow
the request. These requirements are checked again at execution time; the admin form is not treated
as a security boundary.

Internal workloads accept a versioned, minimized input contract and require a strict JSON response
schema. Message content is untrusted data: it cannot add tools, widen fields, change policy, or
authorize a domain write. Provider output must pass schema validation and the owning domain's normal
evidence, permission, idempotency, and business-rule checks before anything is persisted.

The supplier-order workflow uses this boundary for the optional **Purchase Order Import Agent**. The
workload may extract a canonical order, diagnose an import, or propose a declarative profile version.
It cannot create an Item, Vendor, Purchase Order, receipt, or stock movement directly. Storage owns
those decisions, and an order-confirmation path never receives inventory.

Choose a processing mode and maximum data profile that cover the source material without exceeding
installation policy. If the provider, model, agent, workload, or governance approval is missing,
inactive, expired, or incompatible, execution fails closed. Storage then follows its configured
provider-outage behavior and records an operational reason instead of falling back to an ungoverned
call.

Execution telemetry stores bounded policy and usage metadata such as workload, model, processing
mode, status, duration, and token/cost facts when available. It must not retain the raw supplier
email, unrestricted prompt or response, credentials, or provider secrets.

Every structured execution also creates a metadata-only AI access event before any provider
request is sent. If the access event cannot be created, or its terminal result cannot be recorded,
the workload fails closed and its output cannot authorize a domain write. The access event contains
the feature, operation, domain, workload and effective data-policy facts, not source content or a
model response.

A workload request with a cost ceiling must name an ISO currency. The provider must return a cost
in that currency; an unavailable cost, unavailable currency, mismatch, or amount above the remaining
ceiling invalidates the result. The owning domain may apply one cumulative budget across extraction,
secondary verification, and repair. A required consensus uses a separately configured governed
workload and accepts the primary result only when their material projections agree. These controls
prevent use of an over-budget or disputed result; they cannot undo a provider charge already incurred.

## Scopes

API keys are created with explicit scopes.

Implemented scopes:

- `clients.read`: list and view clients.
- `clients.create`: create clients and their default site.
- `clients.update`: update clients and manage client sites.
- `assets.read`: list and view assets.
- `assets.create`: create assets.
- `assets.update`: update assets.
- `contacts.read`: list and view contacts.
- `contacts.create`: create contacts through the Contact upsert endpoint.
- `contacts.update`: update contacts, including client and site relations.
- `contacts.ownership_manage`: repair Contact ownership across Clients and legacy Client Users.
- `marketing.read`: list and view marketing lists, campaigns, recipients, and settings.
- `marketing.lists.manage`: create, update, delete, refresh, and manage contacts on marketing mailing lists.
- `marketing.campaigns.create`: create marketing campaigns from mailing lists.
- `marketing.campaigns.update`: update campaigns, schedules, campaign emails, test sends, and AI draft requests.
- `marketing.campaigns.approve`: approve campaigns and queue recipients.
- `marketing.campaigns.send`: queue due-send processing for approved campaigns.
- `marketing.settings.update`: update consent, unsubscribe, tracking, quiet hours, and batching settings.
- `tickets.read`: list and view tickets.
- `tickets.create`: create tickets through the ticket engine.
- `tickets.update`: update ticket fields and status.
- `tickets.portal.publish`: publish an eligible client Ticket through the one-way Customer Portal action.
- `tickets.reply_customer`: send an idempotent public technician reply through normal Ticket email and workflow behavior.
- `tickets.actions`: perform workflow-approved Ticket actions such as transition, escalation, close, planned scope, quote, review, evidence, conversion, and purchase need.
- `tickets.workflow.read`: inspect the current state, missing requirements, available actions, transitions, and escalation paths.
- `tickets.workflow.manage`: create and edit draft workflow definitions and preview active-Ticket migration.
- `tickets.workflow.publish`: publish immutable workflow versions and apply an authorized active-Ticket migration.
- `tasks.read`: list and view tasks.
- `tasks.create`: create tasks.
- `tasks.update`: update task fields and status.
- `knowledge.read`: list and view knowledge shelves, books, chapters, articles, and Documentation records.
- `knowledge.create`: create knowledge shelves, books, chapters, articles, Documentation categories, templates, and records.
- `knowledge.update`: update or delete knowledge shelves, books, chapters, articles, and Documentation records.
- `integration.bookstack.read`: read sanitized BookStack sync status and summaries.
- `integration.bookstack.run`: test the BookStack connection and run pull or push sync operations.
- `relationships.read`: read Nexum relationship configuration and sync health.
- `relationships.sync`: exchange signed ticket, status, documentation, and Knowledge payloads between Nexum installations.
- `storage.read`: list and view storage items, warehouses, and boxes.
- `storage.create`: create storage items, warehouses, and boxes.
- `storage.update`: update storage records and adjust stock.
- `calendar.read`: list calendars and view calendar events.
- `calendar.create`: create calendar events.
- `calendar.update`: update calendar events.
- `calendar.delete`: delete calendar events.
- `risk.read`: list and view risk assessments and risk items.
- `risk.create`: create risk assessments and risk items.
- `risk.update`: update risk assessments, risk items, and risk item history.
- `email.read`: list and view unrouted inbox messages.
- `email.update`: mark inbox messages as spam and queue inbox polling.
- `notifications.read`: list and view the authenticated user notifications.
- `notifications.update`: mark the authenticated user notifications as read.
- `sales.read`: list and view sales opportunities and activities.
- `sales.create`: create sales opportunities through the sales engine.
- `sales.update`: update sales opportunities and add sales activities.
- `sales.quote_templates.read`: read Sales quote-template catalogs and configured reusable templates.
- `sales.quote_templates.manage`: create, update, and delete Sales quote templates, template lines, option groups, and acknowledgements.
- `lead-intelligence.read`: read Lead Intelligence settings, segments, research runs, scan ledger, and policy results.
- `lead-intelligence.manage`: update Lead Intelligence settings and manage lead segments.
- `lead-intelligence.run`: create planned research runs, evaluate contact marketing eligibility, and promote approved candidates.
- `taxonomy.read`: list and view shared categories and tags.
- `taxonomy.create`: create shared categories and tags.
- `taxonomy.update`: update shared categories and tags.
- `taxonomy.delete`: soft-delete shared categories and tags.
- `commercial.read`: list and view commercial services, contracts, SLA policies, and time rates.
- `commercial.create`: create commercial services, contracts, SLA policies, and time rates.
- `commercial.update`: update commercial services, contracts, SLA policies, and time rates.
- `economy.read`: list and view economy orders and generated order lines.
- `economy.create`: generate economy orders from billable ticket time and picked ticket costs.
- `economy.update`: move economy orders between draft and ready states.
- `economy.delete`: delete empty economy orders and draft order lines.
- `data_exchange.read`: list Data Exchange profiles and inspect run status.
- `data_exchange.run`: trigger implemented Data Exchange export profiles.
- `data_exchange.download`: download generated Data Exchange files.
- `data_exchange.import`: start import dry-runs for approved Data Exchange import targets.
- `data_exchange.approve_import`: commit valid Data Exchange import previews through approved targets.
- `report.read`: list and view available report definitions.
- `worklog.read`: read aggregate or pseudonymized technician worklog summaries.
- `time-entries.read`: read minimized time entries without names, titles, or notes.
- `users.read`: list and view users, roles, and user profile metadata.
- `users.create`: create users and queue invitations for pending users.
- `users.update`: update user profiles, statuses, roles, and resend invitations.

Full access can be selected by an admin when a trusted integration needs every implemented API scope.

Do not add scopes to the catalog before the matching routes and tests exist.

## Current Routes

Current API routes are under `/api/v1`:

- `GET /api/v1/clients`
- `GET /api/v1/clients/{client}`
- `GET /api/v1/clients/{client}/assets`
- `GET /api/v1/clients/{client}/sites`
- `POST /api/v1/clients`
- `PUT /api/v1/clients/{client}`
- `PATCH /api/v1/clients/{client}`
- `POST /api/v1/clients/{client}/sites`
- `PUT /api/v1/client-sites/{site}`
- `PATCH /api/v1/client-sites/{site}`
- `GET /api/v1/assets`
- `GET /api/v1/assets/{asset}`
- `POST /api/v1/assets`
- `PUT /api/v1/assets/{asset}`
- `PATCH /api/v1/assets/{asset}`
- `GET /api/v1/contacts`
- `GET /api/v1/contacts/{contact}`
- `POST /api/v1/contacts`
- `PUT /api/v1/contacts/{contact}`
- `PATCH /api/v1/contacts/{contact}`
- `GET /api/v1/clients/{client}/contacts`
- `POST /api/v1/contacts/{contact}/move`
- `POST /api/v1/clients/{client}/contacts/bulk-fix`
- `POST /api/v1/clients/{client}/contacts/legacy-orphans/cleanup`
- `DELETE /api/v1/clients/{client}/contacts/{contact}`
- `GET /api/v1/marketing/lists`
- `POST /api/v1/marketing/lists`
- `GET /api/v1/marketing/lists/{list}`
- `PUT /api/v1/marketing/lists/{list}`
- `PATCH /api/v1/marketing/lists/{list}`
- `DELETE /api/v1/marketing/lists/{list}`
- `GET /api/v1/marketing/lists/{list}/members`
- `POST /api/v1/marketing/lists/{list}/refresh`
- `POST /api/v1/marketing/lists/{list}/contacts`
- `DELETE /api/v1/marketing/lists/{list}/contacts/{contact}`
- `GET /api/v1/marketing/campaigns`
- `POST /api/v1/marketing/campaigns`
- `GET /api/v1/marketing/campaigns/{campaign}`
- `PUT /api/v1/marketing/campaigns/{campaign}`
- `PATCH /api/v1/marketing/campaigns/{campaign}`
- `POST /api/v1/marketing/campaigns/{campaign}/ai-plan`
- `PUT /api/v1/marketing/campaigns/{campaign}/schedule`
- `PATCH /api/v1/marketing/campaigns/{campaign}/schedule`
- `POST /api/v1/marketing/campaigns/{campaign}/emails`
- `POST /api/v1/marketing/campaigns/{campaign}/emails/ai-draft`
- `PUT /api/v1/marketing/campaigns/{campaign}/emails/{email}`
- `PATCH /api/v1/marketing/campaigns/{campaign}/emails/{email}`
- `POST /api/v1/marketing/campaigns/{campaign}/emails/{email}/test-send`
- `DELETE /api/v1/marketing/campaigns/{campaign}/emails/{email}`
- `POST /api/v1/marketing/campaigns/{campaign}/approve`
- `POST /api/v1/marketing/campaigns/{campaign}/send-due`
- `GET /api/v1/marketing/settings`
- `PUT /api/v1/marketing/settings`
- `PATCH /api/v1/marketing/settings`
- `GET /api/v1/tickets`
- `GET /api/v1/tickets/{ticket}`
- `POST /api/v1/tickets`
- `PUT /api/v1/tickets/{ticket}`
- `PATCH /api/v1/tickets/{ticket}`
- `POST /api/v1/tickets/{ticket}/external-messages`
- `POST /api/v1/tickets/{ticket}/portal-visibility`
- `POST /api/v1/tickets/{ticket}/messages`
- `POST /api/v1/tickets/{ticket}/timer/start`
- `POST /api/v1/tickets/{ticket}/time-entries`
- `POST /api/v1/tickets/{ticket}/cost-entries`
- `GET /api/v1/tickets/{ticket}/workflow-decisions`
- `POST /api/v1/tickets/{ticket}/workflow-transitions/{transitionKey}`
- `POST /api/v1/tickets/{ticket}/workflow-escalations/{pathKey}`
- `POST /api/v1/tickets/{ticket}/close`
- `POST /api/v1/tickets/{ticket}/planned-lines`
- `DELETE /api/v1/tickets/{ticket}/planned-lines/{plannedLine}`
- `POST /api/v1/tickets/{ticket}/planned-lines/{plannedLine}/convert`
- `POST /api/v1/tickets/{ticket}/planned-lines/{plannedLine}/purchase`
- `POST /api/v1/tickets/{ticket}/sales-quote`
- `POST /api/v1/tickets/{ticket}/sales-quote/send`
- `POST /api/v1/tickets/{ticket}/messages/{message}/quote-versions/{version}/accept`
- `POST /api/v1/tickets/{ticket}/workflow-reviews`
- `POST /api/v1/tickets/{ticket}/workflow-reviews/{review}/decision`
- `POST /api/v1/tickets/{ticket}/workflow-evidence`
- `GET /api/v1/ticket-workflow-catalog`
- `GET /api/v1/ticket-workflows`
- `POST /api/v1/ticket-workflows`
- `GET /api/v1/ticket-workflows/{workflow}`
- `PUT /api/v1/ticket-workflows/{workflow}`
- `POST /api/v1/ticket-workflows/{workflow}/publish`
- `POST /api/v1/ticket-workflows/{workflow}/migration-preview`
- `POST /api/v1/ticket-workflows/{workflow}/migrations`
- `GET /api/v1/tasks`
- `GET /api/v1/tasks/{task}`
- `POST /api/v1/tasks`
- `PUT /api/v1/tasks/{task}`
- `PATCH /api/v1/tasks/{task}`
- `GET /api/v1/knowledge/articles`
- `GET /api/v1/knowledge/articles/{article}`
- `POST /api/v1/knowledge/articles`
- `PUT /api/v1/knowledge/articles/{article}`
- `PATCH /api/v1/knowledge/articles/{article}`
- `DELETE /api/v1/knowledge/articles/{article}`
- `GET /api/v1/knowledge/shelves`
- `GET /api/v1/knowledge/shelves/{shelf}`
- `POST /api/v1/knowledge/shelves`
- `PUT /api/v1/knowledge/shelves/{shelf}`
- `PATCH /api/v1/knowledge/shelves/{shelf}`
- `DELETE /api/v1/knowledge/shelves/{shelf}`
- `GET /api/v1/knowledge/books`
- `GET /api/v1/knowledge/books/{book}`
- `POST /api/v1/knowledge/books`
- `PUT /api/v1/knowledge/books/{book}`
- `PATCH /api/v1/knowledge/books/{book}`
- `DELETE /api/v1/knowledge/books/{book}`
- `GET /api/v1/knowledge/chapters`
- `GET /api/v1/knowledge/chapters/{chapter}`
- `POST /api/v1/knowledge/chapters`
- `PUT /api/v1/knowledge/chapters/{chapter}`
- `PATCH /api/v1/knowledge/chapters/{chapter}`
- `DELETE /api/v1/knowledge/chapters/{chapter}`
- `GET /api/v1/integrations/book-stack/status`
- `POST /api/v1/integrations/book-stack/test`
- `POST /api/v1/integrations/book-stack/pull`
- `POST /api/v1/integrations/book-stack/push`
- `POST /api/v1/nexum/relationships/tickets`
- `POST /api/v1/nexum/relationships/tickets/{remoteTicketId}/messages`
- `POST /api/v1/nexum/relationships/tickets/{remoteTicketId}/status`
- `POST /api/v1/nexum/relationships/documentation`
- `POST /api/v1/nexum/relationships/knowledge/articles`
- `GET /api/v1/storage/items`
- `GET /api/v1/storage/items/{item}`
- `POST /api/v1/storage/items`
- `PUT /api/v1/storage/items/{item}`
- `PATCH /api/v1/storage/items/{item}`
- `POST /api/v1/storage/items/{item}/adjust`
- `GET /api/v1/storage/warehouses`
- `POST /api/v1/storage/warehouses`
- `PUT /api/v1/storage/warehouses/{warehouse}`
- `PATCH /api/v1/storage/warehouses/{warehouse}`
- `GET /api/v1/storage/boxes`
- `POST /api/v1/storage/boxes`
- `PUT /api/v1/storage/boxes/{box}`
- `PATCH /api/v1/storage/boxes/{box}`
- `GET /api/v1/calendars`
- `GET /api/v1/calendar/events`
- `GET /api/v1/calendar/events/{event}`
- `POST /api/v1/calendar/events`
- `PUT /api/v1/calendar/events/{event}`
- `PATCH /api/v1/calendar/events/{event}`
- `DELETE /api/v1/calendar/events/{event}`
- `GET /api/v1/risk/assessments`
- `GET /api/v1/risk/assessments/{assessment}`
- `POST /api/v1/risk/assessments`
- `PUT /api/v1/risk/assessments/{assessment}`
- `PATCH /api/v1/risk/assessments/{assessment}`
- `POST /api/v1/risk/assessments/{assessment}/items`
- `GET /api/v1/risk/items/{item}`
- `PUT /api/v1/risk/items/{item}`
- `PATCH /api/v1/risk/items/{item}`
- `POST /api/v1/risk/items/{item}/updates`
- `GET /api/v1/email/inbox/messages`
- `GET /api/v1/email/inbox/messages/{message}`
- `POST /api/v1/email/inbox/messages/{message}/spam`
- `POST /api/v1/email/inbox/poll`
- `GET /api/v1/notifications`
- `POST /api/v1/notifications/{notification}/read`
- `POST /api/v1/notifications/read-all`
- `GET /api/v1/sales/opportunities`
- `GET /api/v1/sales/opportunities/{opportunity}`
- `POST /api/v1/sales/opportunities`
- `PUT /api/v1/sales/opportunities/{opportunity}`
- `PATCH /api/v1/sales/opportunities/{opportunity}`
- `POST /api/v1/sales/opportunities/{opportunity}/activities`
- `POST /api/v1/sales/opportunities/{opportunity}/read`
- `GET /api/v1/lead-intelligence/settings`
- `PATCH /api/v1/lead-intelligence/settings`
- `GET /api/v1/lead-segments`
- `POST /api/v1/lead-segments`
- `GET /api/v1/lead-segments/{segment}`
- `PATCH /api/v1/lead-segments/{segment}`
- `POST /api/v1/lead-research-runs`
- `GET /api/v1/lead-research-runs/{run}`
- `GET /api/v1/lead-scan-ledger`
- `POST /api/v1/lead-intelligence/evaluate-contact`
- `POST /api/v1/lead-intelligence/promote-candidate`
- `GET /api/v1/taxonomy/categories`
- `GET /api/v1/taxonomy/categories/{category}`
- `POST /api/v1/taxonomy/categories`
- `PUT /api/v1/taxonomy/categories/{category}`
- `PATCH /api/v1/taxonomy/categories/{category}`
- `DELETE /api/v1/taxonomy/categories/{category}`
- `GET /api/v1/taxonomy/tags`
- `GET /api/v1/taxonomy/tags/{tag}`
- `POST /api/v1/taxonomy/tags`
- `PUT /api/v1/taxonomy/tags/{tag}`
- `PATCH /api/v1/taxonomy/tags/{tag}`
- `DELETE /api/v1/taxonomy/tags/{tag}`
- `GET /api/v1/commercial/services`
- `GET /api/v1/commercial/services/{service}`
- `POST /api/v1/commercial/services`
- `PUT /api/v1/commercial/services/{service}`
- `PATCH /api/v1/commercial/services/{service}`
- `GET /api/v1/commercial/contracts`
- `GET /api/v1/commercial/contracts/{contract}`
- `POST /api/v1/commercial/contracts`
- `PUT /api/v1/commercial/contracts/{contract}`
- `PATCH /api/v1/commercial/contracts/{contract}`
- `GET /api/v1/commercial/slas`
- `GET /api/v1/commercial/slas/{sla}`
- `POST /api/v1/commercial/slas`
- `PUT /api/v1/commercial/slas/{sla}`
- `PATCH /api/v1/commercial/slas/{sla}`
- `GET /api/v1/commercial/time-rates`
- `GET /api/v1/commercial/time-rates/{rate}`
- `POST /api/v1/commercial/time-rates`
- `PUT /api/v1/commercial/time-rates/{rate}`
- `PATCH /api/v1/commercial/time-rates/{rate}`
- `GET /api/v1/economy/orders`
- `POST /api/v1/economy/orders/generate`
- `GET /api/v1/economy/orders/{order}`
- `POST /api/v1/economy/orders/{order}/ready`
- `POST /api/v1/economy/orders/{order}/draft`
- `DELETE /api/v1/economy/orders/{order}`
- `DELETE /api/v1/economy/orders/{order}/lines/{line}`
- `GET /api/v1/data-exchange/profiles`
- `POST /api/v1/data-exchange/profiles/{profile}/runs`
- `GET /api/v1/data-exchange/runs/{run}`
- `GET /api/v1/data-exchange/files/{file}/download`
- `POST /api/v1/data-exchange/imports/dry-run`
- `POST /api/v1/data-exchange/imports/{preview}/commit`
- `GET /api/v1/reports`
- `GET /api/v1/reports/{reportKey}`
- `GET /api/v1/users`
- `GET /api/v1/users/roles`
- `POST /api/v1/users`
- `GET /api/v1/users/{user}`
- `PUT /api/v1/users/{user}`
- `PATCH /api/v1/users/{user}`
- `POST /api/v1/users/{user}/status`
- `POST /api/v1/users/{user}/roles`
- `POST /api/v1/users/{user}/invite`

## Contact Write API

`GET /api/v1/contacts` supports lookup filters before an integration creates or updates a Contact:

- `q`: broad search across name, organization, email, and phone.
- `email`: exact email address lookup.
- `phone`: normalized phone lookup.
- `status`: status filter.

Example:

```text
GET /api/v1/contacts?email=ola@example.test
```

`POST /api/v1/contacts` is an upsert endpoint. It creates a Contact when no matching Contact exists,
and updates the existing Contact when the submitted email address or normalized phone number already
belongs to a Contact.

The upsert endpoint requires both `contacts.create` and `contacts.update` because the same request
may either create or update data.

Common payload fields:

- `display_name`
- `organization_name`
- `job_title`
- `email`
- `phone`
- `sms_allowed`
- `preferred_language`
- `do_not_call`
- `do_not_email`
- `marketing_consent`
- `client_id`
- `site_id`
- `relation_type`

When `client_id` is supplied without `site_id`, Nexum links the Contact to the Client's default Site
when one exists. When a Site is linked, Nexum also updates the legacy `client_users` bridge so older
ticket and client workflows continue to work while the Contact Domain transition is in progress.

`PUT` and `PATCH /api/v1/contacts/{contact}` update a known Contact by ID and require
`contacts.update`.

## Marketing API

The Marketing API uses existing Client and Contact APIs for identity records. Create or update the
Client first, then create or upsert the Contact with `contacts.create` and `contacts.update`.
Marketing list membership can then be managed with `marketing.lists.manage`.

Important Contact fields for Marketing:

- `do_not_email`: excludes the Contact from Marketing list resolution.
- `marketing_consent`: required for list resolution when Marketing settings use explicit opt-in.

Transactional SMS uses Contact phone `sms_allowed` and `do_not_call`; Marketing consent does not
grant transactional SMS permission.

Mailing list endpoints use these common fields:

- `name`
- `description`
- `audience_type`: `all_business_contacts` or `manual_contacts`
- `consent_category_id`
- `contact_tag_ids`
- `client_tag_ids`
- `manual_contact_ids`

Campaign endpoints use these common fields:

- `marketing_list_id`
- `email_account_id`
- `name`
- `description`
- `schedule_frequency`: `daily`, `weekly`, `monthly`, or `custom`
- `first_send_date`
- `send_time`
- `send_weekday`
- `month_day`
- `batch_size`
- `send_interval_minutes`
- `sequence_interval_value`
- `sequence_interval_unit`
- `new_recipient_policy`
- `track_opens`
- `track_clicks`

Campaign emails use an active Email template with `scope=marketing`, then store their own subject,
HTML body, plaintext body, and sequence settings as a campaign snapshot. The API does not send a
campaign until `POST /api/v1/marketing/campaigns/{campaign}/approve` has created recipient queue
rows and due-send processing has been queued or run.

## Contact Ownership Repair API

Contact ownership repair endpoints are intended for trusted cleanup tools and production support.
They keep canonical `contact_relations` and legacy `client_users` aligned while the Contact Domain
transition is in progress.

`GET /api/v1/clients/{client}/contacts` requires `contacts.read`. The `{client}` parameter accepts
either internal Client ID or `client_number`. The response includes canonical Contacts, their
relations, and legacy `client_users` rows for the Client's Sites.

Mutating repair endpoints require `contacts.ownership_manage`:

- `POST /api/v1/contacts/{contact}/move`
- `POST /api/v1/clients/{client}/contacts/bulk-fix`
- `DELETE /api/v1/clients/{client}/contacts/{contact}`

`POST /api/v1/contacts/{contact}/move` accepts `target_client_id` or `target_client_number`,
optional `target_site_id`, `dry_run`, and `reason`. Actual moves are transactional and update both
Contact relations and the legacy Client User bridge.

`POST /api/v1/clients/{client}/contacts/bulk-fix` accepts `contact_ids`, optional `target_site_id`,
`dry_run`, and `reason`. Use `dry_run: true` first to get per-Contact statuses before mutating data.

`POST /api/v1/clients/{client}/contacts/legacy-orphans/cleanup` accepts explicit `client_user_ids`,
`dry_run`, and `reason`. It deletes only legacy rows for the selected Client that have no
`contact_id`; linked rows are skipped and should be handled through Contact detach.

`DELETE /api/v1/clients/{client}/contacts/{contact}` detaches a Contact from one Client. It deletes
linked legacy `client_users` rows for that Client so the legacy contact does not remain visible on
the wrong customer. `delete_if_orphan` can soft-delete the Contact only when it has no remaining
ownership or User account link.

Repair calls are audited in the activity log with the actor, API token ID when available, reason,
dry-run flag, before state, result, and after state.

## Ticket API

Ticket API routes use the same ticket engine as the Tech UI.

`POST /api/v1/tickets` creates tickets through `StoreTicket`, so ticket defaults, ticket rules, SLA
resolution, assignment, initial events, and description messages are applied.

`PUT` and `PATCH /api/v1/tickets/{ticket}` update fields through `UpdateTicketFields` and change
status through `ChangeTicketStatus`. Workflow and action guards are still enforced.

Common create fields:

- `subject`
- `description`
- `client_id`
- `site_id`
- `contact_id`
- `asset_id`
- `owner_id`
- `queue_id`
- `priority_id`
- `ticket_type_id`
- `impact`
- `urgency`

The `{ticket}` route parameter is the public ticket key, for example `TD-2026-000001`.

### Customer Portal Publication And Technician Replies

`POST /api/v1/tickets/{ticket}/portal-visibility` accepts `portal_visible: true`. It requires the
`tickets.portal.publish` token ability, an active internal user with `ticket.update`, a client-scoped
Ticket, and the Ticket's active client Contact with an email address. Publication is one-way and
idempotent: the first call returns `published_now: true`; a repeat returns `false` without another
event or portal notification.

`POST /api/v1/tickets/{ticket}/messages` creates only public technician `customer_reply` messages.
It requires `tickets.reply_customer`, `ticket.reply_customer`, a Published Ticket, and a valid
same-client reply Contact. Required fields are `body` and an integration-chosen `idempotency_key`
of at most 100 characters. Optional fields are `reply_intent`, `reply_contact_id`, and `cc`.

Supported reply intents are `customer_update`, `request_customer_input`, and `send_solution`.
`send_solution` records the established solution facts; the coordinator then reads
`workflow-decisions`, performs an allowed Resolved transition, and uses the existing close endpoint.

The first successful reply returns HTTP 201 with `created: true`. Retrying the same Ticket, actor,
key, and normalized payload returns the original message with HTTP 200 and `created: false`.
Reusing the key for another payload or actor, or after soft deletion, returns HTTP 409. A replay
never repeats customer email, portal notification, relationship synchronization, Ticket events, or
workflow triggers.

The external-message endpoint remains an inbound synchronization boundary. It stores external
authorship, does not queue the normal technician customer email, and cannot inject reply intent or
solution metadata.

## Task API

Task API routes expose the core task workflow for trusted automation and future AI agents.

`POST /api/v1/tasks` creates tasks through `StoreTask`, so task defaults, owner context, checklist
items, and creation activity are handled consistently with the Tech UI.

Supported owner context:

- `owner_type: client` with `owner_id`.
- `owner_type: ticket` with `owner_id`.

Common create fields:

- `title`
- `description`
- `owner_type`
- `owner_id`
- `client_id`
- `site_id`
- `assigned_to`
- `status_id`
- `queue_id`
- `priority_id`
- `due_at`
- `estimated_minutes`

`PUT` and `PATCH /api/v1/tasks/{task}` update task fields and create an API update activity.

## Knowledge API

Knowledge API routes expose shelves, books, chapters, and article read/write operations for trusted
automation and future AI agents.

Use hierarchy endpoints to create the BookStack-compatible structure before creating pages:

- `POST /api/v1/knowledge/shelves`
- `POST /api/v1/knowledge/books`
- `POST /api/v1/knowledge/chapters`

`POST /api/v1/knowledge/articles` creates articles through the Knowledge `StoreArticle` action. This
applies article defaults, assigns owner and creator, generates the slug, and renders Markdown to
HTML.

Common create fields:

- `title`
- `body_markdown`
- `visibility`
- `status`
- `knowledge_shelf_id`
- `knowledge_book_id`
- `knowledge_chapter_id`
- `priority`
- `next_review_at`
- `sync_to_book_stack`

`PUT` and `PATCH /api/v1/knowledge/articles/{article}` update articles through the Knowledge
`UpdateArticle` action and re-render Markdown to HTML.

Documentation API routes expose the template-based records shown in `/tech/documentations`:

- `GET /api/v1/knowledge/documentations`
- `POST /api/v1/knowledge/documentations`
- `GET /api/v1/knowledge/documentations/{documentation}`
- `PATCH /api/v1/knowledge/documentations/{documentation}`
- `DELETE /api/v1/knowledge/documentations/{documentation}`
- `GET /api/v1/knowledge/documentation-categories`
- `POST /api/v1/knowledge/documentation-categories`
- `GET /api/v1/knowledge/documentation-templates`
- `POST /api/v1/knowledge/documentation-templates`

Documentation records accept structured request `data` plus optional free-form `content` or `body`.
Responses return structured values as `fields` plus `content`/`body`. When a free-form body is
supplied and the selected template does not already include a `content` field, the API stores a
content textarea in the document snapshot so the existing Tech UI renders it.

When `sync_to_book_stack` is true, the BookStack integration must be active and two-way sync must be
enabled. Nexum marks the record and any needed local parent hierarchy as `pending_push` and queues
the BookStack push worker. BookStack-owned records reject local API edits unless two-way sync is
enabled.

## BookStack Sync API

BookStack sync API routes expose the same pull and push actions used by the Admin UI.

Routes:

- `GET /api/v1/integrations/book-stack/status`
- `POST /api/v1/integrations/book-stack/test`
- `POST /api/v1/integrations/book-stack/pull`
- `POST /api/v1/integrations/book-stack/push`

`status` requires `integration.bookstack.read`. Mutating operations require
`integration.bookstack.run`.

Responses return sanitized status and summary payloads. Secrets are never returned. Push summaries
include shelves, books, chapters, pages, skipped, failed, total, and errors.

## Storage API

Storage API routes expose warehouse, box, item, and stock adjustment operations for trusted
automation, N8N workflows, and future barcode flows.

`GET /api/v1/storage/items` supports:

- `q`: broad search across name, SKU, EAN, and manufacturer part number.
- `sku`: exact SKU lookup.
- `ean_number`: exact EAN lookup.
- `warehouse_id`: restrict to one warehouse.
- `box_id`: restrict to one box.
- `status`: active or inactive.

`POST /api/v1/storage/items` creates items through the Storage `StoreItem` action. Initial quantity
creates a stock movement so the first stock count remains auditable.

`POST /api/v1/storage/items/{item}/adjust` adjusts stock through the Storage adjustment action. The
API rejects adjustments that would make on-hand quantity negative. The generic adjustment endpoint
is also blocked when the item has serial, batch, or expiry tracking enabled, or when it has a
positive `StockUnit` ledger balance. Identified stock must instead use a unit-aware receipt, picking,
or reversal workflow so its quantities and identifiers remain consistent.

Storage also exposes:

- `GET` and `POST /api/v1/storage/warehouses`
- `PUT` and `PATCH /api/v1/storage/warehouses/{warehouse}`
- `GET` and `POST /api/v1/storage/boxes`
- `PUT` and `PATCH /api/v1/storage/boxes/{box}`

Purchase workflows use separate least-privilege abilities from general inventory:

- `storage.purchase.read`: list and inspect orders, lines, shipments, tracking, and receipt history.
- `storage.purchase.manage`: create/update orders and manage line cancellations, lifecycle,
  shipments, and tracking identifiers.
- `storage.purchase.receive`: post normal goods receipts.
- `storage.purchase.receive_overage`: authorize explained over-deliveries when combined with
  `storage.purchase.receive`.
- `storage.purchase.reverse`: create guarded receipt reversals.

Purchase-order endpoints are:

- `GET` and `POST /api/v1/storage/purchase-orders`
- `GET` and `PUT /api/v1/storage/purchase-orders/{purchaseOrder}`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/lines/{purchaseOrderLine}/cancel`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/close`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/cancel`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/shipments`
- `PATCH /api/v1/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/status`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/trackings`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/receipts`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/receipts/{purchaseReceipt}/reverse`

`storage.purchase.read` does not grant Ticket visibility. Purchase-line `ticket_id`,
`ticket_planned_line_id`, nested Ticket identity/subject, and Ticket-specific metadata are omitted
unless the same token also has `tickets.read`. Neutral Storage-owned origin metadata may remain.

Every mutation uses the same transactional Storage action as the browser workflow. The receipt and
reversal payloads require an `idempotency_token`; retrying the same token and payload returns the
existing ledger entry without posting stock again. Reusing a token for different data is rejected.
Create and update actions also enforce active supplier, warehouse, and orderable-item master data at
the action boundary. Partially received orders cannot be edited through a direct API request, and
client metadata cannot replace system-owned lifecycle or shipment-status history.

A receipt line includes `purchase_order_line_id`, `qty_accepted`, `qty_rejected`, and optional
discrepancy, overage, serial, batch, and expiry data. Accepted quantity updates stock; rejected
quantity stays outside available stock. Rejected quantity does not satisfy the purchase-order line,
so it remains part of that line's outstanding replacement quantity. When the receipt is linked to an
allocated shipment, the shipment allocation separately exposes received, rejected, cancelled, and
outstanding quantities so every parcel can finish without falsely increasing order-level received
stock. An over-delivery requires both receive abilities and an
`over_receipt_reason` on the affected line.

Shipment tracking responses return the carrier-snapshot-derived safe URL. Raw direct URLs are not
returned, and unsafe or non-allowlisted URLs remain non-clickable. Mutation links in API resources
are lifecycle-aware and disappear when the related action is no longer valid.

## Sales API

Sales API routes expose opportunity read/write operations and activity registration for trusted
automation and future AI agents.

`GET /api/v1/sales/opportunities` supports:

- `q`: broad search across opportunity title, opportunity key, and client name.
- `status`: restrict to one opportunity status.
- `client_id`: restrict to one client.
- `owner_id`: restrict to one assigned owner.

`POST /api/v1/sales/opportunities` creates an opportunity through the Sales `StoreSalesOpportunity`
action. This keeps opportunity key generation, defaults, weighted value, initial activity, and
follow-up calendar behavior aligned with the Tech UI.

Common create fields:

- `client_id`
- `primary_contact_id`
- `owner_id`
- `title`
- `type`
- `status`
- `summary`
- `needs`
- `estimated_value_ex_vat`
- `probability_percent`
- `expected_close_date`
- `next_follow_up_at`
- `next_follow_up_type`
- `next_follow_up_note`

`PUT` and `PATCH /api/v1/sales/opportunities/{opportunity}` update an existing opportunity. The route
parameter is the public opportunity key.

`POST /api/v1/sales/opportunities/{opportunity}/activities` adds `journal`, `internal_note`, or
`email_in` activity. The `email_in` type marks the opportunity as unread.

Outbound sales email and quote sending are not exposed by this API slice. Those workflows remain in
the Sales UI and queued mail jobs until a dedicated email composition API is designed.

## Taxonomy API

Taxonomy API routes expose shared categories and tags for trusted automation and classification
workflows.

`GET /api/v1/taxonomy/categories` supports:

- `q`: search by category name, slug, and description.
- `type`: restrict to one category type.
- `parent_id`: restrict to one parent category, or pass an empty value for root categories.
- `is_active`: filter active or inactive categories.

`POST /api/v1/taxonomy/categories` creates a category and generates the slug from the name. `PUT` and
`PATCH /api/v1/taxonomy/categories/{category}` update an existing category. Category deletion is
blocked when the category has child categories, linked services, or linked documentation templates.

`GET /api/v1/taxonomy/tags` supports:

- `q`: search by tag name, slug, and description.
- `active`: filter active or inactive tags.

`POST /api/v1/taxonomy/tags` creates a tag and generates the slug from the name. `PUT` and
`PATCH /api/v1/taxonomy/tags/{tag}` update an existing tag. Tags are soft-deleted.

Record-specific tag attachment is not part of this Taxonomy API slice. Each domain API should own tag
attachment for its own records because each domain has its own validation and workflow rules.

## Commercial API

Commercial API routes expose the first stable commercial data surfaces:

- Services.
- Contracts.
- SLA policies.
- Time rates.

`GET /api/v1/commercial/services` supports `q`, `status`, `billing_cycle`,
`availability_audience`, and `orderable`.

`POST /api/v1/commercial/services` creates a service catalogue record. `PUT` and `PATCH` update the
service record.

`GET /api/v1/commercial/contracts` supports `q`, `client_id`, and `status`.

`POST /api/v1/commercial/contracts` creates a draft contract only. Public contract sending, approval,
and line-item editing are not exposed by this API slice.

SLA create/update keeps the same single-default behavior as the Tech UI.

Time rate create/update generates `slug` from `code`.

## Economy API

Economy API routes expose internal order preparation for trusted automation and AI agents.

The API does not create accounting invoices, send invoices, or export to external accounting
systems. It manages the same draft economy orders that the technician Economy UI uses.

`GET /api/v1/economy/orders` supports `q`, `status`, `client_id`, `period_start`, `period_end`, and
`per_page`.

`POST /api/v1/economy/orders/generate` runs the shared Economy order generation action for an
optional date period and returns the generation summary.

Draft orders can be marked ready. Ready orders can be moved back to draft.

Empty draft or ready orders can be deleted. Draft order lines can be deleted, and deleting generated
lines unlocks their ticket time or ticket cost source records for recalculation.

## Data Exchange API

Data Exchange API routes expose the implemented profile runtime for trusted automation.

`GET /api/v1/data-exchange/profiles` returns configured profiles with direction, format, status, run
count, and file count.

`POST /api/v1/data-exchange/profiles/{profile}/runs` triggers an export profile and returns the run
and generated file ID. Import profiles must use dry-run and commit endpoints.

`GET /api/v1/data-exchange/runs/{run}` returns run status, summary, error message, and generated file
metadata.

`GET /api/v1/data-exchange/files/{file}/download` downloads a generated file from protected storage.

`POST /api/v1/data-exchange/imports/dry-run` accepts `profile_id` and uploaded `file` for CSV, JSON,
or XLSX preview. The response includes valid/invalid row counts and row errors.

`POST /api/v1/data-exchange/imports/{preview}/commit` commits a valid preview through the
module-approved import target. Previews with invalid rows are rejected.

## Report API

Report API routes expose the shared report registry.

`GET /api/v1/reports` supports `domain` and `q`. It returns only reports visible to the authenticated
API user.

`GET /api/v1/reports/{reportKey}` returns metadata for one visible report.

The Report API does not calculate report results yet. Report-specific result APIs should be added
through the owning domain or through a future shared runnable report contract.

## User Management API

User Management API routes expose beta-ready user lifecycle operations:

- user discovery
- role discovery
- user creation
- canonical profile updates
- status changes
- role assignment
- pending invite resend

User deletion is not exposed. Account lifecycle uses `PENDING_INVITE`, `ACTIVE`, and `DISABLED`.

The API never returns password hashes, remember tokens, invite token values, two-factor secrets, or
two-factor recovery codes.

## Client And Site Custom Fields

The Client and Client Site APIs support platform Custom Fields.

Client create and update requests may include:

```json
{
  "custom_fields": {
    "msp_manager_id": "12345"
  }
}
```

Searchable custom fields can be used for lookup:

```text
GET /api/v1/clients?custom_field[msp_manager_id]=12345
```

Client Site create and update requests may include site-specific custom fields:

```json
{
  "custom_fields": {
    "msp_manager_site_id": "SITE-12345"
  }
}
```

Searchable Client Site custom fields can be used for direct lookup:

```text
GET /api/v1/client-sites?custom_field[msp_manager_site_id]=SITE-12345
GET /api/v1/clients/{client}/sites?custom_field[msp_manager_site_id]=SITE-12345
```

Custom fields are only accepted when the field is active, applies to the target model, and is
editable through API. Unique fields reject duplicate values for the same model type. Integration
values must be sent in the JSON `custom_fields` object, not as HTTP headers.

## Custom Field Definition API

Custom field definitions are available through a read-only discovery API:

```text
GET /api/v1/custom-fields
GET /api/v1/custom-fields/{id}
```

Required ability:

```text
custom-fields.read
```

Useful filters:

- `model`: model alias, for example `client`
- `q`: search key, label, help text, or model type
- `active`: boolean
- `editable_via_api`: boolean
- `searchable`: boolean

This API returns definitions, not values. Values are still read and written through the owning
domain API, for example the Client API `custom_fields` payload.

## Calendar API

Calendar API routes expose visible calendars and calendar events for trusted automation and future AI
agents.

`GET /api/v1/calendars` returns calendars visible to the authenticated user.

`GET /api/v1/calendar/events` uses the Calendar overlay query, so privacy masking and recurrence
expansion match the Tech UI. Supported filters:

- `from`: range start.
- `to`: range end.
- `calendar_id`: optional visible calendar filter.
- `timezone`: parsing timezone, default `Europe/Oslo`.

`POST /api/v1/calendar/events` creates events through `StoreCalendarEvent`. When `calendar_id` is
omitted, Nexum uses the authenticated user's personal work calendar.

`PUT` and `PATCH /api/v1/calendar/events/{event}` update events through `UpdateCalendarEvent`.

`DELETE /api/v1/calendar/events/{event}` soft-deletes a local event when the user can manage the
calendar.

## Risk API

Risk API routes expose risk assessments, risk items, and item update history for trusted automation
and future reporting/AI workflows.

`POST /api/v1/risk/assessments` creates assessments through `StoreRiskAssessment`.

`POST /api/v1/risk/assessments/{assessment}/items` creates a risk item through `StoreRiskItem`,
including the initial history row.

`PUT` and `PATCH /api/v1/risk/items/{item}` update descriptive risk item fields. When item history
exists, likelihood, impact, and status remain locked just like in the Tech UI.

`POST /api/v1/risk/items/{item}/updates` creates a history update and synchronizes the current item
snapshot for likelihood, impact, status, and next review date.

## Email Inbox API

Email Inbox API routes expose unrouted email messages for trusted automation and future AI-assisted
triage.

`GET /api/v1/email/inbox/messages` returns messages where `ticket_id` is null. Supported filters:

- `q`: search subject, from name, from email, and plain text body.
- `state`: filter message state.
- `account_id`: filter by email account.
- `from_email`: exact sender filter.

`GET /api/v1/email/inbox/messages/{message}` returns one unrouted message with attachments and tags.
The API does not expose raw storage paths or email account secrets.

`POST /api/v1/email/inbox/messages/{message}/spam` uses the Email `MarkEmailAsSpam` action. It tags
the message, archives it, and creates or updates an inbound spam rule.

`POST /api/v1/email/inbox/poll` queues `FetchImapAccount` jobs for active accounts. It does not run
IMAP polling inside the HTTP request.

## Notification API

Notification API routes expose the authenticated user's database notifications.

`GET /api/v1/notifications` supports:

- `unread`: when true, only unread notifications are returned.
- `per_page`: page size.

`POST /api/v1/notifications/{notification}/read` marks one owned notification as read.

`POST /api/v1/notifications/read-all` marks all unread notifications for the authenticated user as
read.

Users cannot read or update another user's notifications through the API.

The API foundation is intentionally incremental. Each domain must own its API controllers, resources,
route registration, validation, and tests.
