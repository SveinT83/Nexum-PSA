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

## Technician UI

The Client show page uses the gear action in the Summary card as the Client edit entry point.
This edit surface updates core Client fields, Client status, the N-able RMM mapping, and editable
Client custom field values.

The Summary card shows Client number, name, organization number, format, billing email, status, and
the N-able RMM mapping when that integration is active. Short values use three columns on wide
screens, two columns on medium screens, and one column on small screens. Notes use the full card
width below this metadata.

Technicians with `client.update` can edit Notes directly in the Summary card. The textarea waits
briefly after typing and then saves only the Notes field. `Saving...` means the current text has not
yet been confirmed by the server; `Saved` appears only after the server has persisted it. If saving
fails, the text stays in the textarea and an error appears so the technician can try again. Users
without `client.update` see the same Notes content read-only. The gear action and full Client edit
form remain available for the other Client fields and can also update Notes.

The Client show page also has workspace tabs for related records. `Sites` shows the Client's
locations, while `Contacts` shows all Client contacts across all Sites so technicians can verify
whether a Client has contacts without opening each Site first. The Contacts tab links to the
existing Client contact detail and create flows.

The `Tickets` tab appears for technicians with `ticket.view`. It lists all non-deleted Tickets
linked directly to the selected Client, including both open and closed work, and links each row to
the existing Ticket detail page. The count badge and rows come from the same access-controlled
collection, so Client access alone never exposes Ticket data.

The related-record tabs are ordered Assets, Sites, Contacts, Tickets, Tasks, Time, Contracts,
Signals, and Custom Fields. The Custom Fields tab remains hidden when no visible Client fields are
configured, and Tickets remains hidden when the technician lacks Ticket access.

The `Contracts` tab shows active contract timebanks when the technician has
`commercial.timebank.view`. Each timebank line displays the current period, included time, used time,
remaining time, overuse, and a compact progress bar.

The `Time` tab is the operational time usage surface for the Client. It includes quick no-ticket
timebank registration when Commercial policy allows it, and lists time usage from quick Client
entries, Tickets, and Tasks that have not already been included on an Economy order line. Editable
rows can be corrected by the technician who owns the entry or by users with the relevant higher
permission. Quick Client and Ticket rows can also have their time rate changed before they are
ordered; Task time does not currently store a billing rate snapshot. Ticket-owned Task work is shown
as two clearly labelled rows when billing differs from actual time: `Task tracked` is the
technician's actual time, while `Task billing` is the linked customer-billable Ticket projection.
These coupled rows cannot be edited independently from Client Time because that would desynchronize
technician reporting and customer billing.

The `Licences` tab is the Client-level workspace for Cloud Factory subscriptions. It shows provider,
Vendor, Service, quantity, renewal, binding dates, contract link, billing state, and recent
operations. Users with `integration.cloudfactory_write` can issue supported licences or change
supported quantities, renewal, and Microsoft status. Nexum requires a won active contract with the
relevant Service line before a provider write. Cloud Factory Client Portal changes are synchronized
back into this tab automatically; contract-policy conflicts stay visible and blocked from billing
until resolved.

The Client index has a compact search row and an advanced filter panel behind the funnel icon.
Technicians can filter by active status, Client format, contract presence, won contracts, and RMM
link state when RMM is configured. Client index filters are remembered in the technician session for
two hours or until the technician uses `Clear`.

## Client Number Allocation

The New Client form displays the next five-digit Client number. Nexum treats the unchanged
suggestion as an automatic value and revalidates it when the form is saved. If another Client used
that number while the form was open, Nexum allocates the next available number and still creates
the Client, default Site, and primary Contact transactionally. Pure numeric legacy values remain
part of the sequence; alphanumeric external or imported identifiers do not advance it.

A number changed by the technician is manual. Duplicate manual numbers produce a field-level
validation error and do not create partial related records. The database unique constraint remains
the final integrity guard for UI, API, and Data Exchange creation.

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

## Customer Portal Memberships

Customer Portal access is granted to a Contact for an explicit Client or Site scope. Client-wide
membership covers the Client, while Site membership limits the portal context to that Site.

The foundation release only proves identity, invitation, account activation, membership switching,
and access boundaries. Tickets, contracts, documents, billing, and other Client data must not be
exposed in the portal until those domains add explicit portal slices with their own permissions and
tests.

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

Client Site lookup routes require `clients.read`:

- `GET /api/v1/client-sites`
- `GET /api/v1/clients/{client}/sites`

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

Clients and Client Sites support platform Custom Fields.

Admins configure field definitions from `Admin -> System -> Custom fields`.

Visible fields appear on the Client show page in the client workspace `Custom Fields` tab.
Technicians with edit permission can click a field row to update that client's value in a modal.
This does not edit the custom field definition itself.

Editable fields also appear on the Client settings page as part of the broader client settings form.

The Client API accepts `custom_fields` on create and update requests and exposes values in the
`custom_fields` response payload. Client Site create and update requests support the same
`custom_fields` payload for site-specific values.

Searchable fields can be used as API filters:

```text
GET /api/v1/clients?custom_field[msp_manager_id]=12345
```

Client Sites can also be looked up by custom field values:

```text
GET /api/v1/client-sites?custom_field[msp_manager_site_id]=SITE-12345
GET /api/v1/clients/{client}/sites?custom_field[msp_manager_site_id]=SITE-12345
```

This is intended for lightweight integration scenarios such as n8n syncing Clients and Sites from
MSP Manager. External IDs should be sent inside the JSON `custom_fields` object, not as HTTP
headers.
