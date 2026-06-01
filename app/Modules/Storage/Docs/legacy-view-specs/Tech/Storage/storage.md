# tech.storage* — General Documentation (Warehouse & Inventory)

**Creation date:** 2025-10-29
**Status:** In progress
**Difficulty:** Medium–High
**Estimated time:** 9.0 hours

---

## Purpose & Scope

Provide a modular inventory system for internal use first, later multi‑tenant. Focus on predictable stock control, reservations from tickets and future Sales Orders, clear shortage visibility, audited movements, simple receiving/put‑away, and a focused picking queue.

---

## View Structure (Blade files)

```
app/Modules/Storage/Views/
 ├── Admin/
 │   └── Inventory/index.blade.php ← warehouse administration
 └── Tech/Storage/
     ├── index.blade.php           ← inventory work queue
     ├── picking.blade.php         ← ticket reservation picking queue
     └── storage.md
```

Each view follows standard PSA layout: **Top header / Main content / Right slim rail**.

---

## Core Concepts (Data & Logic)

* **Warehouse** → inventory location managed from Storage inventory administration.

  * Fields: name, code, address, notes, is_active.
* **Room** → zone/area within a warehouse (owned by Storage).

  * Fields: name, code, notes, is_active, location_id.
* **Container** → box/pallet/bin with unique code, can be moved as a unit between rooms.

  * Fields: code, label, type (box/pallet/bin), location_id, room_id, status (in_place/in_transit), notes.
* **Item (SKU)** → catalog item that can be stocked and sold.

  * Fields: sku, name, short description, long description, ean/barcode, vendor/manufacturer, manufacturer part number, primary supplier, purchase price, markup percent, sale price, VAT rate, serial handling, reorder point, target level, lead time, MOQ, and status.
* **Stock Unit** → atomic stock record for serial/batch tracked goods.

  * Fields: item_id, serial_no, batch_no, expiry_date, location_id, room_id, container_id, status (available/reserved/loaned/damaged), current_qty (usually 1 for serial-tracked), audit refs.
* **On-Hand (Aggregate)** → per item & location totals.

  * Computed: total_stock, reserved_stock, available_stock (= total − reserved).
* **Reservation** → holds qty against an order/ticket without removing from total_stock.

  * Fields: item_id, qty, source_type (sales_order/ticket/manual), source_id, soft_or_hard (default hard), created_by, created_at.
  * Ticket reservations are created from ticket cost rows and are picked from `tech.storage.picking`.
* **Movement Log** (immutable) → every stock change.

  * Types: receive, relocate, reserve, unreserve, issue, ship, return, adjust, loan_out, loan_in, damage, audit_correction.
  * Each row stores before/after **location/room/container** + qty/serial/batch + actor + reason.
* **Vendor / Supplier partner** → one Documentation-owned master register used for product manufacturers and purchase suppliers across the whole system.

  * Fields: name, vendor_code, org_no, website, contact info, default lead time, terms, active state, and vendor/manufacturer/supplier roles.
  * UI labels should distinguish the roles: `Vendor / Manufacturer` is who makes the item, while `Supplier` is where we buy it.
  * Storage item forms do not create vendors or suppliers inline. Use `New vendor` or `New supplier` to open the Documentation master-data form in a new tab, save the partner there, then select it on the Storage item.
  * Item supplier lines store supplier SKU, purchase URL, unit cost copied from the item purchase price, lead time, MOQ, pack size, and primary supplier state.
* **Purchase Order (PO)** → request to vendor.

  * Header: vendor_id, **deliver_to_location_id** (from Admin locations), status (draft/sent/partial/received/closed/cancelled), vendor_ref, tracking_no, documents (order confirmation).
  * Lines: item_id, qty_ordered, unit_cost, tax, expected_date, qty_received.

---

## Future Purchase Ordering And Shipping Tracking

This is planned, not active scope yet.

Storage should later support a practical ordering flow for buying parts from vendor web shops:

* Items can store one or more vendor purchase URLs, so technicians can open the correct product page when ordering.
* A purchase order can be registered after the item is bought online.
* Purchase orders should store vendor, external order number/reference, order date, expected delivery date, and status.
* Purchase order lines should store item, vendor item number/SKU, quantity, unit cost, and received quantity.
* Shipping tracking should store carrier, tracking number, tracking URL, shipment status, and delivery updates where available.
* The receiving flow should connect the purchase order lines back to Storage stock movements when goods arrive.
* The picking list can later surface whether waiting stock has an open purchase order and visible tracking status.

---

## Ticket Picking List

Route: `tech.storage.picking`

Purpose: one operational queue for ticket cost rows where a technician has reserved a storage item. The list is sorted with rows that can be picked now first, followed by rows waiting for stock.

Rules:

* Only ticket cost entries with `status = reserved` and a linked storage item are shown.
* A row is **Ready** when `storage_items.qty_on_hand >= ticket_cost_entries.quantity`.
* A row is **Waiting for stock** when the reserved quantity is higher than on-hand stock.
* Clicking **Pick** calls the shared ticket picking action. That consumes stock, reduces reserved stock, marks the storage reservation as fulfilled, creates a `ticket_pick` movement, marks the ticket cost row as picked, and lets Economy generate the order basis.
* The Picking List is exposed in the Storage top navigation dropdown and the Storage workspace sidebar.
* The right sidebar has a user-facing Documentation widget focused on how to use the Picking List. Its source page is `app/Modules/Storage/Docs/knowledge/storage-picking-list.md` and the direct route is `tech.storage.picking.docs`.

---

## Storage Item Form Field Guide

