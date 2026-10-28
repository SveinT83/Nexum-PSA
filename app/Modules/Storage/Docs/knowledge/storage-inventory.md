Storage is the operational inventory module for stock items, boxes, warehouses, reservations, picking, and reorder visibility.

Main routes:

- `/tech/storage` is the inventory work queue.
- `/tech/storage/items/create` creates a new stock item.
- `/tech/storage/items/{item}/edit` edits catalogue, supplier, stock, and pricing data.
- `/tech/storage/purchase-orders` registers externally placed supplier orders and their shipments.
- `/tech/storage/receiving` is the queue for partial delivery receiving and receipt history.
- `/tech/storage/picking` is the picking queue for ticket cost reservations.
- `/tech/admin/settings/storage/inventory` is where admins create warehouses.

Core concepts:

- **Warehouse** is the physical inventory location.
- **Box** groups stock inside a warehouse and can be moved as a unit.
- **Item** is the SKU/catalogue record. It stores product identity, supplier information, stock thresholds, and pricing.
- **Movement** is the immutable audit log for stock changes.
- **Reservation** holds stock for a source such as a ticket without immediately removing it from on-hand quantity.
- **Picking** consumes reserved stock and makes it ready for billing through Economy.
- **Purchase Order** records an order already placed with a supplier and snapshots its commercial
  context. Registering it in Nexum never sends an order to the supplier.
- **Shipment** allocates ordered lines to one delivery and keeps append-only carrier tracking.
- **Purchase Receipt** is the immutable record of accepted and rejected quantities in one physical
  delivery. Only accepted quantity creates inventory movements.
- **Can be ordered** controls whether an active item may be reserved beyond current available stock.
- **Ticket purchase need** is a draft Purchase Order line created from customer-approved Ticket
  scope. It becomes an externally placed order only when a technician completes the supplier order
  details and advances it through the purchase-order workflow.

Default warehouse:

- Nexum keeps one default Company warehouse for Storage forms.
- If no active warehouse exists, opening Storage inventory settings or a Storage create form creates
  an active `Company Warehouse` with code `COMPANY`.
- Admins can change the default warehouse from `/tech/admin/settings/storage/inventory`.
- New Item and New Box forms preselect the configured default warehouse, but technicians can still
  choose another active warehouse when needed.

The inventory list defaults to a reorder-focused view. The `Should order` view includes items that are manually flagged, out of stock, over-reserved, or at/below reorder point.

Create actions:

- `New Item` belongs in the inventory item card header.
- `New Box` belongs in the inventory item card header.
- `Add Warehouse` belongs in Admin > Storage inventory settings, not in the daily inventory list.

Supplier filtering:

- The inventory list can be filtered by primary supplier.
- This helps group reorder work by where parts are bought.

## Sorting Inventory Tables

Click a linked table heading to sort the current view. Click the active heading again to reverse
the direction. The active heading shows its direction, search and filters remain selected, and a
new sort starts on the first result page. Optional values such as a missing supplier or box stay at
the end in both directions.

The main Inventory Items queue can be sorted by item/SKU, warehouse, supplier, box, on-hand,
reserved, available, incoming, and reorder status. With no selected sort, Nexum keeps the existing
default of most recently updated items first. Available quantity, incoming quantity, and reorder
status use the same stock and active Purchase Order rules as the values and badges shown in the
table.

Related read-only Inventory tables are sortable too:

- Admin Inventory warehouses: default state, name, code, address, item count, box count, and status.
- Item Movement History: time, type, before/delta/after quantity, reason, and actor.
- Box Contents: SKU, name, on-hand, and reserved quantity.
- Box Events: time, event type, and actor.

Box Contents and Box Events keep separate sort choices. Action columns, editable line tables, and
printed control slips are not sortable because their row order belongs to the workflow.

Stock math:

