# Feature Slice: Storage Purchase Order Registration And Shipment Tracking

Status: Done
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-purchase-orders-shipping-receiving.md`
Owner: Svein / Codex

## Goal

Let technicians register externally placed supplier orders in Storage and follow one or more
shipments and tracking identifiers without implying that Nexum submitted the order.

## User-Visible Behavior

- A compact Purchase Orders queue supports search and lifecycle, supplier, warehouse, expected-date,
  and tracking filters.
- Technicians can register and update a draft or placed order using existing Storage items.
- Purchase-order details show supplier and destination context, ordered-line progress, originating
  Ticket links, shipment allocations, and all tracking identifiers.
- A purchase order can have several shipments, and each shipment can have several tracking
  identifiers.
- Tracking identifiers are clickable only when the Documentation-owned safe resolver accepts the
  direct URL or carrier snapshot configuration.
- Technicians can append handoff or last-mile tracking identifiers without editing historical
  tracking or carrier snapshots.
- Manual shipment status changes follow guarded forward transitions and retain reason, actor, and
  event time.
- Active order lines can have a reasoned outstanding quantity cancellation. Nexum first consumes
  unallocated outstanding quantity, then reduces active shipment outstanding deterministically and
  records those allocation changes.
- Supplier, destination, currency, and line snapshots are visibly locked wherever shipment or
  receipt history makes the corresponding backend fields immutable.
- Completed orders can then be explicitly closed and locked.
- An unreceived order can be cancelled with a required reason after active shipments are cancelled.
- The shared Storage workspace navigation exposes Purchase Orders.

## Scope

- Purchase-order create, index, show, and lifecycle-compatible edit surfaces.
- Existing Storage item selection with quantity, cost, tax, expected date, and order snapshots.
- Supplier and destination-warehouse selection.
- Shipment registration, optional line allocations, carrier snapshot, and multiple tracking rows.
- Append-only tracking registration and guarded manual shipment lifecycle changes.
- Reasoned line-remainder cancellation, whole-order cancellation, and explicit completion locking.
- Shipment allocation capacity excludes cancelled shipments in the backend and registration form.
- Operational-history-aware order and per-line edit controls preserve locked values on submission.
- Terminal purchase orders do not expose shipment mutation controls.
- Supplier-specific item defaults that follow the purchase order's selected supplier.
- Safe carrier tracking-link resolution on the purchase-order detail page.
- Compact Bootstrap views, centralized breadcrumbs, responsive tables, and focused feature tests.
- Preservation and display of existing Ticket and planned-line links.

## Out Of Scope

- Sending an order to a supplier or supplier-webshop automation.
- Carrier polling, credentials, booking, labels, or webhooks.
- Goods receipt posting, stock movements, discrepancies, and reversals; those belong to Slice 3.
- Supplier invoice matching, accounting export, and landed-cost allocation.
- Mutation API parity beyond an explicitly implemented read boundary.

## Data Touched

- `storage_purchase_orders`
- `storage_purchase_order_lines`
- `storage_purchase_shipments`
- `storage_purchase_shipment_lines`
- `storage_purchase_shipment_trackings`
- Read-only references to `storage_items`, `storage_warehouses`, `vendors`, and
  `shipping_carriers`

## Permissions

- `storage.purchase_view` protects the queue and detail/read surfaces.
- `storage.purchase_manage` protects order and shipment creation or update.
- Documentation carrier configuration remains protected separately and does not grant Storage
  mutation access.
- Route permission mappings must remain more specific than the broad `storage.view` fallback.

## Tests

- Register a placed order with several existing Storage items.
- Validate supplier, warehouse, quantities, costs, dates, and lifecycle input.
- Return validation errors, rather than runtime errors, for malformed nested JSON payloads.
- Add multiple shipments, line allocations, and tracking identifiers.
- Append a later handoff tracking number while retaining the original carrier snapshot.
- Reject shipment status skips, backward transitions, and mutation without manage permission.
- Reconcile partial line cancellation against active shipment outstanding and audit affected
  allocations.
- Exclude cancelled shipments from new shipment allocation capacity.
- Hide shipment mutation controls for terminal orders while retaining permitted late tracking on
  received orders.
- Lock currency and only the order lines with operational history in the edit form.
- Require reasons and immutable history for line, order, and shipment lifecycle events.
- Close only completed orders with no outstanding quantity.
- Render safe direct, template, and generic tracking links while keeping unsafe links plain text.
- Filter the queue by lifecycle, supplier, warehouse, expected date, and tracking number.
- Verify read-only and purchase-management route permissions.
- Resolve blank commercial defaults against the selected order supplier, not another primary supplier.
- Preserve Ticket source links and avoid duplicate order lines during compatible conversion.
- Render the responsive detail and shipment forms.

## Documentation

- Parent RFC records the approved target behavior and ordered slice plan.
- Storage Knowledge documentation is updated when the end-to-end receiving workflow is complete.
- Release readiness, TODO status, BookStack sync, and human-review tracking are completed in Slice 4.
