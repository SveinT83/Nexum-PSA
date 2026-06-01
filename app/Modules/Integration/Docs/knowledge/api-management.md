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

## Contact Write API

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

The API foundation is intentionally incremental. Each domain must own its API controllers, resources,
route registration, validation, and tests.