- On-hand is the current physical quantity.
- Reserved is the quantity promised to tickets or future order flows.
- Available is on-hand minus reserved, clamped to zero in the UI.
- Incoming is the positive quantity still outstanding on active ordered or partially received
  Purchase Orders. Received and cancelled line quantities are subtracted. Draft, received, closed,
  cancelled, deleted, and fully balanced orders do not count as incoming stock.
- A positive Incoming value shows **On order** in the row status. The quantity is not on-hand or
  available until a goods receipt is posted.
- Active items that can be ordered may be over-reserved from a Ticket. The item then appears in the
  `Should order` view and the picking queue waits for stock before it can be picked.
- Active items marked as not orderable can only be reserved up to available quantity.
- Initial quantity on item creation creates a stock movement so the first stock count is auditable.

## API

Storage exposes API routes under `/api/v1/storage` for trusted integrations, N8N workflows, future
barcode scanning, and AI-assisted technician work.

Implemented scopes:

- `storage.read`: list and view items, warehouses, and boxes.
- `storage.create`: create items, warehouses, and boxes.
- `storage.update`: update storage records and adjust item stock.
- `storage.purchase.read`: read purchase orders, shipments, tracking, receipts, and safe links.
- `storage.purchase.manage`: create and update orders, shipments, line cancellation, tracking, and
  order lifecycle.
- `storage.purchase.receive`: post accepted and rejected delivery quantities.
- `storage.purchase.receive_overage`: additionally permit a reasoned over-receipt.
- `storage.purchase.reverse`: reverse an eligible receipt through compensating movements.

Implemented routes:

- `GET /api/v1/storage/items`
- `GET /api/v1/storage/items/{item}`
- `POST /api/v1/storage/items`
- `PUT /api/v1/storage/items/{item}`
- `PATCH /api/v1/storage/items/{item}`
- `POST /api/v1/storage/items/{item}/adjust`
- `GET /api/v1/storage/warehouses`
- `POST /api/v1/storage/warehouses`
- `PUT /api/v1/storage/warehouses/{warehouse}`
- `PATCH /api/v1/storage/warehouses/{warehouse}`
- `GET /api/v1/storage/boxes`
- `POST /api/v1/storage/boxes`
- `PUT /api/v1/storage/boxes/{box}`
- `PATCH /api/v1/storage/boxes/{box}`
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

Item lookup supports `q`, `sku`, `ean_number`, `warehouse_id`, `box_id`, and `status`. Barcode
readers should initially use `q`, `sku`, or `ean_number` depending on what the device sends.

Stock changes must use `/api/v1/storage/items/{item}/adjust`. Directly changing `qty_on_hand` is not
allowed because it would bypass the movement history. Generic adjustment is also blocked for
serial-, batch-, or expiry-controlled items and for items with positive stock-unit ledger quantity;
those units require an operation that identifies exactly which units move.

Ticket Workflow operations use the Ticket API rather than the generic Storage API. An approved planned line can be converted with `/api/v1/tickets/{ticket}/planned-lines/{plannedLine}/convert` or turned into a draft purchase need with `/api/v1/tickets/{ticket}/planned-lines/{plannedLine}/purchase`. `POST /api/v1/tickets/{ticket}/cost-entries` uses the same quote-required policy as the browser `Add cost/item` action and can return `routed_to = quote_scope` with a planned line instead of an actual cost entry. Both operations enforce the Ticket's current workflow and user permissions.

Manual web adjustments:

- `Set on-hand to` is for inventory corrections after a physical count. Nexum calculates the delta
  from the current on-hand quantity.
- `Increase by` records a positive delta.
- `Decrease by` records a negative delta and cannot take on-hand quantity below zero.
- The API endpoint still accepts a raw `delta` for integrations that already calculate the change.
- Manual adjustment is unavailable for serial-, batch-, or expiry-controlled items and items with
  positive stock-unit ledger quantity. Purchase receiving identifies inbound units; identified-unit
  correction remains a separate workflow.

