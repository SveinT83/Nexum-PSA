The official Clients API supports the current customer workspace, supported
writes/actions, Client administration and customer licence ordering.

Availability: implemented and verified on Dev under HR-2026-10-25-CLIENTS-API.
Main promotion and production rollout are separate. Do not assume these new routes
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

Operators apply only the approved additive Client command migration on Dev.
The default-off provider API switch is an independent deliberate activation;
API documentation or passing tests never enables a provider or changes rights.
Employee OpenWebUI retrieval is a separate acceptance check.
