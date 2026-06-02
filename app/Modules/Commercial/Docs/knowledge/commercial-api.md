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