The item create/edit form is split into three working cards.

### General

* **SKU** is the internal item number. It is stored uppercase and should be stable because ticket costs, reservations, and future purchase orders refer to it.
* **Name** is the human-readable product name.
* **Warehouse** is required and tells Storage where the item belongs.
* **Box** is optional. If selected, the item is grouped inside that box.
* **EAN / Barcode** is used for product identification and later scan workflows.
* **Status** controls whether the item is active in normal lists and selection flows.
* **Short Description** is operational customer-facing text. Ticket cost lines can use it as invoice text when the item is reserved on a ticket.
* **Long Description** is internal catalogue detail for technicians.

### Vendor & Supplier

* **Vendor / Manufacturer** is who makes the product, such as HP, Lenovo, Dell, Ubiquiti, or Microsoft.
* **New vendor** opens the Documentation-owned vendor form in a new tab. Create the vendor there when it is missing, then return to the item form and select it.
* **Manufacturer Part No.** is the manufacturer model or part number.
* **Supplier** is where we buy the item, such as Dustin, Komplett, ALSO, or a local distributor.
* **New supplier** opens the Documentation-owned supplier form in a new tab.
* **Supplier SKU** is the supplier's item number. It can differ from our SKU and from the manufacturer part number.
* **Purchase URL** is the direct ordering/product page for the selected supplier.
* **Currency** is the currency used for purchase price. Default is `NOK`.
* **Lead Time** is expected delivery time in days from ordering until the item normally arrives.
* **Supplier MOQ** is the supplier's minimum order quantity. If the supplier requires packs of 5, set this to `5`.
* **Pack Size** is how many units come in one pack, bundle, or box from the supplier.

### Stock & Pricing

* **Initial Quantity** is only used when creating an item. It creates an immutable stock movement so the first stock count is auditable.
* **Reorder Point** is the quantity where the item should be considered for reorder.
* **Target Level** is the desired stock quantity after reordering.
* **MOQ** is the internal minimum order quantity used for reorder suggestions. If supplier MOQ is enough, keep this aligned with supplier MOQ.
* **Purchase Price** is the normal cost price excluding VAT. This is also copied to the primary supplier line as unit cost so supplier cost is not entered twice.
* **Markup %** is the percentage used when calculating or reviewing sale price.
* **Sale Price** is the price charged onward before VAT.
* **VAT Rate** defaults from Admin > Economy settings. A specific item can override it when needed.
* **Require serials on withdrawal/sale** means technicians must record serial numbers when stock is consumed.
* **Manual should-order flag** forces the item into reorder attention even when the normal quantity rules have not triggered.

---

## Stock States & Rules

* **Available math:** `available = total_on_hand − reserved` (never negative; over‑reservation is allowed but flagged).
* **Shortage detection:** item rows flagged when `available ≤ 0` or `reserved > total_on_hand`.
* **Reorder visibility:** the inventory list defaults to `Should order`, which includes manually flagged items, out-of-stock items, over-reserved items, and items at or below reorder point.
* **Serial/batch:** if `track_serial` → reserve by unit; if `track_batch` → reserve by batch with FEFO (first‑expiry‑first‑out) suggestion.
* **Expiry:** FEFO recommendations for pick/issue; warning on receive if expiry in past or inside configurable window.
* **Loan/Issue:** manual withdrawals and loans supported; loans require due_date and optional associated ticket.

---

## UX Layout (Bootstrap)

Static layout pattern across views: **Top header** / **Main content** / **Right slim rail**.
Suggested icons: warehouse, map-pin, box, package, qr-code, barcode, truck, rotate-ccw (returns), shuffle (relocate), alert-triangle (shortage), file-text (PO), check-circle (received), layers (containers).

Reusable widgets/components:

* **Stock Summary Card** (available / reserved / total).
* **Shortage List** (global filterable table).
* **Reservations Panel** (with source links).
* **Location Breadcrumb** (Warehouse → Room → Container).
* **Movement Timeline** (audit view).
* **Scan Input** (barcode/EAN field with keyboard focus).
* **Put‑Away Helper** (suggest target locations).

---

## Permissions (align with PSA taxonomy)

* `storage.view` — read warehouse data, shortages, movements.
* `storage.edit` — receive, relocate, adjust, manual issue/loan/return.
* `storage.audit` — access full movement history and exports.
* `storage.admin` — manage warehouses/rooms/containers/items/vendors.
* `storage.purchase.view` — view POs.
* `storage.purchase.edit` — create/edit/send POs, close/cancel.

> Tie into existing roles: technicians get `storage.view`; tech.admin gets all storage.*; sales may get `storage.view` limited to availability.

## Inventory Administration

Warehouse creation is owned by the admin inventory settings page:

* Route: `tech.admin.settings.storage.inventory`
* Controller: `App\Modules\Storage\Controllers\Admin\InventoryController`
* View: `storage::Admin.Inventory.index`

The daily `tech.storage.index` inventory view should stay focused on stock triage. `New Item` and
`New Box` actions belong in the `Inventory Items` card header, while `Add Warehouse` belongs in the
admin inventory settings card.

Inventory items can be filtered by primary supplier so reorder work can be grouped by where parts
are purchased. The Storage item create/edit forms capture both `Vendor / Manufacturer` and
`Supplier` details at the same time, but vendor/supplier master data is created and maintained in
Documentation so Assets, Commercial, Storage, and future purchase ordering all use one register.

---

(remaining content identical to previous version: Operations & Flows, Alerts & Lists, Integrations, Audit, Real‑Time, Controller Map, and Developer notes remain unchanged.)
