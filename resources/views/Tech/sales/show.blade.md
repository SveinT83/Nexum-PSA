# tech.sales.show — View Specification (Order Ticket)

**Creation date:** 2025-10-28
**URL:** `tech.sales.show:{ticketId}`
**Access:** `sales.view`, `ticket.view`, `sales.manage` (edit/order-line changes), `ticket.reply`, `ticket.internal.note`
**Controller:** `App\Http\Controllers\Tech\Sales\ShowController@show`
**Livewire/Components:** `App\Livewire\Tech\Sales\Show\*`
**Status:** In progress
**Difficulty:** Medium–High
**Estimated time:** 8.0 hours

---

## Purpose

A dedicated “order-type ticket” view that blends **sales order handling** (quote → pick → ship → invoice) with **ticket communications** (customer replies, internal notes, rule/workflow triggers). The order is a **Ticket of type `order`** with additional sales widgets and actions.

---

## Layout (Bootstrap)

**Regions:**

* **Top header** (breadcrumbs, title, primary actions)
* **Main section** (order lines, communications, activity log)
* **Right slim rail** (key cards: Quote, Shipments, Order Details, Billing, Assignment)

**Reusable components:**

* Page header bar (title, status badge, actions)
* Tabs/anchors (Order, Messages, Activity)
* Cards: Details, Quote, Shipments, Billing, SLA/Workflow, Assignee
* Data table: order lines (editable with inline validation)
* Modal manager: send/del-send, shipping detail, confirm transitions, attachments
* Toast/alerts, sticky action bar, audit log list

**Suggested icons:** shopping-bag, file-text, send, truck, package, barcode, receipt, clock, user, workflow, lock, refresh-ccw, shield, bell

---

## Core Concepts & Defaults

* **Ticket type:** `order` (queue may further specialize, e.g., `Order Private`, `Order Business`).

* **Workflow-driven:** Status changes and task creation follow Workflow JSON; **fallbacks** in Settings are used when workflow is absent.

* **Settings-driven defaults:**

  * Quote expiry: **7 days** (configurable)
  * Auto reminders: on/off, interval (days), max count
  * Partial vs consolidated invoicing (default: **per partial shipment**)
  * PDF templates under `admin.templates` (quote documents)

* **Lifecycle (high level):**

  1. Draft → Quote Sent → (Reminders) → Quote Accepted/Rejected/Expired
  2. Order Accepted *(fallback)* → Pick → Ready for Dispatch → Sent → Delivered → Closed

* **Invoice locking:** Billing draft is created **at shipment** (per partial by default) and **locked at Delivered** (or earlier if defined in workflow).

* **Stock reservation policy (default):**

  * On **Quote Created**: reserve items (*Reserved for Quote*).
  * On **Quote Rejected/Expired**: release reservation.
  * On **Quote Accepted**: upgrade reservation to *Reserved for Order*.
  * On **Pick completed**: decrement stock (*Picked*).
  * On **Send**: mark *Dispatched*.

* **Universal Tasks:** On Quote Accepted, create **task “Pick items”** with child tasks **“Pack items”** and **“Deliver to carrier”**. Completing child tasks can auto-advance order status via workflow.

---

## Page — Top Header

**Shows:**

* Ticket/Order title (e.g., `Order #12345`), status badge
* Customer, Site, Contact (clickable chips)
* Primary actions:

  * **Send / Partial Send** (dropdown)

    * Send entire order
    * Create partial shipment
  * **Send Quote**

    * Send (PDF + portal link)
    * Resend / Reminder
  * **Mark Quote Accepted** (manual) / **Mark Rejected**
  * **Close** (if delivered & billed)

**Smart UX:** disable/enable actions based on status and permissions; show confirm modals; sticky on scroll.

---

## Main — A) Order Lines (Editable)

* Data table with columns: SKU, Description, Qty, Unit price, Discount, Tax code, Line total, Stock status
* Inline edit rules:

  * Before **Quote Sent**: free edit
  * After **Quote Sent**: edit → **creates new Quote version** and returns to **Draft** (workflow may override)
  * Lines included in a **sent/packed/shipped** shipment are **locked** for those quantities
* Totals section: subtotal, tax/VAT, grand total (with currency), reserved/available indicators
* Actions: Add item, Remove item, Apply discount, Recalculate

---

## Main — B) Messages (Customer & Internal)

* **Reply to customer** / **Internal note** toggle
* Attachments, templates/snippets, CC/BCC, From-account selection
* Full email threading; replies can trigger **Rules/Workflow** (e.g., keyword-based acceptance)
* Outbound: PDF quote attached + portal link when sending quotes

