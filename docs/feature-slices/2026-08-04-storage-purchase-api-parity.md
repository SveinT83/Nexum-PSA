# Feature Slice: Storage Purchase API Parity

Status: Done
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-purchase-orders-shipping-receiving.md`, Slice 4
Owner: Svein / Codex

## Goal

Expose the approved Storage purchase workflow to trusted API clients without creating an alternate
business path around the browser actions, lifecycle rules, inventory posting, or receipt reversal
guards.

## User-Visible Behavior

Scoped API clients can list and inspect purchase orders, register and update externally placed
orders, manage shipments and tracking identifiers, cancel outstanding line quantities, close or
cancel eligible orders, post partial goods receipts, and reverse eligible receipts.

Receipt creation and reversal retain their domain idempotency tokens. A normal receive token cannot
approve an over-delivery; the same token must also hold the explicit overage ability, and the line
must include an explanation.

## Scope

- Add five purchase-specific abilities to the Integration-owned API ability catalog.
- Add twelve versioned Storage API routes in the Storage module.
- Use the same Storage actions as the browser workflow for every mutation.
- Return nested purchase-order, line, shipment, tracking, receipt, and stock-unit resources.
- Return only the safe computed tracking URL rather than exposing the raw direct-link field.
- Enforce nested order ownership before shipment, line, and receipt mutations.
- Distinguish received, rejected, cancelled, and outstanding shipment-allocation quantities without
  treating rejected goods as received order stock.
- Keep mutation links lifecycle-aware so terminal resources do not advertise invalid actions.
- Add OpenAPI attributes for every route.
- Add focused ability, route, lifecycle, idempotency, overage, reversal, and resource tests.

Implemented endpoints:

- `GET /api/v1/storage/purchase-orders`
- `POST /api/v1/storage/purchase-orders`
- `GET /api/v1/storage/purchase-orders/{purchaseOrder}`
- `PUT /api/v1/storage/purchase-orders/{purchaseOrder}`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/lines/{purchaseOrderLine}/cancel`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/close`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/cancel`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/shipments`
- `PATCH /api/v1/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/status`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/trackings`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/receipts`
- `POST /api/v1/storage/purchase-orders/{purchaseOrder}/receipts/{purchaseReceipt}/reverse`

## Out Of Scope

- Carrier polling, webhooks, credentials, booking, and label purchase.
- Automatically placing orders with suppliers.
- A separate API-only mutation service or relaxed validation path.
- API deletion or editing of posted receipt ledger rows.
- Applying migrations or seeders to a live database as part of this slice.

## Data Touched

The API does not add tables. Mutations use the approved purchase-order, shipment, tracking, receipt,
stock-unit, movement, and lifecycle tables through their existing transactional actions.

The Integration ability catalog, Storage API routes/controllers/resources/tests, Integration
Knowledge documentation, and this Feature Slice are changed.

## Permissions

- `storage.purchase.read`: list and inspect purchase records.
- `storage.purchase.manage`: purchase-order, shipment, tracking, and lifecycle mutation.
- `storage.purchase.receive`: post normal idempotent receipts.
- `storage.purchase.receive_overage`: allow explained over-delivery only when combined with receive.
- `storage.purchase.reverse`: create guarded idempotent reversal receipts.

The existing `storage.read`, `storage.create`, and `storage.update` abilities remain limited to the
inventory API. Purchase abilities do not imply one another.

## Tests

`PurchaseOrderApiTest` covers:

- Ability catalog access metadata and exact middleware on all routes.
- Read-only access without mutation inheritance.
- Ticket identity and Ticket-specific metadata remain hidden unless the token also has
  `tickets.read`.
- Create/update action parity.
- Shipment allocation, tracking append, and manual status action parity.
- Received, rejected, cancelled, and outstanding shipment-allocation resource values.
- Nested-resource ownership rejection.
- Line cancellation, close, and cancel lifecycle transitions.
- Receipt idempotency without duplicate stock movements.
- Separate overage authorization.
- Guarded, idempotent reversal behavior.
- Cancellation-link suppression after reversed receipt history.
- Lifecycle-aware mutation links.

The focused Storage, Integration, Ticket regression suites and OpenAPI scan are required before the
combined RFC handoff.

Final Dev verification passed on 2026-08-04:

- `HOME=/tmp php artisan test` for `PurchaseOrderApiTest`, `PurchaseOrderWorkflowTest`,
  `PurchaseReceivingActionsTest`, `PurchaseReceivingWorkflowTest`, `StorageModuleTest`,
  `IntegrationModuleTest`, and `TicketWorkflowV3Test`: 141 tests and 1,550 assertions passed.
- `vendor/bin/openapi app -o /tmp/tdpsa-openapi.json`: generation passed, with all 12 purchase
  routes represented by 12 purchase OpenAPI operations.
- `vendor/bin/pint --test` for the purchase API controller, routes, ability catalog, resources, and
  API test: all 12 PHP files passed.
- `git diff --check` passed for the tracked API documentation, ability catalog, and routes.

The parent handoff subsequently applied all purchase migrations and seeders to Dev and synced the
Documentation, Storage, Ticket, and Integration Knowledge sources. Interactive browser checks remain
part of the shared human-review handoff.

## Documentation

- This Feature Slice records the delivered API contract.
- Integration Knowledge documentation lists the endpoints and least-privilege abilities.
- The parent RFC remains the source of truth for lifecycle and inventory behavior.
- The combined Level 3 workstream must retain an open entry in `docs/human-review.md` until a named
  human reviewer explicitly verifies it.

## Done Criteria

- [x] All purchase API routes have explicit Sanctum abilities.
- [x] All mutation endpoints call the browser workflow's domain actions.
- [x] Receipt and reversal idempotency are preserved.
- [x] Overage authorization is independent from normal receipt authorization.
- [x] Nested resources cannot be mutated through another order URL.
- [x] Tracking resources expose only a safe computed click-through URL.
- [x] OpenAPI attributes cover the implemented endpoint surface.
- [x] Focused API tests pass on Dev.
- [x] Combined RFC automated regression suites and OpenAPI generation pass on Dev.
- [x] The parent handoff applies the migrations and seeders, syncs Knowledge, records deployment
  notes, and completes the expected 302/302/401 HTTP smoke checks on Dev.
- [ ] A named human reviewer completes the visual and workflow checks in `HR-2026-08-04-001`;
  automated browser QA remains blocked by the Dev environment's self-signed certificate.
