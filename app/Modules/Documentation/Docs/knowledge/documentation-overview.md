Documentation stores structured operational documents for internal work, client work, and
site-specific client work.

The Tech UI is available under `/tech/documentations`. API routes live under `/api/v1/knowledge`
because Documentation records are part of the broader Knowledge API surface.

## Choosing A Template

Creating Documentation starts with a category. When that category has one active template, Nexum
selects it automatically and opens the document form without another click.

When several active templates belong to the category, Nexum asks you to choose the schema before
showing the document fields. The create form displays both the selected category and template so you
can confirm the structure before entering content.

The chosen template is copied into the Documentation record as a snapshot when you save. Later
template edits therefore do not rewrite existing documents. An inactive template or a template from
another category cannot be used through the create request.

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

## Full Documentation API coverage (Dev, 2026-10-27)

The verified Dev API now covers the supported Documentation browser workflows:
documents, category/template administration, canonical suppliers/vendors/manufacturers,
carriers/tracking rules, explicit portal visibility and membership-scoped portal reads.
Read **Documentation Overview -> Documentation API guide** for required scopes,
user permissions, request keys, read-back, examples and troubleshooting.

Main and production are unchanged; HR-2026-10-27-DOCUMENTATION-API was Reviewed
by Svein Tore on 2026-10-27. Promotion and deployment require separate authorization.
Changing a visible document's Client/Site or moving it to internal scope hides it
until explicitly published for its new audience. Multiple active templates require
an explicit selection through the API as in web. Issue #275's immutable revision
and approval/publication lifecycle remains separate and unimplemented.

## Documentation API guide

Verified on Dev on 2026-10-27. Main and production are unchanged. Human checklist
**HR-2026-10-27-DOCUMENTATION-API** was Reviewed by Svein Tore on 2026-10-27.
Main promotion and production release require separate authorization. Do not describe
these additions as production functionality.

## Prerequisites

Use an active internal user with the corresponding Documentation permissions and
an API key created in **Admin > System > Integrations > API**. A token ability never
grants a missing user permission. Use the target installation's **API documentation**
page (`/api/documentation`) for exact payloads and Swagger examples.

Portal callers need an active user, matching contact, active portal account and an
active membership for the intended Client/Site. A bearer key needs
`documentation.portal.read`; a logged-in portal session may use the same read API.

## Find The Required Context

1. Read `GET /api/v1/documentation/context` for scopes, options, defaults and catalog URLs.
2. Search `GET /api/v1/documentation/lookups/clients?q=...`.
3. Read `GET /api/v1/documentation/lookups/sites?client_id=...` for that Client.
4. Select a Documentation category and active template through the catalog URLs.
5. Pass explicit filters to list calls. API filters do not change browser session context.

Lists accept `page` and `per_page` (1 to 100; default 25), with deterministic ordering.
Unknown fields and malformed filters return 422. Document filters include `q`,
`category_id`, `category_slug`, `template_id`, `client_id`, `site_id`, `scope_type`,
`work_context_id`, `context_type`, and `exclude_internal`.
A site must belong to the selected client. A supplied scope must match the IDs.

## Maintain Suppliers, Vendors And Manufacturers

1. Search `GET /api/v1/documentation/suppliers?q=...` or
   `GET /api/v1/documentation/vendors?role=all&q=...` before creating a record.
2. Create with `POST /api/v1/documentation/suppliers` or
   `POST /api/v1/documentation/vendors`. Send `name`, the applicable role booleans,
   and `is_active: true` when it should be usable for new purchasing.
3. Read the exact returned ID using `GET /api/v1/documentation/vendors/{vendor}`.
4. Maintain it with `PATCH /api/v1/documentation/vendors/{vendor}`, using the
   current ETag in `If-Match`. Omitted fields retain their values.
5. Deactivate through `is_active: false`. There is no supported partner delete flow.

