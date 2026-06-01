API Management is available from Admin -> Integrations -> API.

Nexum PSA uses Laravel Sanctum personal access tokens for API authentication.

API requests must send:

```text
Authorization: Bearer {token}
Accept: application/json
```

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
- `tickets.read`: list and view tickets.
- `tickets.create`: create tickets through the ticket engine.
- `tickets.update`: update ticket fields and status.
- `tasks.read`: list and view tasks.
- `tasks.create`: create tasks.
- `tasks.update`: update task fields and status.

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
- `GET /api/v1/tickets`
- `GET /api/v1/tickets/{ticket}`
- `POST /api/v1/tickets`
- `PUT /api/v1/tickets/{ticket}`
- `PATCH /api/v1/tickets/{ticket}`
- `GET /api/v1/tasks`
- `GET /api/v1/tasks/{task}`
- `POST /api/v1/tasks`
- `PUT /api/v1/tasks/{task}`
- `PATCH /api/v1/tasks/{task}`

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
- `preferred_language`
- `client_id`
- `site_id`
- `relation_type`

When `client_id` is supplied without `site_id`, Nexum links the Contact to the Client's default Site
when one exists. When a Site is linked, Nexum also updates the legacy `client_users` bridge so older
ticket and client workflows continue to work while the Contact Domain transition is in progress.

`PUT` and `PATCH /api/v1/contacts/{contact}` update a known Contact by ID and require
`contacts.update`.

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

The API foundation is intentionally incremental. Each domain must own its API controllers, resources,
route registration, validation, and tests.
