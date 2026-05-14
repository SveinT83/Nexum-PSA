# tech.storage.items.create — View Specification (Create/Edit Item)

**Creation date:** 2025-10-29
**URLs:**

* `tech.storage.items.create`
* `tech.storage.items.edit:{itemId}` *(same view file; mode toggled by controller)*
  **Access:** `storage.items.manage` (create/edit), `storage.view` (read-only preview)
  **Controller:** `App\Http\Controllers\Tech\Storage\Items\CreateEditController@show`
  **Store/Update:** `App\Http\Controllers\Tech\Storage\Items\CreateEditController@store|update`
  **Livewire/Components:** `App\Livewire\Tech\Storage\Items\CreateEdit\*`
  **Status:** Not started
  **Difficulty:** Medium
  **Estimated time:** 6.0 hours

---

## Purpose

Create and edit inventory items with mandatory placement (warehouse + optional box), multi-vendor pricing, manual/automated pricing utilities, asset conversion settings, reorder logic, and attachments. Ensures predictable stock handling, traceability, and readiness for purchasing and sales modules.

---

## Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Design:** Static structure, dynamic data; responsive for desktop and mobile (PWA friendly).

---

## Reusable Components & Widgets

* **HeaderBar**: title, breadcrumb, mode badge (Create/Edit), actions.
* **FormSection** (collapsible): General, Placement, Vendors, Pricing, Stock & Reorder, Asset Settings, Attachments, Advanced.
* **SearchableDropdown**: warehouse selector, box selector, category, vendor, manufacturer.
* **InlineCreateLink**: “Create new box” → opens new tab `tech.storage.boxes.create`.
* **VendorGrid**: table of vendor lines with add/remove, primary toggle, in-row validation.
* **MoneyInput**: currency-aware numeric inputs.
* **FilePicker**: image (single), documents (PDF, multiple).
* **AuditFooter**: created/updated by, timestamps.
* **RightRail** widgets: Validation summary, Stock flags preview (order-needed), Activity log preview.

Suggested icons: save, save-check, ban (cancel), layers (warehouse), package (box), truck (lead-time), tag (SKU/MPN), building (vendor), barcode (EAN), paperclip (attachments), shield (warranty), wrench (asset), bell (reorder).

---

## Actions (Header)

* **Save**
* **Save & Close**
* **Cancel** (back to `tech.storage.index`)
* **Duplicate** (edit mode only)
* **Adjust Price From Primary** (opens confirmation modal; recalculates selling price based on latest primary vendor cost and rules)

---

## Form — Field Structure & Rules

### 1) General

* **Name** *(required)*
* **Internal ID** *(read-only)*: database `id` used as internal SKU; default start number configurable in settings.
* **EAN/UPC** *(optional)*
* **Manufacturer** *(optional; selector)*
* **MPN** *(optional; manufacturer part number)*
* **Category** *(optional)*: e.g., PC, Mobile, Electronics, or None.
* **Tax/VAT handling**: VAT is global (Economy settings). Item displays **effective VAT** (read-only) with ability to **inherit zero/alt VAT from vendor** when applicable (e.g., marketplaces with no VAT).

### 2) Placement (Mandatory)

* **Warehouse** *(required; dropdown)*
* **Box** *(optional; dropdown)* with **link**: *Create new box* → opens `tech.storage.boxes.create` in a new tab.

### 3) Vendors (Multiple; one Primary)

Vendor lines in a grid:

* **Vendor** *(required per line)*
* **Vendor SKU/Part No.**
* **Currency**
* **Unit Cost** *(required)*
* **MOQ** (minimum order qty)
* **Pack Size** (purchase pack)
* **Lead Time** (days)
* **Valid From / To** (optional pricing windows)
* **Is Primary** *(exactly one must be primary)*
* **Vendor VAT policy** (can be "No VAT"/special) influencing effective VAT for purchases.

**Behaviors**:

* Primary required when saving.
* Price indexing supported during purchase receiving to update vendor cost history.

### 4) Pricing (Selling)

* **Selling Price** *(required; manual)*
* **Adjust From Primary** *(button)*: opens modal → preview new price from primary cost with margin/rounding rules (from settings); user can apply or cancel. Selling price remains manual unless applied.

### 5) Stock & Reorder

* **Initial Quantity** *(create only; optional numeric)* → saving creates a **stock log event** of type "Manual Entry" (who/when/qty).
* **Reorder Threshold** *(optional)*: if on-hand ≤ threshold → mark **Should order**.
* **Target Level** *(optional)*
* **Default Purchase Pack Size** *(optional)*
* **Flags Preview**: shows if item would appear in "Should order" list based on current/entered values.

### 6) Asset Settings

* **Becomes Asset on client-linked withdrawal/sale** *(toggle)*
* **Require Serial Number on withdrawal/sale** *(toggle)*: forces one serial per picked unit.
* **Default Warranty (months)** *(optional)*: inherits from **Vendor** or **Manufacturer** if present; item-level override allowed.
* **Default Asset Category** *(optional)*

### 7) Attachments

* **Product Image** *(single)*
* **Datasheet/Manual (PDF)** *(multiple)*

### 8) Advanced

* **Notes (internal)**
* **Custom attributes (key=value)** *(optional; for future integrations)*

---

## Validation & Business Rules

* Warehouse is **mandatory**; box optional.
* At least one **vendor line**; exactly one **Primary**.
* Selling price **required**; remains **manual** unless user applies the adjustment from Primary.
* If **Require Serial** = true: enforce serial capture during reservation/pick/sale.
* Saving with **Initial Quantity** logs a stock **inbound** event ("Manual Entry").
* "Should order" flag if: on-hand = 0 **OR** reserved ≥ on-hand **OR** item explicitly marked *should order* **OR** on-hand ≤ threshold.

---

## Right Rail (Slim)

* **Validation summary** (sticky)
* **Stock status preview**: On-hand, Reserved, Available, Order-needed?
* **Recent activity**: last 5 stock log entries for this item (edit mode).

---

## Controller Notes

* Mode toggle by route (create vs edit).
* Provide lists: warehouses (with location names), boxes (filtered by selected warehouse), categories, vendors, manufacturers, currencies.
* Persist vendor lines atomically; ensure exactly one primary.
* On create with initial quantity: write stock log (type, qty, user, warehouse/box, note).
* Expose derived flags to the view (shouldOrder, effectiveVAT).
* Handle **Adjust From Primary** flow via modal endpoint (reads latest primary cost + settings, returns proposed price; only writes on confirm).
* Emit events for logging/audit (created, updated, price-adjusted, initial-stock-added).

---

## Permissions

* `storage.items.manage`: access to create/edit, price adjust, vendor management.
* `storage.view`: can open view read-only (no save actions).
* Box creation uses `storage.boxes.manage` in `tech.storage.boxes.create` (separate tab).

---

## Telemetry & Logging

* All create/update actions are logged (old→new diffs).
* Stock manual entry generates stock log with immutable audit fields.
* Price indexing during receiving updates vendor price history table.

---

## QA Checklist

* Create item with mandatory warehouse only.
* Add two vendors and mark exactly one as primary.
* Apply “Adjust From Primary” and verify selling price change only after confirm.
* Require-serial toggle enforces serial capture on pick.
* Initial quantity creates stock log.
* Reorder flags appear on `tech.storage.index`.