The vendor route defaults to vendor/manufacturer roles. The supplier route defaults
to supplier-only roles. Missing `is_active` defaults false, matching web submission.
One record may have several roles. Filter `role` by `all`, `vendors`, `suppliers`,
or `manufacturers`; optional `is_active` includes explicit inactive searches.

Supported fields are name, vendor code, organization number, URL, phone, email,
default lead time, note, terms and role/status booleans. Import provenance and
identity hashes are system-owned and unavailable as submitted or returned fields.
Storage supplier lookup uses these same IDs; no second supplier register is created.
Creating or editing master data does not place an order or change inventory.

Scopes: `documentation.vendors.read/create/update`. User permissions:
`documentation.view/create/update`, respectively.

## Maintain Carriers And Tracking Rules

1. Open **Knowledge > Documentations > Shipping Carriers** or use
   `GET /api/v1/documentation/shipping-carriers`.
2. Inspect the current profile with `GET /api/v1/documentation/shipping-carriers/{shippingCarrier}`.
3. Create using POST on the collection, or PATCH the detail URL. Carrier writes
   require `documentation.carriers.manage` and `documentation.carrier_manage`.
4. Use the context options for lifecycle, tracking method, verification state and
   link visibility. Send the official source URL and verify the configuration
   before marking it verified. `verified_at` is required when verified.
5. Use `POST /api/v1/documentation/shipping-carriers/{shippingCarrier}/tracking-preview`
   with `tracking_number` and optional `direct_url`. This only builds a safe browser
   link; it performs no provider request or write.
6. Read the saved profile again. Existing Storage shipment snapshots stay unchanged.

Reads/previews require `documentation.carriers.read` plus `documentation.view`.
Tracking URLs require HTTPS, no embedded credentials, normal HTTPS port and an
allowed hostname or subdomain. Templates contain exactly one
`{tracking_number}` placeholder. An unsafe direct URL is ignored; the resolver
may return a verified template, generic page, or null. Never treat preview as
provider validation, shipment booking, or delivery evidence.
Deactivate through lifecycle `inactive`; no carrier delete endpoint is supported.

## Documents, Categories And Templates

Legacy URLs/scopes remain supported:

| Workflow | API | Token ability | Current user permission |
| --- | --- | --- | --- |
| List/show documents | GET `knowledge/documentations[/{documentation}]` | `knowledge.read` | `documentation.view` |
| Create document | POST `knowledge/documentations` | `knowledge.create` | `documentation.create` |
| Edit document | PUT/PATCH `knowledge/documentations/{documentation}` | `knowledge.update` | `documentation.update` |
| Soft delete document | DELETE `knowledge/documentations/{documentation}` | `knowledge.update` | `documentation.delete` |
| Read categories/templates | GET `knowledge/documentation-categories` or `knowledge/documentation-templates`, collection/detail | `knowledge.read` | `documentation.view` |
| Create category | POST `knowledge/documentation-categories` | `knowledge.create` | `taxonomy.manage_categories` |
| Edit category | PUT/PATCH `knowledge/documentation-categories/{category}` | `knowledge.update` | `taxonomy.manage_categories` |
| Create template | POST `knowledge/documentation-templates` | `knowledge.create` | `documentation.manage_templates` |
| Edit template | PUT/PATCH `knowledge/documentation-templates/{documentationTemplate}` | `knowledge.update` | `documentation.manage_templates` |

All paths above start with `/api/v1/`. Include-inactive category/template lists
also require the corresponding administration permission. Categories and templates
belong to Documentation; other Taxonomy types are rejected. No delete is offered
where the current web workflow has no delete operation.

To create a document, send title, category ID or slug, optional client/site,
structured `data`, and optional `content` or `body`. When several active templates
exist, send `template_id`; a single active template is automatic. The captured
schema remains stable on later ordinary content edits. Responses return `fields`,
`content/body`, the captured template and Work Context. Category parent cycles,
wrong-category templates, inactive create templates and mismatched sites are rejected.

