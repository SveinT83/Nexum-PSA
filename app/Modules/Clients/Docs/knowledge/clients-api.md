The official Clients API supports the current customer workspace, supported
writes/actions, Client administration and customer licence ordering.

Availability: verified on Dev and source merged to Main in PR #319 (2026-10-25).
HR-2026-10-25-CLIENTS-API is Reviewed, approved by Svein Tore. Production rollout
has not been performed. Do not assume these new routes
are available on a production installation until its deployed Swagger confirms them.
Provider API writes are independently disabled by default.

## Before using the API

1. Open **Admin -> Integrations -> API**.
2. Create a key with only the required Clients and owner-domain scopes.
3. Confirm the key's active user has the same domain permission required in the
   browser. A token scope alone never grants the user's missing permission.
4. Open the installation's Swagger UI and inspect the exact route, request,
   response, parent IDs and required command headers.
5. Use the current HTTPS installation address and normal certificate validation.

Send Authorization: Bearer {token} and Accept: application/json. Never put a key
in a URL, a customer note, documentation, or an AI prompt.
No existing token is widened when these operations become available.

## Discovery and customer read-back

Use GET /api/v1/clients/context for formats, filters, sorting, defaults, number
authority and current actor/token capabilities. A suggested number is unreserved.
GET never creates a provider integration or reserves a customer number.

GET /api/v1/clients supports q, active, format_id, contract_filter, rmm_filter,
sort, direction, page, per_page and permitted searchable custom_field[key] values.
Page size is at most 100. Follow every page; totals describe the eligible result.
Client filters are explicit API parameters and do not alter the technician session.

GET /api/v1/clients/{client} returns persisted Notes, website, format, sales
classification, status, default Sites, visible custom fields and revision.
Use GET /api/v1/clients/{client}/workspace for permitted owner-domain links.

Related workspace sections are available through
GET /api/v1/clients/{client}/related/{section}, where section is contacts, tickets,
tasks, contracts, signals or risk. Both Clients and the corresponding owner-domain
scope/permission are required. Private Tasks remain private.
Assets use GET /api/v1/clients/{client}/assets with Asset authority.
Task templates and operational timebanks retain their existing Task/Commercial APIs.

## Creating a Client

POST /api/v1/clients retains the basic name/default Site workflow.
Supply site fields either as a nested site object or the documented flat site_*
fields. Postal codes remain text. Optional primary_contact creates or reuses the
canonical Contact and links it to the Client/default Site, maintaining the stable
internal compatibility bridge. It never creates a second person register.

For a composite creation, send a stable Idempotency-Key of 8-200 characters.
Repeat the exact request/key after a lost response; another payload with the same
key is a conflict. Read back the returned Client and permitted Contact/Site records.
The primary contact email is never copied into Billing Email automatically.

When Tripletex customer synchronization is active, send tripletex_number_mode=true
and persist one tripletex_request_key UUID. Normally omit client_number because
Tripletex owns the number. Never retry an ambiguous provider creation blindly.
Explicit provider discovery uses:
- GET /api/v1/clients/context/tripletex/number
- GET /api/v1/clients/context/tripletex/customers
- GET /api/v1/clients/context/tripletex/customers/{customer}

These lookups require current Client create/view authority. Contact suggestions
also require Contact view and contacts.read. Paused/unavailable provider state is
reported explicitly and does not cause a silent local-number fallback.

## Editing, Notes and settings

PUT/PATCH /api/v1/clients/{client} retains compatible core-field updates.
Omitted fields are preserved. Unsupported input is rejected instead of silently
reporting success. Supply If-Match when protecting a legacy edit from stale data.

For Notes-only work, PATCH /api/v1/clients/{client}/notes with notes and the current
revision in If-Match. This shares the browser Notes autosave action and cannot
reset other settings. It requires clients.update and client.update.

GET/PATCH /api/v1/clients/{client}/settings is separate. It requires
client.manage_settings plus clients.settings.read/update. The PATCH command needs
Idempotency-Key and If-Match. It can update core settings and explicitly link or
unlink the active RMM mapping. Omitting rmm_external_id preserves the mapping.
RMM options are read through GET /api/v1/clients/context/rmm.

Admin Client Formats uses GET/POST /api/v1/client-settings/formats and
GET/PATCH /api/v1/client-settings/formats/{clientFormat}. It follows the current
permission-based Admin access, not a hard-coded role name. Update preserves omitted
values. There is no supported Format delete or Client archive/delete command here.

## Sites and custom fields

