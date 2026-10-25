The Commercial API covers the supported catalogue, Contract, customer-response, settings and
operational timebank workflows through the same domain actions as web.

## Availability - 25 October 2026

This implementation is verified on Dev. Svein Tore accepted the completed delivery on
25 October 2026; HR-2026-10-25-COMMERCIAL-API is Reviewed. Main source promotion was
verified on 25 October 2026: [PR #313](https://github.com/SveinT83/Nexum-PSA/pull/313). Production deployment
and controlled delivery activation remain separate actions. The additive command/delivery migration is applied on Dev
(batch 23). Native locking/uniqueness and authenticated trusted HTTPS reads pass.
A new durable command returns 503 on an installation whose required migration is absent.

COMMERCIAL_API_DELIVERY_ENABLED and COMMERCIAL_DURABLE_DELIVERY_ENABLED default to false.
The second switch controls the browser's durable delivery rollout; existing browser delivery
remains until controlled worker verification and deliberate activation. Do not enable either switch to
bypass an error. Rollout owner: Svein Tore. Review: HR-2026-10-25-COMMERCIAL-API.

## Prerequisites and access

1. Open **Admin > Integrations > API**, inspect a named token and grant only the required abilities.
2. Verify its owner is active and retains the corresponding domain permission.
3. Use trusted HTTPS, the /api/v1/commercial base path and generated Swagger at /docs.
4. Never put a token in an article, ticket, command output or screenshot.
5. Customer acceptance uses an exact public capability or current Customer Portal session;
   staff tokens cannot choose a portal account/contact.

| Ability | Operations | Current user rights |
| --- | --- | --- |
| commercial.read | Catalogue/Contract/context/pricing/PDF | Corresponding owner permission |
| commercial.create/update/delete | Supported records, composition, lines and terms | Corresponding owner permission |
| commercial.approve | Manual approval/legacy attestation | commercial.contract_manage |
| commercial.send | Frozen preview/submit/delivery/recovery | commercial.contract_manage |
| commercial.operations.read | Own result, rechecking its original domain permission | Original operation permission |
| commercial.settings.read/update | Hubs/timebank policy | commercial.view / commercial.contract_manage |
| commercial.timebank.read | Client balances and consumption evidence | client.view |
| commercial.timebank.consume | Quick consumption | quick-consume plus policy/overuse rights |

Units retain economy.manage_settings and the actual administrative policy. Tokens never grant
additional user permissions. Active provider-owned Services, Costs and protected links remain
read-only. Provider legal documents and in-use legal terms retain their own restrictions.

## Catalogue and context

Collections: services, packages, costs, terms, slas, time-rates and units.
Each supports GET collection, GET collection/context, GET collection/context/{lookup},
GET collection/{id}, POST collection and PUT/PATCH collection/{id}.
Supported DELETE operations are Services, Packages, Costs, legal terms and Units; Contract
draft/line deletion is described below. No SLA or Time Rate deletion is supported.
The unused browser SLA delete declaration was removed.

Context supplies bounded lookups for the relevant clients/vendors/units/SLA/Services/Costs/
legal terms/rates/provider offers. Lists use q, page and per_page (1-100), with the supported
browser filters/sorts. Service audience and availability_audience are aliases; orderable
supports booleans and browser yes/no.

Service writes include metadata, add-on/audience/customer wording, prices/cadence/discounts,
timebank setup and costs/terms/time_rates composition. time_rates is keyed by existing Time
Rate ID, with enabled and optional amount_ex_vat. PATCH omission preserves composition.
Explicit empty arrays clear manual selections while protected provider links remain.

Package writes include selected services/terms and the five sales_price_* fields.
Detail separates direct and inherited terms. POST services/pricing-preview accepts cost_ids
and sales_price. POST packages/pricing-preview accepts service_ids and custom_prices.
Transient per-unit custom prices are calculated without claiming they are persisted.
These calls do not save records. Catalogue calculations retain web semantics; Contract
pricing uses the exact decimal calculator.

## Mutation and retry sequence

1. GET the owning resource and retain revision.
2. Supply If-Match and a unique Idempotency-Key of 8-200 characters on a new mutation.
   Creation needs a key but no existing record revision.
3. Send only documented typed fields. Omitted PATCH values are retained; null is accepted
   only where allowed. Actor/evidence/provider ownership is server-owned.
4. Read the returned data and operation_id. GET operations/{operation} reads your own durable
   result and rechecks current original-operation permission.
5. If the response is lost, repeat the identical path, body, key and If-Match. replayed=true
   returns the original result without repeating side effects.
6. Changed input under the same key returns 409. Reread/review before creating a new intent.

Legacy Service/SLA/Time Rate/Contract metadata create/update endpoints retain optional-key
compatibility. A supplied key uses the same durable contract. New operations require it.
Deletion does not remove receipts. Revoked rights also block replay and result reads.

## Contract workflow

Use contracts/context and its typed lookup links. GET contracts/{contract}/item-defaults
with service_id discovers supported wording, quantity, price, cadence, SLA and rate defaults.
GET/POST .../items and PUT/PATCH/DELETE .../items/{item} operate on lines.
POST .../items/{item}/sync-cadence explicitly adopts current Service cadence on a draft.
Foreign line/rate/offer IDs and authoritative non-NOK sale currency are rejected.

GET .../pricing reads saved economics. POST .../pricing-preview calculates supplied items
without saving. Inputs include quantity, unit_price, price_currency, billing_interval,
discount_value/type and setup_fee. Totals separate monthly/quarterly/yearly/one-time and
include integer minor units and decimal strings.

GET .../terms exposes saved/generated text and readiness. PUT/PATCH .../terms saves reviewed
manual wording; POST .../terms/refresh explicitly regenerates text and review fingerprints.
GET never creates legal versions, records viewed evidence or repairs history.

Metadata supports license/indexing/binding/renewal settings as well as client/SLA/dates/text.
The legacy created_by field is the web Technician assignment; context supplies the same eligible
technicians and default contract dates. It defaults to the current user if omitted. Approval and
attestation always record the actual authenticated actor, independently of that assignment.
Sent/accepted Contracts are immutable. Only an eligible draft may be deleted.

POST .../approve-manual records the current named actor and freezes customer evidence.
For legacy sent/accepted documents without complete evidence, GET
.../customer-document/attestation-preview?document_type=Tilbud|Avtale, verify the original
document, then POST .../customer-document/attest-legacy with explicit type/fingerprint,
confirmation and a 20-2000 character note. Never attest an assumed reconstruction.

GET .../pdf uses the shared immutable customer document/PDF renderer. Malformed or unknown
stored schemas and missing historical evidence fail closed with 409. A paginated list
isolates an unavailable historical row with honest null customer/pricing data. Customer
output omits internal costs, provider metadata and staff-only rates. No live historical rebuild.

## Sending a quote, agreement or resend

Available after controlled activation; code presence alone does not enable delivery.

1. Resolve Contract legal identity, readiness and current terms.
2. POST contracts/{contract}/communication-preview with kind=quote|contract|resend, optional
   valid cc_email, key and current If-Match.
3. Review exact recipient, CC, subject, HTML and customer document in review. This explicit
   POST stages reviewed legal versions for a draft but sends nothing.
4. Retain id, preview_hash and returned contract_revision. Preview expiry is 15 minutes;
   it belongs to the current actor and Contract.
5. POST .../send-quote, .../send-contract or .../resend with preview_id, preview_hash, a new
   key and the returned Contract revision. Changed recipient/content/provider/state needs
   a new preview. Resend retains the captured document and original public link.
6. GET .../deliveries/{delivery}. States distinguish preview/queued/processing/no_recipient/
   smtp_accepted/review_required/unknown. Missing recipient records no_recipient, not sent mail.
7. SMTP acceptance is provider submission evidence; inbox_delivery_confirmed remains false.
8. POST .../deliveries/{delivery}/recover may redispatch only a never-started queued attempt.
   Started/unknown attempts need provider reconciliation and a human decision, never blind retry.

Payloads/results are encrypted. Queue claim is atomic. The worker rechecks actor rights,
exact recipient/CC/token/snapshot and the frozen system Email provider binding; no fallback.

## Customer and timebank operations

Public: GET public/contracts/{token}, POST .../viewed and .../accept. GET is pure.
Accept requires name/confirm/current customer revision/key. Token rotation invalidates old
reads and replay. Staff identity cannot replace the public capability.

Portal: GET portal/contracts, GET portal/contracts/{contract} and POST .../accept.
Current web session, CSRF, active account/membership/contact and Client/site scope are required.
Acceptance preserves legal/audit/configured notification effects once. Site-only users cannot
accept client-wide Contracts.

GET clients/{client}/timebank?work_date=YYYY-MM-DD returns eligible lines/rates/period,
included/used/remaining minutes and balance revision. POST .../timebank/consumptions accepts
contract_item_id, time_rate_source, work_date, minutes and policy-required note.
Use the selected balance revision, not Contract revision. The locked action rechecks balance/
policy/rights and stores rate/period/usage snapshots. Identical retry returns the same entry;
stale new intent fails. This registers operational usage, not automatic invoice creation.
The existing workload-bound pseudonymous exports keep their separate access/activation.

## Troubleshooting

- 401/403: check token ability, active owner/current rights or portal identity.
- 404: verify exact owner/Client/site/item/offer/current token; do not enumerate IDs.
- 409: review changed state/revision/preview or uncertain provider evidence.
- 422: check Swagger fields/enums/nesting, dates/currency and note/limit policy.
- 428: read the current relevant revision and send If-Match.
- 503: rollout owner must complete the additive migration and native verification.
- Disabled delivery: human review is recorded; retain defaults until controlled activation checks pass.

This describes Dev and prerequisites, not production deployment, real message delivery
or successful retrieval by an employee's AI assistant.