## Portal Visibility And Customer Access

1. Keep documents hidden while preparing them. Visibility is off by default.
2. Check the exact Client/Site and the content intended for that audience.
3. With `documentation.portal.manage` and `documentation.update`, send
   `POST /api/v1/knowledge/documentations/{documentation}/portal-visibility`
   with `portal_visible: true`.
4. Read the document back and inspect `portal_visible`, `portal_visible_at` and
   `portal_visible_by`. This action records the same audit and publication
   notification as the web button.
5. The customer can read `GET /api/v1/portal/documents` and
   `GET /api/v1/portal/documents/{documentation}`. Use an own active
   `membership_id` for explicit membership selection.
6. Hide using the visibility action with false. Normal visible-content updates
   notify the current audience. Moving to another Client/Site or internal scope
   hides the document until it is explicitly published for the new audience.

Client-wide documents are visible to active memberships for that Client.
Site documents require the matching Site or a client-wide membership. Hidden,
internal, other-client and other-site documents return 404. Invalid or another
user's membership selection returns 403, without falling back to another membership.
Portal results contain only captured fields rendered in the portal, category/site
labels and update metadata. They never expose unrendered data or staff metadata.
Publication or notification acceptance does not by itself prove inbox delivery.

## Safe Writes, Read-Back And Recovery

New register/visibility writes require an `Idempotency-Key` of 8 to 200 characters.
Use one stable UUID for one intended mutation. Legacy writes accept the header
optionally for compatibility. Tracking preview is read-only and needs no key.

1. Read the exact record first and retain its ETag.
2. Submit an edit with that ETag in `If-Match`.
3. Read the saved record again, using the returned ID and current GET response.
4. On transport uncertainty, retry the identical request with the same key.
   An identical retry returns the retained result without duplicate local side effects.
5. Use `GET /api/v1/documentation/operations/{operation}` with
   `documentation.operations.read` and the original operation scope/permission to
   inspect the command. Access is restricted to the original user and token.
6. On `state: unknown` (202), reconcile the committed record and notification
   handoff before retrying. Do not create a new key to repeat an uncertain operation.

409 means a key was reused for another request. 412 means the exact record changed;
reload and review before submitting a new mutation. 422 identifies invalid fields.
428 means a required key is missing. 503 means the additive API migration is missing.
403 requires checking both the current user and token, including revoked membership.
Encrypted command receipts depend on the application encryption key and must be
preserved during recovery. They are local operation evidence, not immutable document
history or proof of delivery to an external system.

## Planned Revisions And MCP Integrations

Issue #275 remains open for immutable Documentation revisions, draft/review/approval,
least-privilege publication, freshness and rollback. Those operations are not
implemented or advertised as current capabilities. Future publication must use the
Documentation-owned lifecycle and #276 downstream conflict/read-back safeguards.
Documentation and Knowledge remain distinct authoritative domains.

MCP/integration clients should consume this official typed API and Swagger using
scoped credentials. No direct database writes, borrowed browser session, broader
permissions, or separate register is needed. Publishing this handbook does not
deploy the API or verify retrieval through an employee's OpenWebUI assistant.

## Narrow Form Permissions

Read access follows the matching web surface. Document/vendor detail reads also
accept current documentation.update; carrier detail reads accept carrier_manage;
template list/detail reads accept manage_templates; category list/detail reads
accept taxonomy.manage_categories. The corresponding read token scope is still
required. General document and partner lists continue to require documentation.view.

Context is available to the corresponding view/create/edit/template/carrier/system
form owner. Client/site lookups require Documentation view, create or update.
The minimal vendor lookup in the carrier form requires Documentation view or
carrier_manage and returns only ID, name and vendor code. It exposes no partner notes,
terms, contact data or import provenance. These alternatives match existing edit/admin
forms; they do not grant general browsing rights.