- GET /api/v1/client-sites and /api/v1/clients/{client}/sites list permitted Sites.
- GET /api/v1/client-sites/{site} returns exact persisted detail and revision.
- GET /api/v1/client-sites/{site}/context returns permitted field/edit context.
- POST /api/v1/clients/{client}/sites creates a Site.
- PUT/PATCH /api/v1/client-sites/{site} updates a Site.
- DELETE /api/v1/client-sites/{site} performs the existing supported Site deletion
  with clients.delete, client.delete, Idempotency-Key and If-Match.

Parent and default-Site writes are serialized. Deletion refuses Sites with linked
or historical records, provider bindings, or Site-scoped portal access. This
prevents deleting history or turning a Site-only membership into client-wide access.
Review/move linked records through their owner workflows first. A reference conflict
is not a successful delete.
Client and Site custom_fields use the existing CustomField definition policy.
A hidden, unknown or non-editable field is an explicit validation failure.
Do not change field definitions or API edit rights to work around a denied request.

## Source time corrections

GET /api/v1/clients/{client}/time-usage lists current unordered quick/Ticket/Task
usage. GET /api/v1/clients/{client}/time-usage/{source}/{entry} gives the exact source
and revision; source is quick, ticket or task.
Use PATCH on the same source URL with work_date, minutes, optional note and the
supported source-specific rate/invoice fields. Idempotency-Key and If-Match are
required. Read the returned source and its recalculated billing/timebank state.

The token needs clients.time.read/update. The actor needs Client view plus the
source read right, and must own the source or have its higher edit permission.
Already ordered time is blocked. Coupled Task actual time and Ticket billing
cannot be independently edited from Client Time. Task time has no rate snapshot.
Correction and Economy order generation share the same database lock.

## Licences and customer ordering

Staff reads use /api/v1/clients/{client}/licenses, /context, /operations and
/operations/{operation}. Staff issue is POST on the base URL; subscription quantity,
renewal and supported Microsoft status are separate PATCH actions.
Scopes are clients.licenses.read/write. Writes also require
integration.cloudfactory_write, an eligible active won contract, supported
provider/offer policy and saved provider write settings.

Customer portal endpoints start at /api/v1/portal/licenses. They use the existing
customer session and normal CSRF protection, not a staff bearer token. Only the
current active client-wide Customer admin membership may use these routes.
The portal supports issue, quantity and renewal, with name/confirm and the same
versioned legal acceptance used by the browser. Staff-only status is not exposed.

Read licence context before writing and pass its revision in If-Match together
with Idempotency-Key. Review current contract lines, quantity and terms.
A changed revision, foreign subscription, inactive membership or changed rights
fails closed. Replay cannot submit or record legal acceptance twice.

A submitted operation is not provider confirmation. Inspect the exact operation:
confirmed=false remains unverified external state. Unknown transport state requires
reconciliation; another command key cannot bypass an unresolved Client operation.
Do not mark licences delivered or billable from an accepted HTTP response alone.

## RMM provisioning and recovery

Optional create_in_rmm on Client/Site creation follows the committed local record.
Explicit POST /api/v1/clients/{client}/rmm or /api/v1/client-sites/{site}/rmm can
provision an existing record. The API provider switch and active RMM are required.
Local Client/Site success and RMM state are returned separately.

RMM creation records an intent before transport and verifies the exact returned
identity using a provider read. An uncertain response never triggers another
create automatically. Reconcile using the matching /rmm/reconcile route and the
explicit external_id when required. Reconciliation reads provider state and
checks the frozen expected identity; it does not create another RMM customer/Site.

GET /api/v1/client-operations/{command} reads only the current actor/token's own
command status. It exposes no credentials or unrestricted stored request/response.

## Troubleshooting

- 401: authenticate again or check expiry/revocation.
- 403: check both current user rights and the exact token/membership scope.
- 404: verify the record and its exact Client/Site parent; do not search other
  customers by changing IDs to work around access.
- 409: reload the current record/context, inspect unresolved operation state and
  saved capability settings. Do not replace the idempotency key to repeat an
  uncertain external action.
- 422: correct validation/unsupported fields. Omitted, null, false and zero have
  distinct meanings. Site/Contact/provider actions use their own contracts.
- 428: read the current revision and send If-Match.
- 503: the required dependency, migration or trusted provider lookup is unavailable.

On a separately authorized deployment, operators apply only the approved additive
Client command migration before declaring the target installation available.
The default-off provider API switch is an independent deliberate activation;
API documentation or passing tests never enables a provider or changes rights.
Employee OpenWebUI retrieval is a separate acceptance check.

