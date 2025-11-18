# tech.storage* — General Documentation (Warehouse & Inventory)

**Creation date:** 2025-10-29
**Status:** In progress
**Difficulty:** Medium–High
**Estimated time:** 9.0 hours

---

## Purpose & Scope

Provide a modular inventory system for internal use first, later multi‑tenant. Focus on predictable stock control, reservations from Sales Orders, clear shortage visibility, audited movements, and simple receiving/put‑away. Picking is orchestrated by workflows outside this module.

---

## View Structure (Blade files)

```
resources/views/tech/storage/
 ├── boxes/
 │   ├── create.blade.php   ← used for both create & edit
 │   └── show.blade.php
 ├── items/
 │   ├── create.blade.php   ← used for both create & edit
 │   └── show.blade.php
 └── storage.md
```

Each view follows standard PSA layout: **Top header / Main content / Right slim rail**.

---

## Core Concepts (Data & Logic)

* **Location (from Admin)** → locations are managed in `tech.admin.locations*`. A location becomes a **Warehouse** for storage when `is_warehouse = true`. Storage **does not** create addresses; it only references enabled locations.

  * Fields (owned by Admin module): name, address (street, postal code, city, country), timezone, notes, is_active, **is_warehouse**.
* **Room** → zone/area within a warehouse (owned by Storage).

  * Fields: name, code, notes, is_active, location_id.
* **Container** → box/pallet/bin with unique code, can be moved as a unit between rooms.

  * Fields: code, label, type (box/pallet/bin), location_id, room_id, status (in_place/in_transit), notes.
* **Item (SKU)** → catalog item that can be stocked and sold.

  * Fields: sku, name, description, uom, ean/barcode, track_serial (bool), track_batch (bool), expiry_enabled (bool), min_stock, vendor_id (primary), cost_price, markup_percent, sell_price (derived/override), tax_code.
* **Stock Unit** → atomic stock record for serial/batch tracked goods.

  * Fields: item_id, serial_no, batch_no, expiry_date, location_id, room_id, container_id, status (available/reserved/loaned/damaged), current_qty (usually 1 for serial-tracked), audit refs.
* **On-Hand (Aggregate)** → per item & location totals.

  * Computed: total_stock, reserved_stock, available_stock (= total − reserved).
* **Reservation** → holds qty against an order/ticket without removing from total_stock.

  * Fields: item_id, qty, source_type (sales_order/ticket/manual), source_id, soft_or_hard (default hard), created_by, created_at.
* **Movement Log** (immutable) → every stock change.

  * Types: receive, relocate, reserve, unreserve, issue, ship, return, adjust, loan_out, loan_in, damage, audit_correction.
  * Each row stores before/after **location/room/container** + qty/serial/batch + actor + reason.
* **Vendor** → supplier master.

  * Fields: name, vendor_code, contact info, default lead time, terms.
* **Purchase Order (PO)** → request to vendor.

  * Header: vendor_id, **deliver_to_location_id** (from Admin locations), status (draft/sent/partial/received/closed/cancelled), vendor_ref, tracking_no, documents (order confirmation).
  * Lines: item_id, qty_ordered, unit_cost, tax, expected_date, qty_received.

---

## Stock States & Rules

* **Available math:** `available = total_on_hand − reserved` (never negative; over‑reservation is allowed but flagged).
* **Shortage detection:** item rows flagged when `available ≤ 0` or `reserved > total_on_hand`.
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

---

(remaining content identical to previous version: Operations & Flows, Alerts & Lists, Integrations, Audit, Real‑Time, Controller Map, and Developer notes remain unchanged.)
