Documentation stores structured operational documents for internal work, client work, and
site-specific client work.

The Tech UI is available under `/tech/documentations`. API routes live under `/api/v1/knowledge`
because Documentation records are part of the broader Knowledge API surface.

## Scope And Work Context

Documentation keeps its existing local `scope_type` values:

- `internal`
- `client`
- `site`

Work Context is stored alongside that scope. Internal documentation resolves to the default internal
Work Context and has no Client or Site. Client and site documentation resolve to the selected
Client's Work Context while still keeping the existing `client_id` and `site_id` fields for
compatibility.

When a Site is selected, Nexum derives and validates the owning Client instead of accepting a
conflicting Client/Site combination.

## API

Documentation API routes:

- `GET /api/v1/knowledge/documentations`
- `GET /api/v1/knowledge/documentations/{documentation}`
- `POST /api/v1/knowledge/documentations`
- `PATCH /api/v1/knowledge/documentations/{documentation}`
- `DELETE /api/v1/knowledge/documentations/{documentation}`

The list endpoint supports these context filters:

- `client_id`
- `site_id`
- `scope_type`
- `work_context_id`
- `context_type` with `internal` or `client`

Responses include `work_context_id` and the loaded `work_context` object when available.

## Safety Notes

Documentation visibility and structure remain owned by the Documentation and Knowledge modules.
Work Context only describes whether a document belongs to the owning organization or to a Client. It
does not make internal documentation customer-facing and does not change BookStack ownership rules.

Relationship sync can exchange Documentation records with another Nexum
installation only when the record is non-internal and the selected relationship
has documentation sync enabled. Incoming updates are linked through
`nexum_sync_links`; if the local copy has changed since the last remote checksum,
the relationship link is marked as a conflict for review instead of overwriting
local documentation.

## Customer Portal

Documentation records are hidden from the Customer Portal by default. Technicians can publish or hide
client-scoped Documentation from the Documentation show page.

Portal document visibility is explicit:

- Internal documentation is never shown in the portal.
- Client-scoped documentation is visible only to portal memberships for that Client.
- Site-scoped documentation is visible only to matching Site memberships, while client-wide portal
  memberships can also see it for the Client.
- Hidden documentation, other-client documentation, and unrelated Site documentation return 404 from
  portal routes.

Publishing or hiding a document writes a CustomerPortal audit event.