## Register a customer that already exists in Tripletex (2026-11-18)

Customer registration with an existing Tripletex customer is available in production. Customer-number synchronization must be active. Staff need Client view and create permissions. API callers also need the corresponding Clients scopes and Contact authority when creating a Contact.

1. In **Clients > New Client**, enter the name, customer number or organization number in **Existing Tripletex customer**. Click **Search** or press Enter. Select the intended active customer and check its identity and customer number.
2. Review the proposed billing, Site address and primary contact details. Click **Create Client**. Nexum verifies the selected Tripletex identity and uses its customer number. After a confirmed duplicate preflight, the form keeps your entries and highlights the picker so you can select the customer and save.
3. For API registration, check `GET /api/v1/clients/context` for Tripletex number authority. Search `GET /api/v1/clients/context/tripletex/customers?q=...` by name, customer number or organization number. Pass the selected internal `id` as `tripletex_customer_id` to `POST /api/v1/clients` with `tripletex_number_mode=true`, a UUID `tripletex_request_key` and an `Idempotency-Key`. Omit `client_number`.
4. If the API returns HTTP 422 with `errors.tripletex_customer_id` and confirms no customer was created, select the correct match and submit the corrected request with a fresh request UUID and idempotency key. For a timeout or unknown outcome, keep both original keys and ask an integration administrator to reconcile the attempt.
5. Open the saved Client, or read `GET /api/v1/clients/{client}`, to verify its customer number and check its Site address. If the provider identity is already linked, check the linked Nexum Client before taking further action.

## Find an existing customer while registering (Dev only, 2026-11-18)

On Dev, open **Clients > New Client** and enter a complete billing or primary contact email, a phone number or an organization number. Review each possible match. **Use Nexum customer** opens the existing customer. **Use Tripletex customer** loads that customer's details for review. Verify the Site address before clicking **Create Client**. Shared contact details can identify several customers. Select the intended record. If every match belongs to someone else, explicitly confirm that you need a separate customer. Provider conflicts still require review.

The Dev API accepts a JSON body with `email`, `billing_email`, `phone` or `org_no` at `POST /api/v1/clients/context/registration-candidates`. With a human actor and Clients view/create permissions, the lookup requires `clients.read`. An ordinary registration with both `clients.create` and `clients.read` that matches can return HTTP 200 with `state=selection_required` and `candidates`. This outcome creates no customer. For `use_existing`, read `GET /api/v1/clients/{id}`. For `link_provider`, submit the chosen ID as `tripletex_customer_id`, using the provider name and organization number and a fresh command key for the changed input. The original Tripletex request UUID can be retained after this no-write selection response. Keep the normal reconciliation rules for unknown outcomes. Matching contact email or phone in Nexum or Tripletex requires Contact view authority and, through the API, the `contacts.read` scope. Billing email and organization number follow Client permissions. A failed provider lookup is reported separately.

## Resolve a registration match

1. POST email, phone or org_no to `/api/v1/clients/context/registration-candidates`. A matching authorized Nexum candidate includes its merge URL for actors who can also update Clients and manage Tripletex. A matching Tripletex candidate includes its connection ID for those actors. Multiple candidates require an explicit selection.
2. When creating through `POST /api/v1/clients`, send `Prefer: handling=strict` to receive HTTP 409 and the candidates when a selection is necessary. Existing clients can continue reading the HTTP 200 selection response. A provider duplicate preflight returns HTTP 422 with its usual field errors plus the matching provider IDs for authorized readers.
3. To pair two existing cards, POST to `/api/v1/clients/{client}/tripletex/merge` with `connection_id`, the selected `customer_id`, `expected_local`, `expected_remote` and `accept_change: true`. Supply a stable `Idempotency-Key` and `clients.read` and `clients.update` scopes. Active human Client view/update and integration.tripletex_manage rights also apply. Re-read the returned Client ID and number. Resolve conflicts before starting a new attempt.
4. If only Tripletex has the customer, use the existing selected-provider registration with the provider ID, number mode and one request UUID. A separate attempt that finds the number already linked can return the existing Nexum ID with `registration_applied: false`. Apply additional contact changes explicitly after verifying the existing customer.

## Invoice email on selected-provider imports

For `POST /api/v1/clients` with a selected `tripletex_customer_id`, supply `billing_email` only when the invoice recipient has been confirmed. An omitted, null or blank value leaves the local invoice email empty. `primary_contact.email` still creates the contact email. Read back `data.billing_email` on the created Client. Pairing two existing cards preserves their current fields.
