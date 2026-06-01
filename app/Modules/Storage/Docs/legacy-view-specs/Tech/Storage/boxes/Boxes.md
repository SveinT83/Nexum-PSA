# tech.storage.boxes\* — General Documentation (Storage Boxes)

**Creation date:** 2025-10-29  
 **Module URL namespace:** `tech.storage.boxes.*`  
 **Access levels:** `storage.view`, `storage.manage`, `inventory.manage`, `superuser`  
 **Primary controller namespace:** `App\Http\Controllers\Tech\Storage\Boxes\*`  
 **Livewire namespace:** `App\Livewire\Tech\Storage\Boxes\*`  
 **Status:** Not started  
 **Difficulty:** Medium  
 **Estimated time:** 5.0 hours

---

## 1) Purpose

Boxes provide a portable container abstraction bound to a **warehouse/storage** location. **Items** are assigned to a box, which implicitly assigns them to the same warehouse. Moving a box automatically moves all contained items to the destination warehouse/location. The system supports human-friendly names and printed **barcode/QR** labels from day one.

**Key goals**

- Fast, predictable handling of grouped inventory moves.
- Traceable history of box status, location, and content changes.
- Ready for mobile/PWA scanning workflows.
- Clean, Bootstrap-based UI with top/main/right layout.

**Out of scope (v1)**

- Nested boxes (no hierarchy).
- Automatic restocking logic.

---

## 2) Core Concepts & Relations

- **Warehouse/Storage** (`storage.warehouse`): Physical store. A box **belongs to** one warehouse at any time.
- **Box** (`storage.box`): Logical container, holds items, has a status and a placement note.
- **Item** (`inventory.item`): Articles/resources tracked by SKU/serial/EAN, **belongs to** a box or is unboxed.
- **Box Event** (`storage.box_event`): Immutable log entries for creation, moves, status changes, intake/withdrawals.

### Movement semantics

- Moving a **box** to another warehouse/location teleports the **box and all items** it contains in a single transaction.
- Items retain individual serial/EAN and cost metadata; the box provides grouping and movement convenience.

---

## 3) Data Model (suggested)

### Box

- `id` (PK, numeric; also default **Box ID** used in labels)
- `uuid` (for external references)
- `warehouse_id` (FK → warehouse)
- `code_human` (optional human-friendly slug/code; unique per tenant)
- `name` (friendly name; optional)
- `barcode_value` (string; same as `id` by default; can be overridden)
- `barcode_type` (enum: `QR`, `EAN13`, `CODE128`)
- `status` (enum: `in_stock`, `in_transit`, `loaned`, `at_customer`, `lost`, `retired`)
- `placement_note` (free text; e.g., "aisle B, top shelf", "in the van")
- `is_active` (bool)
- `created_by`, `updated_by`
- Timestamps; soft-deletes

### Relations

- `hasMany(Item)`
- `belongsTo(Warehouse)`
- `hasMany(BoxEvent)`

### BoxEvent

- `id`, `box_id`, `actor_id`
- `type` (enum: `created`, `renamed`, `status_changed`, `moved`, `intake`, `withdrawal`, `audit`)
- `from_warehouse_id` / `to_warehouse_id` (nullable)
- `details` (JSON: quantities, scan payloads, reason)
- Timestamps

---

## 4) Behaviors & Rules

- **Move Box:** updates `warehouse_id` atomically and cascades to child items’ warehouse reference (if denormalized).
- **Status Changes:** drive operational workflow:
  - `in_transit` ⇒ visible in dashboards and excluded from normal picking.
  - `loaned` / `at_customer` ⇒ flagged for return checks.
  - `lost` / `retired` ⇒ disabled for intake.
- **Scanning:** when scanning `barcode_value`, app routes to `boxes.show` with quick actions (move, intake, withdrawal).
- **Audit Trail:** every state change writes a `BoxEvent`.

**Rule engine hooks** (non-blocking):

- `box.moved` → can post internal note on related ticket or alert.
- `box.status_changed` → can notify channel/role.
- `box.audit_failed` → can create task to reconcile stock.

---

## 5) Views (Bootstrap layout: Top / Main / Right)

> All views list components, controls, widgets and icons only (no HTML). Live updates where meaningful.

### A) `tech.storage.boxes.index`

**Access:** `storage.view`  
 **Controller:** `...\Boxes\IndexController@index`  
 **Status:** Not started  
 **Difficulty:** Low  
 **Estimated time:** 1.5 hours  
 **URL:** `tech.storage.boxes.index`

**Purpose:** List/search/filter boxes with status/location and quick actions.

