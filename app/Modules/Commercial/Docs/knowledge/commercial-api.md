Commercial API routes expose the first stable commercial data surfaces for trusted integrations,
automation, and future AI agents.

All routes live under `/api/v1/commercial` and use Sanctum bearer tokens.

Required scopes:

- `commercial.read`: list and view commercial records.
- `commercial.create`: create commercial records.
- `commercial.update`: update commercial records.

## Included Resources

This API slice covers:

- Services.
- Contracts.
- SLA policies.
- Time rates.

It intentionally does not expose public contract sending, quote sending, acceptance, contract item
editing, package composition, cost catalogue editing, or legal term snapshot refresh. Those workflows
have more side effects and need separate API slices.

## Services

Routes:

- `GET /api/v1/commercial/services`
- `GET /api/v1/commercial/services/{service}`
- `POST /api/v1/commercial/services`
- `PUT /api/v1/commercial/services/{service}`
- `PATCH /api/v1/commercial/services/{service}`

List filters:

- `q`
- `status`
- `billing_cycle`
- `availability_audience`
- `orderable`

Common fields:

- `sku`
- `name`
- `unitId`
- `sla_id`
- `category_id`
- `status`
- `availability_audience`
- `orderable`
- `taxable`
- `billing_cycle`
- `price_ex_vat`
- `price_including_tax`
- `short_description`
- `long_description`
- `customer_unit_singular`
- `customer_unit_plural`

## Contracts

Routes:

- `GET /api/v1/commercial/contracts`
- `GET /api/v1/commercial/contracts/{contract}`
- `POST /api/v1/commercial/contracts`
- `PUT /api/v1/commercial/contracts/{contract}`
- `PATCH /api/v1/commercial/contracts/{contract}`

List filters:

- `q`
- `client_id`
- `status`

The create endpoint creates a draft contract only. Contract sending, public approval, and line-item
editing remain owned by the Tech UI until their API slices are designed.

Contract resources expose additive `pricing` and `customer_document` projections. `pricing`
contains exact line/cadence amounts from the shared Commercial calculator, including integer minor
units and canonical decimal strings. `customer_document` is the same six-column Norwegian customer
projection used by the Tech preview, secure public page, Customer Portal, captured acceptance
evidence, and PDF. `customer_document_readiness` reports whether that evidence is available.

Monthly, annual, one-time/setup, and supported legacy quarterly amounts remain separate. A zero-value
customer line is included rather than silently omitted.

The API may update editable Contract metadata, but it does not edit lines, send agreements, accept
agreements, refresh terms, or rewrite `customer_document_snapshot`. Sent and approved Contracts are
locked against ordinary metadata updates. When both dates exist, `binding_end_date` must be on or
before `end_date`; validation errors use Norwegian customer-facing wording.

A null customer-document snapshot means no captured JSON exists. Every non-null empty, scalar,
malformed, or unsupported-schema value is immutable evidence and makes the API fail closed; it is
never replaced with a live projection. Version 1 validates the complete typed envelope, including
document/dates/parties/approval, the exact ordered Norwegian six-column map, priced lines, all four
totals, optional rate/support structures, and appendices. Minor/decimal/display values and the
`Inkludert` invariant must agree semantically. `schema_version: 1` alone is rejected.

Every legacy sent/approved/won row without complete customer JSON requires named manual attestation
against original evidence; API reads never repair or backfill it from mutable Company Profile, Client,
Service, SLA, term, Time Rate, or integration data. A detail request returns 409 until attested. The
paginated list isolates the row instead: `customer_document`, `pricing`, and
`total_monthly_amount` are null and `customer_document_readiness` is
`{ready:false, code:"manual_verification_required", message:...}`. Other rows and honest pagination
remain available. Normal rows report `{ready:true, code:"ready", message:null}`.

Contract sale price currently supports NOK only, independently of cost currency. Authoritative
Service/offer currency is re-read on mutation; non-NOK data fails before persistence rather than being
formatted as kroner.

## SLA Policies

Routes:

- `GET /api/v1/commercial/slas`
- `GET /api/v1/commercial/slas/{sla}`
- `POST /api/v1/commercial/slas`
- `PUT /api/v1/commercial/slas/{sla}`
- `PATCH /api/v1/commercial/slas/{sla}`

When an SLA is marked as default, Nexum clears the default flag from other SLA policies. If no default
SLA exists and a saved SLA is not marked default, Nexum promotes it to default so clean installs keep a
usable policy.

## Time Rates

Routes:

- `GET /api/v1/commercial/time-rates`
- `GET /api/v1/commercial/time-rates/{rate}`
- `POST /api/v1/commercial/time-rates`
- `PUT /api/v1/commercial/time-rates/{rate}`
- `PATCH /api/v1/commercial/time-rates/{rate}`

List filters:

- `q`
- `is_active`

Time rate slugs are generated from `code`, matching the Tech UI behavior.
`is_customer_visible` is an explicit presentation setting returned and accepted by the Time Rate
resource. It defaults to false and does not affect operational Ticket/timebank rate eligibility.

Customer-visible Contract rate snapshots are returned only through the deduplicated customer-document
projection; they are not inferred from rate names.