Deleting items:

- Storage items are soft-deleted so historical ticket, order, and invoice references keep their
  item ID and SKU context.
- An item can only be deleted when on-hand quantity, reserved quantity, active reservations, and
  stock unit quantities are all zero.
- Delete is available from the item detail page and through `DELETE /api/v1/storage/items/{item}`.
- The API delete route uses the existing `storage.update` scope.

## Full Storage API Coverage And Canonical Numbering

The Storage API guide documents the complete supported browser/API workflow,
required token scopes and current user permissions, supplier lookup, inventory and
incoming quantities, purchasing/receiving, picking, import review, profiles and
settings. Full API code is present on Main; production deployment and named human review
remain separate gates. See **Storage Inventory -> Storage API guide** in the handbook and Swagger on
the target installation. Canonical numbering is verified on Dev under Issue #286; check the target installation's
deployment before relying on this update.

## Storage API Guide

Availability: the full Storage API is present on Main. Canonical numbering is verified on Dev
on 2026-10-28 and its Main promotion is tracked with Issue #286. Production deployment and
the human scenario checklist remain separate gates.
Use this guide only against an installation where this API update is deployed.

## Prerequisites
Use an active internal Nexum account with the same Storage permissions required in
the browser. An API key cannot grant permissions the account does not currently hold.
An administrator creates a least-privilege token at **Admin -> Integrations -> API**.
Open **Swagger** at /api/documentation on the target installation for the exact
request fields, scopes and response contracts. Send the bearer token securely in
the Authorization header; never paste it into a support case or handbook article.

Use Accept: application/json. New writes also require a unique Idempotency-Key
header (8-200 characters). Keep that key for retries of the exact same operation.
If GET returns an ETag, send it as If-Match when changing that record to reject
a stale edit. Do not use a new key to repeat a result whose outcome is unknown.

## Register an externally placed supplier order
1. Search GET /api/v1/storage/suppliers?q=... with storage.suppliers.read.
   The account needs storage.view or storage.purchase_view. Lists return active
   suppliers by default. Check the stable ID and role/status fields; an ambiguous
   name returns multiple matches and requires an explicit selection.
2. Read the selected supplier by ID. Use the context and paginated item lookup APIs (or existing warehouse/item reads) to
   identify the delivery warehouse and existing supplier SKU. Supplier records
   remain owned by Documentation; this lookup does not create or change suppliers.
3. If needed, create the item using storage.create and the account's
   storage.item_manage permission. Supply warehouse_id, primary_vendor_id,
   supplier_sku, the item identity/prices and zero initial stock for goods that
   have not arrived. Read back the saved supplier line.
4. Register the order with POST /api/v1/storage/purchase-orders and
   storage.purchase.manage. The account also needs storage.purchase_manage.
   Supply the supplier, destination, external supplier reference, order date for
   an ordered order, currency and item lines. This records an order already
   placed outside Nexum; it does not send a purchase to the supplier.
5. Read the resulting order and item. qty_incoming is outstanding ordered
   quantity. It is separate from qty_on_hand, qty_reserved and qty_available.
   Only explicit receiving or other supported inventory actions change stock.

Nexum generates the canonical numeric po_number on successful creation. Omit po_number
from POST input; a nonempty caller-selected number returns 422. Read the returned value
and retain it as the Nexum business identity; never predict the next value. PUT may echo
the identical saved value for compatibility, but an attempted change returns 422. Existing
legacy identities and the separate external supplier reference are preserved. A numbering
503 requires operator reconciliation of the exact schema/counter, never a caller fallback.

## Inventory, boxes and picking
Use the item filters, summary, supplier lines and movement history to inspect stock.
The incoming quantity includes active placed orders and excludes cancelled or
closed quantities. Box detail includes current items and event history.
Use GET /api/v1/storage/picking with storage.picking.read to inspect reserved Ticket
costs. A keyed POST to /storage/picking/{costEntry}/pick uses the same guarded
Ticket action as **Pick** in the browser. It consumes stock, fulfils the reservation,
updates the cost and performs the existing Ticket/Economy follow-up once.
Serial-, batch- or expiry-tracked stock retains the existing refusal to use generic
picking; the API does not advertise a new tracked-unit workflow.

