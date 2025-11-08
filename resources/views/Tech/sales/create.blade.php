# tech.sales.create — View Specification

**URL:** `tech.sales.create` → `/tech/sales/create`
**Access levels & permissions:**

* View/Create: `sales.create`
* Send quote/confirmation: `sales.send`
* Override prices/discounts/VAT: `sales.override`
* Change queue (sales/order): `ticket.queue.change`
* Read-only fallback: `tech.read`

**Creation date:** 2025-10-28
**Controller:** `App\Http\Controllers\Tech\Sales\SalesController@create`
**Store/Send actions:** `SalesController@store`, `SalesController@sendQuote`, `SalesController@sendConfirmation`
**Status:** In progress
**Difficulty:** Medium–High
**Estimated time:** 6.0 hours

---

## Purpose

Create a **ticket-backed order/sale**. Every record is a **Ticket** with items. Queue determines context:

* **`sales` queue:** quotes, contracts, agreements.
* **`order` queue:** physical goods/products.

PDF output and email sending originate **from the ticket thread** to preserve threading and audit.

---

## Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Design:** Static layout; dynamic content; real-time updates where applicable.

**Suggested icons:** file-plus, shopping-cart, receipt, percent, edit, lock, unlock, mail, send, calculator, printer, download, shield-check, triangle-alert.

---

## Top Header

* **Title:** "New Sale / Order"
* **Breadcrumbs:** Sales → Create
* **Primary actions (right-aligned):**

  * `Save` (draft)
  * `Save & Send Quote`
  * `Save & Send Confirmation`
  * `Cancel`
* **Status pill (live):** `Draft` | `Quote Sent` | `Confirmed` | `Processing` | `Invoiced` | `Cancelled`

---

## Main Content

### A) Context & Party Selection

If navigated from another form, **Client, Site, User** are prefilled and locked; otherwise required selectors.

* **Queue selector (guarded):** default from entry; values: `sales` or `order` (editable only with `ticket.queue.change`).
* **Client** (searchable select)
* **Site** (dependent select)
* **User/Contact** (dependent select)
* **Reference fields:** Customer PO, External Ref, Internal Ref (optional)

### B) Order Meta

* **Subject/Title** (text)
* **Custom order description** (rich textarea; shown on PDF)
* **Dates:** Quote valid until (default +14 days), Delivery date (optional)
* **Currency** (default system currency)

### C) Items Table (invoice-like)

Columns:

1. **SKU / Item** (picker from inventory; free-text fallback if permitted)
2. **Description** (editable)
3. **Qty** (numeric)
4. **Unit Price** (prefilled from inventory; **override** allowed with `sales.override`)
5. **Discount** (%, value or both; per-line)
6. **VAT** (from item; view-only by default; per-line override requires `sales.override`)
7. **Line Total** (auto)

Row actions: Add line, Duplicate, Remove, Drag to reorder.
Bulk: Clear discounts, Recalculate from catalog.
Validation: non-negative qty/price; discount ≤ 100%.

### D) Totals & Taxes (sticky footer inside Main)

* **Subtotal (excl. VAT)**
* **VAT total** (sum of per-line VAT)
* **Grand Total (incl. VAT)**
* **Notes to customer** (optional; prints below totals)
  Widgets: mini-calculator; rounding preview.

---

## Right Slim Rail

* **Send options**

  * Recipient(s): defaults to selected user + CC list
  * Email account: default from settings (per system or global)
  * PDF template: Quote / Order Confirmation
  * Message template preview (editable subject/body)
  * Checkbox: **Customer already confirmed** (skips quote; jumps to Confirmed)
  * Checkbox: **Do not send order confirmation** (if already confirmed and policy allows)
* **Policy hints (read-only):**

  * "Require customer confirmation" (on/off; from settings)
  * Allowed senders (accounts)
* **Audit preview:** will log to ticket timeline.

---

## Actions & State Transitions

* **Save** → `Draft`. All fields editable. Triggers create Ticket with set queue. Ticket rules may run post-create (configurable).
* **Save & Send Quote** → sets `Quote Sent`. Generates PDF (Quote), posts email from ticket, logs event. Editing after send creates **new version** on next send.
* **Save & Send Confirmation** → sets `Confirmed`. Generates PDF (Order Confirmation), sends email (unless suppressed by checkbox), logs event. Locks **items/prices/discounts**; allows edits to delivery fields and internal notes only.
* **Cancel** → if ticket not created: close modal/navigation; if created: set status `Cancelled`, lock send actions.
* **Auto-advance (optional via rules/settings):**

  * `Confirmed` → `Processing` when fulfillment starts
  * `Processing` → `Invoiced` when invoice reference attached

Locking rules:

* `Draft`: unlocked
* `Quote Sent`: soft-lock (warn on change; next send creates version n+1)
* `Confirmed`/`Processing`/`Invoiced`: items/pricing locked; metadata limited
* `Cancelled`: fully locked

---

## Settings Dependencies

* **Sales policy:** require customer confirmation (on/off)
* **Send order confirmation automatically** (on/off)
* **Default email accounts** per system and global fallback
* **PDF templates** (Quote, Order Confirmation)
* **Default quote validity days**

---

## Controller Notes

* Prefill context from route/query (client_id, site_id, user_id, queue) and lock if provided.
* Inventory lookup for SKU, price, and VAT (authoritative source).
* Totals calculation server-side; mirror client-side for UX.
* Create ticket first (queue = `sales` or `order`), then attach order payload.
* Email sending via ticket thread; persist message, attachments, and PDF artifact in ticket files.
* Versioning for quotes after first send.
* Full audit trail (who/when/what) + config change log entries.

---

## Widgets & Reusable Components

* **EntityPicker** (Client/Site/User)
* **QueuePill** (sales/order)
* **ItemRow** (SKU picker + fields)
* **TotalsBar** (sticky)
* **SendPanel** (right rail)
* **StatusPill** (header)
* **AuditToast** (post-action feedback)

---

## Validation & Errors

* Missing Client/Site/User → blocker with inline errors
* Price/discount/VAT inconsistencies → inline row errors
* Email send failure → non-blocking for Save; blocking for send actions with detailed error
* Permission checks on overrides and queue change

---

## Notes

* Multi-language later; English for now.
* PWA-friendly; keyboardable line entry.
* Real-time updates: calculate totals as user types; autosave optional but default is manual save here.