---

## Main — C) Activity

* System and user events: quote sent/reminded/accepted, version created, tasks created/completed, shipments created, invoice drafts created/locked, rules/workflow actions
* Minimal quote-history detail (no separate tab): “Quote v2 created by … (previous archived)”

---

## Right Slim Rail — Cards

### 1) Quote Card

* Status: Draft / Sent / Reminded / Accepted / Rejected / Expired
* Expiry date (default 7 days) and reminder plan (settings-driven)
* Buttons: Send Quote, Send Reminder, Mark Accepted, Mark Rejected
* Template: shows current **PDF template** name; link: “Preview PDF”

### 2) Shipments Card

* List of shipments (supports **partial shipments**) with: carrier, service, tracking, sent date, ETA, status
* Action: **+ Shipment** (opens the same modal as header action)
* Each shipment links to its details (read-only summary)

### 3) Order Details Card

* Queue, Priority, Tags, Created/Updated timestamps
* Assigned Tech/Team (assign/change)
* Workflow name (with link to JSON view), Current step

### 4) Billing Card

* Billing mode: **Per partial shipment** (default) or Consolidated
* Invoice drafts with status (Draft / Ready / Locked)
* VAT summary
* “Open in Billing” action

### 5) Customer Card

* Client, Site, Contact, Phone, Email
* Portal status: link to customer view of quote/order

---

## Actions & Modals

### Send / Partial Send (Dropdown)

* **Send entire order** → opens **Shipping modal** with all items preselected
* **Create partial shipment** → two-step modal

  1. Select lines & quantities
  2. Shipping details

### Shipping Modal — Fields

* Carrier (dropdown)
* Service level (dropdown)
* Tracking number (optional)
* Sent date, Estimated delivery
* Warehouse (dropdown)
* **Items & quantities** included
* Shipping cost (for billing)
* Customer note (portal-visible)
* Internal note (internal only)

**Result:**

* Create shipment entity + **shipment card** entry
* Update ticket status: **Partially Sent** or **Sent**
* Create/update **invoice draft** for shipped items (per settings)

### Quote Actions

* **Send Quote**: generate PDF from template, attach to email, include portal link
* **Reminder**: respects settings (interval & max count); logs **Quote Reminded**
* **Mark Accepted**/**Mark Rejected**: manual overrides (workflow may also set these)

---

## Rule/Workflow Integration

* **Ticket Rules** can react to incoming replies (e.g., acceptance keywords) and set statuses or enqueue workflows
* **Workflow** (JSON) governs task creation, stock transitions, auto-status, and billing triggers
* **Fallback defaults** in Settings when no workflow is found:
  Order Confirmed → Ready for Processing → Sent → Delivered → Closed

---

## Validation & Locking

* Prevent edits on shipped quantities and on **Locked** invoice drafts
* Lock invoice when status **Delivered** (or earlier per workflow)
* Prevent sending if no reservable stock (unless overrides allowed by role)

---

## Logging & Audit

* Every action (edits, sends, reminders, status changes, reservations, picks, shipments, billing) is logged with timestamp, actor, and payload snapshot

---

## Permissions

* `sales.view`: view order tickets
* `sales.manage`: edit order lines, send quotes, shipments, billing
* `ticket.reply`: reply to customer
* `ticket.internal.note`: add internal notes
* `workflow.apply`: run/override workflow steps
* `billing.manage`: open/confirm invoice drafts

---

## Smart UX Suggestions

* **Sticky totals & action bar** when scrolling long line lists
* **Inline stock badges** (Reserved/Pending/Picked)
* **Conflict guard**: if edits occur after Quote Sent → prompt that a new version will be created
* **Autosave** for edits in Draft state
* **Keyboard shortcuts** for common actions (send quote, add line)

---

## Test Scenarios (Happy path)

1. Create draft → Send quote → Customer accepts → Tasks auto-created → Pick completes → Partial send #1 → Invoice draft #1 → Final send → Delivered → Invoice locks → Close
2. Send quote → Edit lines → New version created → Resend → Accept → Full send → Delivered → Lock & Close

---

## Non-Goals

* Custom HTML templates (handled separately under `admin.templates`)
* Deep quote version browsing (activity log only)

---

## Open Settings References

* `tech.admin.settings.sales`: quote expiry, reminders, billing mode, default workflow fallback
* `admin.templates`: PDF template definitions
* `tech.admin.settings.ticket.rules` & `tech.admin.settings.ticket.workflow`: rule/workflow governance