## Supplier Orders, shipments and receiving
GET /storage/supplier-orders exposes the unified operational queue.
GET /storage/receiving exposes the receiving queue. A caller sees only the
purchase/import branches its current user permissions and token scopes allow.
Existing purchase APIs cover order edits, shipments/tracking, line cancellation,
close/cancel, partial receipts and eligible receipt reversal. The control-slip
endpoint returns structured printable order/shipment data without a browser session.

Receipts and reversals keep their existing idempotency_token in addition to any
HTTP command key. Over-delivery requires the separate receive-overage ability and
current user permission, plus the domain's explanation. A reversal adds compensating
ledger entries; it does not delete receipt history.

## Review supplier imports
Use storage.imports.read to list and inspect imports. Source email evidence is a
sanitized supporting snapshot. Raw mailbox access and provider read state still
belong to Mail; Storage access does not grant mailbox access.

- storage.imports.resolve allows line mapping and audited manual correction.
- storage.imports.execute allows supported distinct-item creation, finalization,
  rejection and controlled retry. Existing actions retain their additional domain
  permissions and lifecycle guards.
- AI repair also requires storage.profiles.manage and the corresponding current
  user profile permission.

Check the returned import status and resulting purchase order. A retry response of
202/pending means queued processing, not a finished import, received stock or an
external supplier order. Finalizing an import does not receive goods.

## Profiles and administrator settings
Current browser administration permissions remain required. Inventory settings have
separate storage.settings.read and storage.settings.update scopes.
Policy uses storage.policy.read / storage.policy.update.
storage.profiles.read covers list/detail and export. storage.profiles.manage covers draft create/import, metadata
audit, immutable version creation, protected fixtures, fresh validation,
activation/rollback, pause/retire and portable export.

A profile must pass the same protected fixture validation before activation as in
the browser. Importing an exported profile produces an inactive draft with local
routing to configure. Validation is an explicit action and never silently activates
a profile. Ordinary policy inputs remain warehouse, selected Storage agent and
business review/fallback behavior; internal actor/workload mechanics stay managed
by Nexum.

## Controlled execution and result read-back
AI repair is default-off at the API boundary (STORAGE_API_AI_REPAIR_ENABLED).
Retry, profile activation/rollback and enabling automation through API settings
require the separate default-off STORAGE_API_AUTOMATION_ENABLED switch and explicit
confirm: true. A deployment operator must authorize activation separately.
Previews report the target and effects without changing business rows or calling AI.

Read GET /storage/operations/{operation_id} with storage.operations.read and the
original operation scopes. Results are restricted to the same actor and token and
recheck current user permissions. Keyed replay returns the retained result without
repeating stock, profile, import or provider effects. Reusing a key for another
request returns 409. An interrupted external call remains running/unknown with
reconciliation_required: true; inspect the retained evidence before a new command.
A completed API command can still contain a pending import: command completion
does not prove asynchronous work or external delivery finished.

## Troubleshooting
- 401: authenticate against the intended installation.
- 403: verify both token scopes and the account's current permissions/status.
- 404: verify the stable ID and the parent-child relationship.
- 409: reload lifecycle state, check key reuse and the API runtime switches.
- 412: fetch a fresh revision and review the changed data before retrying.
- 422: correct the named fields or the reported domain refusal.
- 428: provide the required idempotency header.
- 503: ask the operator to verify the API migration/storage and inspect operation
  state before repeating an uncertain write.

Human scenario review is tracked in HR-2026-10-26-STORAGE-API. Automated tests and
Dev availability do not constitute production deployment or manual acceptance.
