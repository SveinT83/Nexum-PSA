# UI/UX Documentation – tech.admin.billing.index (Billing Overview & Invoice Basis)

**URL:** `/tech/admin/billing`
**Route:** `tech.admin.billing.index`
**Access / Permissions:** `billing.view` (read), `billing.manage` (draft & post), `billing.admin` (settings & destructive)
**Creation date:** 2025-11-06
**Controller (path):** `App/Http/Controllers/Tech/Admin/Billing/BillingController@index`
**Status:** In progress
**Difficulty:** High
**Estimated Time:** 7.5 hours

---

## Purpose

A centralized workspace for **collecting, reconciling and invoicing billable data** from Tickets, Contracts, Sales, and Timebanks.
The Billing module aggregates and manages `billing_items` rather than generating them. It allows recalculation, draft creation, posting, and credit handling — ensuring accurate invoicing without background cron dependencies.

---

## Core Functions

* Gather all `billing_items` with status `pending`.
* Allow recalculation per client, contract, or period.
* Build draft invoices from `pending` items → lock (`reserved`).
* Finalize and post → mark `invoiced`.
* Support credit notes and reversals.
* Provide full audit and locking logic.

---

## Data Lifecycle & States

| State                | Meaning                                      |
| -------------------- | -------------------------------------------- |
| **pending**          | Created by source module, awaiting invoicing |
| **reserved (draft)** | Included in a draft run (locked)             |
| **invoiced**         | Finalized and posted to accounting           |
| **credited / void**  | Reversed or cancelled line                   |
| **superseded**       | Replaced by updated data from recalculation  |

### Locking & Editing Rules

* **Draft lock:** When an item enters a draft, its source record (time entry, expense, etc.) becomes **read-only**.
* Editing a locked source shows: *“Locked by Billing Run {id}. Remove or delete the run to edit.”*
* Removing a line from a draft sets it back to **pending**.
* Posted items are permanently locked; only credit items can reverse them.
* **Badges:** *Locked (Draft)* / *Locked (Posted)*.

### Refresh Logic

* "Refresh from Sources" only affects **non-locked pending** items.
* Drafted items must be removed before they can be recalculated.
* Draft UI includes **Remove from run** quick-action.

---

## Layout (Bootstrap, no HTML)

### 1. Header Zone

* Title: **Billing**
* Filters: Period (current month/custom), Client(s), Status chips (Pending/Reserved/Invoiced), Include prior periods toggle.
* Actions:

  * **Recalculate Billing** (refreshes data)
  * **Build Drafts** (per client or consolidated)
  * **Post Invoices**
  * Badge: *Stale Drafts* when outdated

### 2. Main Table

* **Grouped by Client → expandable sections**
* Columns: Description, Qty, Unit, Price, Total, Status, Source Ref, Last Updated
* Inline totals per client and global footer total
* Row/Group Actions:

  * *Quick-select*: All Pending | Cap by Hours | Cap by Amount | Manual Pick
  * *Build Draft (Client)*
  * *Remove from Draft* (if reserved)
  * *Open Source* (ticket/order/timebank)

### 3. Right Panel

* **Client Summary:** totals (Pending/Reserved/Invoiced), timebank remaining, reconciliation timestamp.
* **Billing Runs:** clickable list with status badges (Draft, Posted, Voided).
* **Actions on selected run:** *Refresh from Sources*, *Edit Draft*, *Post*, *Void*.
* Shortcuts: *Billing Settings*, *Export Settings*.

---

## Behaviors

* **Recalculate Billing** → runs reconciliation service → updates/supersedes items.
* **Build Drafts** → locks items → sets `status=reserved`, creates/updates run.
* **Post Invoices** → validates → sets `status=invoiced` and finalizes.
* **Previous Runs** → stored as immutable summaries; drafts editable until posted.

### Quick-select Logic

* *All pending*: select all pending lines for client.
* *Cap by hours/amount*: numeric inputs limit selection.
* *Manual pick*: toggles checkbox column.

### Validation

* Clients missing billing profiles (address, tax, etc.) block posting with clear error.
* Empty state: *Nothing to bill for this period* → CTA **Recalculate**.

---

## Components & Icons

* Shared components: FilterBar, GroupedDataTable, TotalsCard, RunListWidget, DraftDrawer, ConfirmModal.
* Icons: calculator (recalculate), file-plus (build), send (post), refresh-ccw (refresh), lock, check-circle, history, filter, calendar.

---

## Logging & Audit

Every reconciliation, draft build, and posting logs:

* Triggered by (user/system)
* Scope (client/contract/period)
* Item count, timestamps, and summary.

Example log entry: *Recalculated 412 items (10 superseded, 0 errors)*.

---

## Permissions

| Permission       | Action                                                     |
| ---------------- | ---------------------------------------------------------- |
| `billing.view`   | View billing items and runs                                |
| `billing.manage` | Recalculate, build drafts, edit draft text                 |
| `billing.admin`  | Override values, post invoices, void drafts, open settings |

---

## Developer Notes

* Keep controller thin; logic in BillingService + ReconciliationJob.
* Draft model is separate from Invoice model.
* Idempotency key: `(client_id, period, draft_kind)`.
* Use database transactions for draft build/post operations.
* Ensure audit trail links to affected billing_items + source records.

---

## Future Extensions

* Tax/VAT calculation.
* Multi‑currency support.
* Advanced credit workflows.
* Bulk export to accounting integrations.
