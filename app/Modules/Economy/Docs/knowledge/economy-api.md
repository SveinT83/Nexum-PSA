The Economy API covers the implemented order-preparation workspace, its administration and
customer-safe Portal reads. The current full surface is available on Dev under
`HR-2026-10-25-ECONOMY-API` (Reviewed by Svein Tore, 25 October 2026). Merged to Main in
[PR #315](https://github.com/SveinT83/Nexum-PSA/pull/315). Production deployment is not verified;
do not assume these additions exist in production. Swagger is the exact HTTP contract.

Economy prepares billing orders and Data Exchange files. It does not issue provider invoices,
send invoices, register payments or convert currencies.

## Access prerequisites

Use an active account and an explicitly scoped API key from Admin -> Integrations -> API.
Send `Authorization: Bearer {token}` and `Accept: application/json`. Never put a token in a URL.
The key limits access; the account must also retain the current browser permission.

| API ability | Current actor permission and behavior |
| --- | --- |
| economy.read | economy.order_manage: order list/detail/lines/context |
| economy.create | economy.generate_orders: installation-wide generation and its preview |
| economy.update | economy.order_manage: ready/draft/manual-invoiced and publication |
| economy.delete | economy.delete_orders: supported empty-order/draft-line deletion |
| economy.settings.read | economy.manage_settings: effective settings/context |
| economy.settings.update | economy.manage_settings: supported settings update |
| economy.export + data_exchange.run | economy.view + data_exchange.run: existing Economy export profile |
| economy.read + data_exchange.read | economy.view + data_exchange.view: Economy export run |
| economy.operations.read | Own operation only, plus its current original domain permission |

Settings use the same delegated permission as the Economy Admin page. An Admin label alone does
not bypass an installation's configured permissions. Existing token scopes are never widened.
Generated file downloads additionally require data_exchange.download scope/permission and
current Economy read scope/view permission. Rates and Units remain Commercial APIs.

## Complete endpoint surface

All paths below start with `/api/v1/economy`.

| Method | Path | Result |
| --- | --- | --- |
| GET | /orders | Filtered/paginated staff order list and status counters |
| GET | /orders/context | Status/sort/period defaults, owner links and supported capabilities |
| GET | /orders/{order} | Staff detail, effective/stored totals, revision and available actions |
| GET | /orders/{order}/lines | Complete paginated line access |
| GET | /orders/generate/preview | Read-only generation candidates, scope and freshness hash |
| POST | /orders/generate | Shared all-source generation and truthful affected-record summary |
| POST | /orders/{order}/ready | draft -> ready |
| POST | /orders/{order}/draft | ready -> draft |
| POST | /orders/{order}/invoiced | ready/approved -> manual_invoiced |
| POST | /orders/{order}/portal-visibility/preview | Review publish/hide impact |
| POST | /orders/{order}/portal-visibility | Explicit publication or hiding |
| DELETE | /orders/{order} | Empty draft/ready only |
| DELETE | /orders/{order}/lines/{line} | Draft line, source unlock and optional last-order deletion |
| GET | /settings | Effective settings, enums/default behavior and revision |
| PATCH | /settings | Partial update of supported settings |
| GET | /exports/preview | Existing Data Exchange profile, row count and freshness hash |
| POST | /exports | Execute the reviewed file export, without provider delivery |
| GET | /exports/{run} | Actual scoped run/file/checksum result |
| GET | /operations/{operation} | Own durable result and current notification-attempt status |
| GET | /portal/orders | Customer-safe published order list using existing login session |
| GET | /portal/orders/context | Current client-level availability |
| GET | /portal/orders/{order} | Customer-safe published detail |
| GET | /portal/orders/{order}/lines | Customer-safe active lines, paginated |

Portal paths require the existing browser login session and active membership/contact/client.
A staff bearer token does not establish Portal identity. Site memberships cannot see client-level
Economy summaries. There is no customer-side mutation or new customer token-issuance workflow.

## Safe mutation sequence

1. Read the order or settings and retain its `revision`. For publication, first POST the visibility
   preview with `portal_visible: true` or `false`; retain its revision and `preview_hash`.
2. Use a fresh `Idempotency-Key` of 8-200 characters for one intended operation. New manual-invoiced,
   publication and settings writes require it and `If-Match: {revision}`. Publication also requires
   the reviewed `preview_hash` in its body.
3. Send only the documented scalar fields. Unsupported fields, nested inputs, server-owned values
   and invalid states are rejected with 422. An omitted PATCH field is preserved; false and zero
   are applied. Setting nullable VAT to null means current default fallback, not VAT exemption.
4. Inspect the exact returned order/settings/receipt. Mark invoiced means manual_invoiced only;
   exported_at, accounting-provider state and payments are not changed by that action.
5. If a response is lost, repeat the identical path/body/headers with the same key. A committed
   command returns the original result with replayed=true and does not repeat its effects. Changing
   the payload for that key gives 409. Re-read and review after a stale revision/preview refusal.
6. Read GET /operations/{operation_id} for durable result and current notification evidence.
   Notification submission and an order state change do not prove inbox delivery.

The original generate/ready/draft/delete APIs remain compatible without the new headers. Supplied
headers are enforced. Use keys for safe retry. A legacy empty-order DELETE without a key returns
204; with a key it returns a durable deletion result and supports replay after deletion.

## Generation and correction

Preview is read-only and identifies installation-wide candidate counts and financial-client scope.
Counts are candidates before contract allocation and existing-source deduplication, not promised
invoice amounts. If more than 100 clients occur, the preview says its client-ID list is truncated
and reports the full count. No default/settings/period/allocation records are created by preview.

Generate accepts optional period_start/period_end in YYYY-MM-DD and optional reviewed preview_hash.
If using If-Match for generation, supply that preview_hash as the revision. The action handles
eligible Ticket time, picked Ticket costs, quick-timebank overuse and confirmed licence billing
periods. Covered time stays allocated without a billable line. No-billing Ticket close outcomes
customer_declined, cancelled and no_sale do not become ordinary billable output.

Summary distinguishes actual lines_created, lines_updated, sources_reused, orders_touched and
created/affected line/order IDs. A repeated run does not create another charge or an empty draft
for a source already on a ready order. Concurrent Economy writers use the same database boundary.

Delete only draft lines through their exact order parent. This unlocks the supported source for
correction/regeneration, recalculates totals and removes a last-line empty draft atomically.
Ticket time/cost, quick overuse and confirmed local licence periods retain their source identity
and recorded financial client. Moving a Ticket to another client does not silently retarget its
financial history. Stock movements, original consumption and provider subscriptions are not reversed
or deleted. Correct original time/cost data through its owner workflow before generating again.

## Totals, paging and fields

The staff API preserves legacy numeric stored totals and adds exact decimal stored_totals and
line decimals. effective_totals uses the same active-line/default-VAT display calculation as the
web and Portal. Reading does not rewrite old amounts or persist missing VAT defaults.

currencies lists the active-line currencies; currency_conversion=false. Do not treat mixed-currency
legacy combined totals as a converted single-currency amount. No FX calculation is implemented.

Order detail includes at most 100 lines, with lines_truncated and lines_url. Use the line endpoint
until links.next is null for complete access. Full order totals are never limited to that page.
Lists accept q/status/client_id/period_start/period_end, page/per_page (1..100), sort and direction.
The context endpoint lists actual supported sort/status values. Stats use the same active filters.

Customer projections contain active lines, display amounts and safe references; they exclude staff
identities, internal source metadata/IDs, generation diagnostics and staff mutation links.

## Export files and delivery outcomes

1. GET /exports/preview and review profile, format, row_count, configured-filter behavior and hash.
   Reads never initialize the profile. Execute initializes the existing default template only when
   it is absent; an existing configured profile is preserved.
2. POST /exports with preview_hash and a fresh Idempotency-Key. This uses the Data Exchange owner
   runtime for CSV/JSON/XLSX, profile filters, file history/checksum and audit. It dispatches no
   external delivery and does not mark an order exported or create a provider invoice.
3. Inspect status, files, checksum and error_code. A failed owner run is a durable failed result;
   replay does not repeat a failed file write. Investigate before a new deliberate attempt.
4. Read /exports/{run}; download through the returned canonical Data Exchange file URL with the
   required current owner scopes/permissions. Revoked Economy rights also block the owner API.

POST /api/v1/data-exchange/profiles/{profile}/runs uses the same preview/key workflow for the
canonical Economy Orders profile. There is no less-protected alternate download or export path.

Notifications have pending/processing/submitted/superseded/unknown states. They are claimed once
after the order commit, use existing current Portal audiences and notification preferences, and
never blindly retry an unknown partial submission. Ask an administrator to investigate unknown
outcomes. No new scheduler/provider activation is implied by exposing these APIs.

## Troubleshooting

- 401: supply the correct authentication mechanism; Portal requires a login session.
- 403: inspect both API scopes and the active user's current domain/owner permissions.
- 404: the parent/child, export profile or current published Portal scope does not match.
- 409: re-read/review changed data; do not reuse a key for a different operation payload.
- 422: use the supported fields/enums/date range and a valid state transition.
- 428: read the current revision and supply If-Match for the new mutation.
- 503: the required additive Economy migration must be applied before writes.
