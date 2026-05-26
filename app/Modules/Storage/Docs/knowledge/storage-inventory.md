Storage is the operational inventory module for stock items, boxes, warehouses, reservations, picking, and reorder visibility.

Main routes:

- `/tech/storage` is the inventory work queue.
- `/tech/storage/items/create` creates a new stock item.
- `/tech/storage/items/{item}/edit` edits catalogue, supplier, stock, and pricing data.
- `/tech/storage/picking` is the picking queue for ticket cost reservations.
- `/tech/admin/settings/storage/inventory` is where admins create warehouses.

Core concepts:

- **Warehouse** is the physical inventory location.
- **Box** groups stock inside a warehouse and can be moved as a unit.
- **Item** is the SKU/catalogue record. It stores product identity, supplier information, stock thresholds, and pricing.
- **Movement** is the immutable audit log for stock changes.
- **Reservation** holds stock for a source such as a ticket without immediately removing it from on-hand quantity.
- **Picking** consumes reserved stock and makes it ready for billing through Economy.

The inventory list defaults to a reorder-focused view. The `Should order` view includes items that are manually flagged, out of stock, over-reserved, or at/below reorder point.

Create actions:

- `New Item` belongs in the inventory item card header.
- `New Box` belongs in the inventory item card header.
- `Add Warehouse` belongs in Admin > Storage inventory settings, not in the daily inventory list.

Supplier filtering:

- The inventory list can be filtered by primary supplier.
- This helps group reorder work by where parts are bought.

Stock math:

- On-hand is the current physical quantity.
- Reserved is the quantity promised to tickets or future order flows.
- Available is on-hand minus reserved, clamped to zero in the UI.
- Initial quantity on item creation creates a stock movement so the first stock count is auditable.
