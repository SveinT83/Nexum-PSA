The Clients domain owns customer organizations and their sites.

Clients are central records used by Tickets, Contacts, Assets, Contracts, Tasks, Risk, Reporting,
Integrations, and future automation. A Client may have one or more Sites. Contacts should be created
through the Contact Domain and related to the Client/Site rather than being managed through the old
Client Users workflow.

## Workspace Behavior

Opening a Client sets `active_client_id` in the technician session. Opening a Site sets
`active_site_id`. Other domains, especially Contacts, use this context to scope lists and forms.

The Client index clears active client and site context so technicians can return to a global client
view.

## Sites

Sites are physical or operational locations for a Client. Each Client should have a default Site so
Contacts, Tickets, Assets, and future integrations have a safe fallback when a specific Site is not
selected.

When API or UI workflows mark a Site as default, other Sites for the same Client are cleared as
default.

## Contact Transition

The old `client_users` table remains a compatibility bridge while modules move to the Contact Domain.
New integrations should create Contacts through the Contact API and pass `client_id` and `site_id`
when a Client/Site relation should be created.

Do not build new long-term person workflows on `client_users`.

## API Usage

Client API routes are available under `/api/v1`.

Read routes require `clients.read`:

- `GET /api/v1/clients`
- `GET /api/v1/clients/{client}`
- `GET /api/v1/clients/{client}/sites`

`GET /api/v1/clients` supports:

- `q`: search by client name, organization number, client number, or billing email.
- `active`: filter by active status.
- `per_page`: page size.

Example:

```text
GET /api/v1/clients?q=ellrun
```

Create routes require `clients.create`:

- `POST /api/v1/clients`

Update routes require `clients.update`:

- `PUT /api/v1/clients/{client}`
- `PATCH /api/v1/clients/{client}`
- `POST /api/v1/clients/{client}/sites`
- `PUT /api/v1/client-sites/{site}`
- `PATCH /api/v1/client-sites/{site}`

`POST /api/v1/clients` creates the Client and a default Site. If no site name is supplied, the Site
is named `Default`.

Supported Client fields:

- `name`
- `client_number`
- `org_no`
- `client_format_id`
- `website`
- `sales_category_id`
- `lead_temperature`
- `billing_email`
- `notes`
- `active`

Default Site can be supplied as a nested `site` object or through flat `site_*` fields:

```json
{
  "name": "Example Client AS",
  "org_no": "999888777",
  "billing_email": "billing@example.test",
  "site": {
    "name": "Main Office",
    "city": "Trondheim",
    "country": "Norway"
  }
}
```

This API is intended for trusted automation such as n8n and future AI agents. Tokens must be scoped
only to the abilities the integration actually needs.

## Custom Fields

Clients support platform Custom Fields.

Admins configure field definitions from `Admin -> System -> Custom fields`.

Visible fields appear on the Client show page in the client workspace `Custom Fields` tab.
Technicians with edit permission can click a field row to update that client's value in a modal.
This does not edit the custom field definition itself.

Editable fields also appear on the Client settings page as part of the broader client settings form.

The Client API accepts `custom_fields` on create and update requests and exposes values in the
`custom_fields` response payload.

Searchable fields can be used as API filters:

```text
GET /api/v1/clients?custom_field[msp_manager_id]=12345
```

This is intended for lightweight integration scenarios such as n8n syncing Clients from MSP Manager.
