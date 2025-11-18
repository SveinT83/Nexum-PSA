# tech.storage.boxes.show — View Specification (Box Detail)

**Creation date:** 2025-10-29
**URL:** `tech.storage.boxes.show:{boxId}`
**Access:** `storage.view` (read), `storage.manage` (item ops), `storage.box.manage` (move box, delete, status), `storage.export` (export list)
**Controller:** `App\Http\Controllers\Tech\Storage\Boxes\ShowController@show`
**Livewire/Components:** `App\Livewire\Tech\Storage\Boxes\Show\*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 4.5 hours

---

## Purpose

Show a single **Box** with its metadata, allow **moving the entire box** to another warehouse, **editing** the box (friendly name, barcode/QR, status), and **deleting** the box **only when empty**. Includes an inline **item list limited to the box’s contents** and per-item actions (move item out, stock adjust, reserve/release). Supports marking the box **Inactive** to prevent adding new items.

---

## Layout (Bootstrap)

**Top header** (left→right):

* Box title (friendly name) + small code/ID
* Status badge (Active/Inactive)
* Warehouse selector (read-only label) + **Move Box** button
* **Edit** (opens `tech.storage.boxes.create` for edit)
* **Delete** (enabled only if empty)

**Main content**:

* **Items in this box** — paginated table with bulk actions
* Inline banners for **Inactive** and **Move success** messages

**Right slim rail**:

* Box summary (counts, total on-hand, total reserved, last activity)
* Quick actions (Export CSV, Toggle Inactive/Active)
* Audit log (latest 5)
* Help (legend and guardrails)

Suggested icons: box, package, barcode, tag, building, map-pin, layers, lock, check-circle, alert-triangle, clock, move-horizontal, trash, edit, activity, file-down.

---

## Box Metadata (header)

* **Box Code/ID** (database ID as machine code; friendly name optional)
* **Status**: `Active` | `Inactive`
* **Warehouse**: name; **Move Box** opens modal → pick target warehouse. Moving the box **moves all items with it**.
* **Barcode/QR**: if present show small pill; copy-to-clipboard action.

**Behaviors**

* **Delete** disabled when `item_count > 0`; tooltip: “Empty the box before deletion.”
* **Inactive** box: disables **Add item** entry points (from elsewhere, attempts are blocked with error). Shows banner: “This box is inactive. New items cannot be added.”
* **Edit** opens shared create/edit screen: `tech.storage.boxes.create`.

---

## Items Table (box scope)

> Note: **Do not show Warehouse or Box columns** (implicitly this box).

**Columns** (compact, responsive):

* **Item #** (SKU/internal) — link to `tech.storage.items.show:{itemId}`
  *Icon:* barcode
* **Name** — primary label; secondary line for variant/EAN if present
  *Icon:* tag
* **Location** — optional sub-location inside box (slot/row)
  *Icon:* map-pin
* **On-hand** — numeric
  *Icon:* layers
* **Reserved** — numeric (quotes/picks/orders)
  *Icon:* lock
* **Available** — computed: `on_hand - reserved` clamped ≥ 0
  *Icon:* check-circle
* **Should Order** — badge if derived logic true
  *Icon:* alert-triangle
* **Updated** — last stock mutation timestamp
  *Icon:* clock

**Row actions**:

* **Move item** (to another box / to warehouse location)
* **Adjust stock** (modal; reason + note required)
* **Reserve / Release**
* **Toggle manual should_order**
* **View history** (drawer)

**Bulk actions** (`storage.manage`):

* Move selected items
* Adjust stock (multi)
* Toggle should_order
* Export selection (CSV)

**Global actions**:

* Export CSV (all filtered)

**Filters & Sorting**

* Text search: item #, name, EAN
* Availability chips: All / In Stock / Out of Stock / Should Order / Low/Zero Available / Manual Flagged
* Quantity ranges: on_hand / available
* Reserved state: reserved ≥ on_hand, reserved > 0, reserved = 0
* Sort: Item #, Name, Location, On-hand, Reserved, Available, Updated (multi-key)

Defaults: show **All items** in this box; sort by **Available (asc)** then **Updated (desc)**. Preserve state in query string.

---

## Actions (header & rail)

* **Move Box** (permission `storage.box.manage`): opens modal

  * Pick target warehouse
  * Confirmation text: moving box moves **all contained items**
  * Emits `BoxMoved`
* **Edit**: opens create/edit screen (name, status, barcode/QR)
* **Delete** (permission `storage.box.manage`):

  * Enabled only if box is empty
  * Requires typed confirmation of box code
  * Emits `BoxDeleted`
* **Toggle Inactive/Active** (rail): flips status; blocks new items when Inactive; emits `BoxStatusChanged`

---

## Safety & Guardrails

* Stock mutations require **reason + note**; write to audit log.
* Moving a box creates **one composite move event** plus child per-item move entries.
* Deleting a box requires `item_count == 0` at action time (server verifies).
* Race conditions: optimistic UI with server reconciliation; display toast on conflict.

---

## Events & Logging

* **ItemStockAdjusted** (who, when, itemId, delta, reason, note)
* **ItemReserved / ItemReleased** (qty, reference)
* **ItemMoved** (from box → to box/warehouse)
* **BoxMoved** (from warehouse → to warehouse, item_count)
* **BoxStatusChanged** (active↔inactive)
* **BoxDeleted** (id)

All entries visible in the right rail (latest 5) with link to full audit.

---

## Reusable Components (library)

* `BoxHeader` (title, status, actions)
* `BoxSummaryCard`
* `InventoryTable` (box-scoped variant)
* `LocationBadge`
* `QuantityBadge(on_hand, reserved, available)`
* `ShouldOrderBadge`
* `StockAdjustModal`, `MoveItemModal`, `MoveBoxModal`
* `AuditList`

---

## Data/Field References

**Box**: `id`, `code`, `name`, `status` (active/inactive), `warehouse_id`, `barcode`, `qr_code`, `item_count`, `created_at`, `updated_at`.

**Item (subset for table)**: `item_number`, `name`, `ean`, `box_id`, `location_subpath`, `qty_on_hand`, `qty_reserved`, `qty_available (virtual)`, `should_order`, `updated_at`.

---

## Permissions

* `storage.view` — read box + list items
* `storage.manage` — item-level actions in the box
* `storage.box.manage` — move/delete/toggle status/edit
* `storage.export` — export
* Audit is visible to `tech.admin`, `superuser`

---

## Performance & UX Notes

* Server-side pagination/filter/sort; debounce search
* Real-time updates via WebSockets for stock/reservations/status
* Preserve filter state via query string; back/forward friendly
* Empty state: “No items in this box yet” + hint (inactive/active status)
* Error states: conflict banners for concurrent changes (e.g., box emptied/filled during action)

---

## Testing Checklist

* Delete disabled when items exist; enabled when empty; server double-checks
* Inactive box blocks adding items (from elsewhere) and shows banner here
* Move Box relocates all items and emits correct events
* Item moves, reserves, adjusts all audit correctly with reasons
* qty_available never negative (UI clamps; backend preserves true value)
* Filters/sort/pagination persist; export respects filters
* Permissions enforced: view vs manage vs box.manage
