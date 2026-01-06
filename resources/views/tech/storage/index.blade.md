# tech.storage.index — View Specification (Inventory List)

**Creation date:** 2025-10-29
**URL:** `tech.storage.index`
**Access:** `storage.view` (read), `storage.manage` (bulk actions), `storage.export` (export)
**Controller:** `App\Http\Controllers\Tech\Storage\IndexController@index`
**Livewire/Components:** `App\Livewire\Tech\Storage\Index\*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 4.0 hours

---

## Purpose

A fast, predictable index of all inventory **items**, optimized for daily operations. Default list shows **“Should Order”** items.

**Should Order logic (default filter):**

* On-hand quantity = 0, **OR**
* Reserved quantity >= On-hand quantity, **OR**
* Manual flag `should_order` set by technician.
  *Source-of-truth fields*: `qty_on_hand`, `qty_reserved`, `should_order`.

---

## Layout (Bootstrap)

* **Top header**: Title, global filters, quick actions (right-aligned).
* **Main content**: Table/list with pagination and bulk actions.
* **Right slim rail**: Saved filters, quick stats, help.

---

## Table Columns (compact, responsive)

* **Item #** (SKU / internal item number) — link to item show.
  *Icon:* barcode.
* **Name** — primary label; secondary line can show variant/EAN when present.
  *Icon:* tag.
* **Warehouse** — current warehouse name.
  *Icon:* building.
* **Box** (optional) — if linked; shows box code and friendly name.
  *Icon:* package.
* **Location** — aisle/shelf/bin within warehouse.
  *Icon:* map-pin.
* **On-hand** — numeric.
  *Icon:* layers.
* **Reserved** — numeric (from quotes/picks/orders).
  *Icon:* lock.
* **Available** — computed: `on_hand - reserved` (not negative).
  *Icon:* check-circle.
* **Should Order** — badge if true (from logic above).
  *Icon:* alert-triangle.
* **Updated** — last stock mutation timestamp.
  *Icon:* clock.

*Reusable widgets*: Badge, Pill/Chip, Inline status dot, Tooltip on hover for full location path `Warehouse > Box > Location`.

---

## Filters & Sorting

**Defaults:** `Should Order = true` sorted by `Available (asc)`, then `Updated (desc)`.

**Filters (left-to-right in header):**

* **Warehouse** (dropdown) — list of warehouses; includes “All”.
* **Box** (dropdown, dependent on warehouse).
* **Location** (dropdown, dependent on warehouse/box).
* **Availability** (radio): All / Should Order / In Stock / Out of Stock.
* **Quantity range** (min/max) on `on_hand` and/or `available`.
* **Reserved state**: `reserved >= on_hand`, `reserved > 0`, `reserved = 0`.
* **Manual flag**: `should_order` on/off.
* **Text search**: item #, name, EAN, notes.

**Sorting (multi-key):** Item #, Name, Warehouse, Box, Location, On-hand, Reserved, Available, Updated.

**Quick chips** under the header: `Should Order`, `Out of Stock`, `Low/Zero Available`, `Manual Flagged`.

---

## Actions

**Row actions:** View, Adjust stock (modal), Move (warehouse/box/location), Reserve/Release, Toggle manual `should_order` flag, View history.

**Bulk actions (permission `storage.manage`):** Adjust stock, Move, Export selection (CSV), Toggle `should_order`.

**Global actions:**

* `+ New Item` (if scope allows creating items here)
* `Export CSV` (permission `storage.export`)

**Safety:** All stock mutations require note + reason; write to audit log.

---

## Right Rail (narrow)

* **Saved filters** (pin current set).
* **Quick stats**: total items, out-of-stock count, should-order count, total reserved.
* **Help**: tooltip legend for Should Order logic.

---

## Empty/Edge States

* **No results** (with current filters) — offer to clear filters.
* **No warehouses** — CTA to add a warehouse (permission-gated).
* **Box moved/unknown** — show fallback badge and link to fix location.

---

## Performance & UX Notes

* Use server-side pagination, sort, and filter; debounce search.
* Preserve filter state via query string.
* Real-time updates (Echo/WebSockets) for stock/reservation changes.

---

## Events & Logging

* Log all adjustments/moves/reservations (who, when, from→to, delta, note).
* Emit events: `ItemStockAdjusted`, `ItemReserved`, `ItemReleased`, `ItemMoved` for rule engine and dashboards.

---

## Reusable Components (mark for library)

* `InventoryTable`
* `WarehouseDropdown`, `BoxDropdown`, `LocationDropdown`
* `QuantityBadge(on_hand, reserved, available)`
* `ShouldOrderBadge` (derives from logic)
* `StockAdjustModal`, `MoveItemModal`

---

## Data/Field References

* `item_number`, `name`, `ean`, `warehouse_id`, `box_id`, `location_path`,
  `qty_on_hand`, `qty_reserved`, `qty_available (virtual)`, `should_order (manual flag)`,
  `updated_at`.

---

## Permissions

* `storage.view` — list and read.
* `storage.manage` — stock adjust, move, toggle flag, bulk.
* `storage.export` — export CSV.
* Audit visible to `tech.admin` and `superuser`.

---

## Testing Checklist

* Default view shows only Should Order per the three conditions.
* Changing warehouse updates box/location lists.
* `qty_available` never negative; UI clamps at 0 while backend stores true value.
* Bulk operations respect selection + filters.
* All mutations produce audit entries and events.
