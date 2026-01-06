# tech.storage.items.show — View Specification (Item Detail)

**Creation date:** 2025-10-29
**URL:** `tech.storage.items.show:{itemId}`
**Access:** `storage.item.view` (base), `storage.item.move`, `storage.item.withdraw`, `storage.item.intake`, `storage.item.adjust`
**Controller:** `App\Http\Controllers\Tech\Storage\Items\ShowController@show`
**Livewire/Components:** `App\Livewire\Tech\Storage\Items\Show\*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 6.0 hours

---

## Purpose

Single-item detail view used by technicians and store managers to **inspect stock balances**, **move** items between **boxes/warehouses**, and to register **withdrawals** and **intakes**. Sales-related partial shipments and backorders are handled by the Sales module; this view focuses on **actual on-hand operations** and accurate audit trails.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.

**Suggested icons:**

* Item info (tag, package)
* Move (arrow-left-right)
* Withdraw (minus-circle)
* Intake (plus-circle)
* History (clock)
* Reservations (bookmark)
* Loans (user-check)
* Purchases/PO (truck)
* Audit (list-check)
* Edit/Adjust (wrench)

---

## Top Header

* **Title:** `Item #<internalId> — <Name>`
* **Meta chips:**

  * Warehouse (pill) • Box (pill or "none")
  * Status: *On-hand*, *Reserved*, *Loaned*, *On order*
* **Primary actions (buttons):** `Move to Box`, `Move to Warehouse`, `Withdraw`, `Intake`
* **Secondary actions:** `Adjust` (admin only), `Edit Item`

---

## Main Content

### A) Stock Summary (read-only, live-updating)

Fields displayed prominently:

* **On-hand (physical):** integer (never negative)
* **Reserved (sales/tickets):** integer (may exceed on-hand)
* **Loaned (internal):** integer
* **On the way (purchases/returns):** integer
* **Available = On-hand – Reserved – Loaned**
* Optional badges for **Min level / Reorder flag**

### B) Movements & References (tabs)

1. **History** — Chronological movements (withdraw/intake/move/adjust) with user, timestamp, qty, comment, refs.
2. **Reservations** — Open order lines referencing this item (draft/open). Read-only links to Sales.
3. **Loans** — Active loans with assignee, quantities, due dates, and quick-return shortcuts.
4. **Purchases (PO)** — Open purchase orders with expected quantities and ETA.

---

## Right Slim Rail

* **Item Quick Facts:** SKU, EAN, Vendor, Cost/Markup, Track serial numbers (yes/no), Default warehouse/box, Reorder rules.
* **Shortcuts:** `Open Vendor`, `Open PO`, `Open Sales Order` (when referenced), `Print label`.

---

## Actions & Modals

### 1) Move to Box — Modal

* **Inputs:** Target box (dropdown scoped to current warehouse) or `Remove from box`.
* **Rules:**

  * Allowed even when there are open reservations/loans; references remain valid (re-bound to new box automatically).
  * If serial tracking = yes, no serial prompt (no qty change).
* **Result:** Movement entry (+ audit).

### 2) Move to Warehouse — Modal

* **Inputs:** Target warehouse (dropdown), optional target box (optional, dependent on chosen warehouse).
* **Rules:**

  * Allowed even with open reservations/loans; system re-binds reservations to the **new warehouse**. Loans remain with the item and inherit the new warehouse context.
  * If item is currently boxed and target warehouse lacks that box, prompt: `Remove from box` or `Select destination box`.
* **Result:** Movement entry (+ audit).

### 3) Withdraw — Modal

* **Inputs:**

  * **Quantity** (max = current **On-hand**; cannot exceed; no negative on-hand)
  * **Purpose:** `Internal use` / `Loan` / `For client`

    * **Loan:** select technician, optional due date.
    * **For client:** select client & contact → creates **Sales Order (draft)** with picked quantity. Sales permissions not required for the withdrawing user.
  * **Comment** (required for audit best practice)
  * **Serials** (if serial-tracked): prompt N inputs equal to quantity.