**Components**

- Header: Title, `+ New Box` (button), `Scan` (button)
- Filters: Warehouse selector, Status multi-select, Active only toggle, Free text search
- Table/List columns: Box ID, Name, Warehouse, Status (icon), Placement, Items count, Last event time
- Row actions: View, Move, Edit, Print Label, Disable/Enable

**Suggested icons:** box, map-pin, move, printer, qrcode, toggle-right

---

### B) `tech.storage.boxes.show:{boxId}`

**Access:** `storage.view`  
 **Controller:** `...\Boxes\ShowController@show`  
 **Status:** Not started  
 **Difficulty:** Medium  
 **Estimated time:** 1.5 hours  
 **URL:** `tech.storage.boxes.show:{boxId}`

**Purpose:** Single box dashboard with contents and history.

**Components**

- Top: Box identity card (ID, name, barcode preview, status, warehouse, placement note)
- Main: Items grid/list (SKU, serial/EAN, qty, reserved flags)
- Right rail: Quick actions (Move, Change Status, Intake, Withdrawal, Print Label)
- Tabs: History (BoxEvents), Notes, Attachments (e.g., photo of the box)

**Suggested widgets:** barcode/QR preview, status pill, timeline (events)

---

### C) `tech.storage.boxes.create` / `tech.storage.boxes.edit:{boxId}`

**Access:** `storage.manage`  
 **Controller:** `...\Boxes\EditController@form` / `@save`  
 **Status:** Not started  
 **Difficulty:** Low–Medium  
 **Estimated time:** 1.0 hour  
 **URL:** `tech.storage.boxes.create`, `tech.storage.boxes.edit:{boxId}`

**Purpose:** Create or edit box metadata.

**Fields**

- Warehouse (required)
- Name (optional)
- Human code (optional)
- Barcode value & type (default to `id` + `QR`)
- Placement note
- Status
- Active toggle

**Buttons:** Save, Save & Print Label, Close

---

### D) `tech.storage.boxes.move:{boxId}`

**Access:** `storage.manage`  
 **Controller:** `...\Boxes\MoveController@form` / `@move`  
 **Status:** Not started  
 **Difficulty:** Medium  
 **Estimated time:** 1.0 hour  
 **URL:** `tech.storage.boxes.move:{boxId}`

**Purpose:** Move box between warehouses/placements (atomic with items).

**Fields**

- From (read-only) / To warehouse
- Placement note (new)
- Reason (optional)
- Confirmation checkbox (affects all contained items)

**Buttons:** Move, Cancel

---

### E) `tech.storage.boxes.scan`

**Access:** `storage.view` (intake/withdrawal require `inventory.manage`)  
 **Controller:** `...\Boxes\ScanController@index`  
 **Status:** Not started  
 **Difficulty:** Medium  
 **Estimated time:** 1.0 hour  
 **URL:** `tech.storage.boxes.scan`

**Purpose:** PWA-friendly scanner surface for barcode/QR.

**Components**

- Camera scanner widget
- Auto-resolve to `boxes.show` or quick intake/withdrawal modal
- Fallback manual input

---

### F) `tech.storage.boxes.audit:{boxId}`

**Access:** `storage.manage`  
 **Controller:** `...\Boxes\AuditController@start`  
 **Status:** Not started  
 **Difficulty:** Medium  
 **Estimated time:** 1.0 hour  
 **URL:** `tech.storage.boxes.audit:{boxId}`

**Purpose:** Verify physical contents vs system records.

**Components**

- Expected items list
- Scan/mark present/missing/damaged
- Generate `audit` BoxEvent and optional reconciliation task

---

## 6) Logging & Audit

- All create/update/move/status actions write to `box_events` and to the system action log.
- Controller-level guard rails: idempotent move, transaction boundaries, and clear error messages for partial failures.

---

## 7) Permissions

- `storage.view`: list and view boxes.
- `storage.manage`: create/edit/move/status/print/audit.
- `inventory.manage`: intake/withdrawal operations affecting items.
- `superuser`: override moves and force status.

---

## 8) Smart UX Suggestions

- **Scan-to-Action:** scanning a code brings up contextual quick actions.
- **Placement presets:** per-warehouse placement suggestions (e.g., popular shelves).
- **Label batch print:** from Index selection.
- **Conflict hints:** warn if any contained item is reserved on an open ticket/order.

---

## 9) Integration Notes

- Works with ticketing: reservations and shipments can reference box IDs.
- Export/import labels as PDF for later printing.
- Real-time updates via websockets when boxes move.