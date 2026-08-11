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

Ticket Workflow operations use the Ticket API rather than the generic Storage API. An approved planned line can be converted with `/api/v1/tickets/{ticket}/planned-lines/{plannedLine}/convert` or turned into a draft purchase need with `/api/v1/tickets/{ticket}/planned-lines/{plannedLine}/purchase`. Both operations enforce the Ticket's current workflow and user permissions.

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