* **Rules:**

  * **Picking limited to actual On-hand.**
  * Reservations may exceed On-hand, but withdrawing never does.
  * Partial/backorder logic lives in Sales (this view does not create backorders/PO automatically).
* **Result:**

  * Movement entry, linkage to Loan or Sales Order when applicable, decrement **On-hand**, and if `Loan`, increment **Loaned**.

### 4) Intake — Modal

* **Inputs:**

  * **Source:** `Return from loan` / `Purchase (PO)` / `Other`
  * **Quantity** (defaulted when source implies known expected qty)
  * **PO Link** (when `Purchase`), **Loan Link** (when `Return`)
  * **Comment**
  * **Serials** if required
* **Rules:**

  * Loan return: pre-fill outstanding loan quantities per technician; support partial returns.
  * Purchase: show open POs for this item; pre-fill expected inbound qty; allow partial receipt.
* **Result:** Increase **On-hand**; decrease **Loaned** or **On the way** accordingly; movement + audit.

### 5) Adjust — Modal (Admin only)

* **Inputs:** Quantity delta (+/-), reason code (inventory correction, damage, shrink), comment.
* **Rules:** Cannot set **On-hand** negative.

---

## Validation & Edge Cases

* **Never allow negative On-hand.**
* **Withdraw quantity ≤ On-hand** at confirm time (re-check on submit in case of race conditions).
* **Concurrency:** Use server-side revalidation and optimistic UI with rollback messages.
* **Serial tracking:** Require exact count of serials matching the movement quantity; prevent duplicates.
* **Moves with open references:** maintain referential integrity; re-bind reservations to the new warehouse.

---

## Events & Logging

* Emit domain events: `ItemMoved`, `ItemWithdrawn`, `ItemIntaken`, `ItemAdjusted`.
* Persist audit log: user, action, qty, purpose/source, references (loan#, order#, po#), comment, timestamps, from/to (warehouse/box).

---

## Permissions & Roles

* Gate checks per action button; hide/disable unauthorized actions.
* Read-only users see balances, history, and references but no action buttons.

---

## Smart UX

* **Live counters** (websockets) for balances.
* **One-click loan return** from Loans tab (prefilled intake modal).
* **PO arrival hint**: when ETA ≤ N days, surface a banner in the header.
* **Reorder nudge**: show `Should reorder` chip when rules match.

---

## Reusable Components (mark for library)

* `BalanceSummaryCard`
* `MovementHistoryList`
* `ReservationsList`
* `LoansList`
* `POList`
* `MoveModal`, `WithdrawModal`, `IntakeModal`, `AdjustModal`
* `SerialInputGrid`

---

## Data Contracts (inputs/outputs)

* **GET show:** item core fields, balances (`on_hand`, `reserved`, `loaned`, `on_way`), warehouse & box, vendor & pricing, serial flag.
* **POST move:** `{ item_id, to_warehouse_id, to_box_id? }`
* **POST withdraw:** `{ item_id, qty, purpose, client_id?, contact_id?, technician_id?, due_at?, comment, serials?[] }`
* **POST intake:** `{ item_id, qty, source, po_id?, loan_id?, comment, serials?[] }`
* **POST adjust:** `{ item_id, delta, reason, comment }`

---

## Testing Checklist (high-level)

* Block withdraw > on-hand; allow reserve > on-hand (read-only).
* Move with open reservations/loans correctly re-binds references.
* Loan return updates `loaned` and `on_hand` accurately.
* Intake from PO decrements `on_way`.
* Serial tracking strictness on all movements.

---

## Notes

* Partial shipments/backorders, PO auto-suggest, and invoicing are controlled by **Sales** and **Purchasing** modules; this view only performs physical stock transactions and creates links (draft order, PO, loan) when necessary.
