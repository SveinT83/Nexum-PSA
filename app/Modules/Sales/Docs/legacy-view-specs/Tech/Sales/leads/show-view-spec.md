# tech.sales.leads.show — View Specification (Lead)

**Creation date:** 2025-10-29
**URL:** `tech.sales.leads.show:{leadId}`
**Access levels:** Read: `sales.view` • Write (edit notes/priority, create contract/order): `sales.manage`
**Controller:** `App\Http\Controllers\Tech\Sales\Leads\ShowController@show`
**Livewire/Components:** `App\Livewire\Tech\Sales\Leads\Show\*`
**Status:** Not started
**Difficulty:** Medium
**Estimated time:** 5.0 hours

---

## Purpose

A sales-focused lead detail view centered on a **client without an active contract**. Sellers can review context (notes, history, footprint), estimate priority, and jump to contract creation or a one-off sales order.

---

## Layout (Bootstrap)

**Template:** Top header / Main content / Right slim rail (static layout, dynamic content).

### Top header

* **Title:** Client name (lead) + badge “Lead”
* **Subline:** Industry • Size (users/assets) • Last outreach timestamp
* **Buttons:**

  * `Open Client` → navigates to `tech/clients/show.blade.php` (perm: `sales.view`)
  * `Create Contract` → route `tech.contracts.create` (perm: `sales.manage`)
  * `Create Order` → route `tech.sales.create` (perm: `sales.manage`)
* **Breadcrumbs:** Sales / Leads / {Client}

### Main content (left)

**A. Client Summary (widget: `LeadClientSummaryCard`)**

* Client basics: legal name, org no., primary site, primary contact
* Tags/Industry • Region • Account owner (sales rep)
* Quick chips: “No contract”, “Past customer” (if applicable)

**B. Footprint Metrics (widget: `FootprintStatRow`)**

* Cards: Sites • Users • Assets • Tickets (12m) • Orders (12m)
* Each card links to filtered list in respective modules

**C. Activity & History (widget: `LeadActivityTimeline`)**

* Timeline of prior touches: calls, emails, meetings, failed attempts
* Highlights: “Agreed to revisit” notes with target date badges
* Filter: All / Notes / Emails / Meetings / Tasks

**D. Notes (widget: `SalesNotesEditor`)**

* Rich text notes with @mention and file refs
* Inline add/edit, autosave, versioning
* Permissions: view (`sales.view`), edit (`sales.manage`)

**E. Priority & Fit (widget: `LeadPriorityPanel`)**

* Manual priority (0–100) slider + textual label (Low/Med/High)
* Fields: Estimated seats, potential MRR, pain points
* Optional computed helper (non-blocking): score suggestion from footprint (read-only)
* Action chips: `Mark Blocked` / `Ready to pitch` (stateful flags)

**F. Previous Contracts (widget: `ContractHistoryList`)**

* If the client had contracts before: show last status, end date, reason
* Quick link: open historical contract

**G. Related Tickets & Orders (widget: `RelatedWorkfeed`)**

* Recent tickets (last 6 months) and sales orders (last 12 months)
* Use badges for status; click opens the item in new view

### Right slim rail

**1. Lead Quick Facts**

* First seen, source, owner, last activity, next suggested action

**2. Upcoming touchpoints**

* Mini list of scheduled follow-ups (from tasks/calendar integration when available)

**3. Attachments**

* Uploaded files (proposals, discovery docs)

**4. Audit (read-only)**

* Created by, last modified by, key field changes

---

## Components & Reuse

* `LeadClientSummaryCard` (reusable across sales views)
* `FootprintStatRow` (stat tiles: count + link)
* `LeadActivityTimeline` (shared model with tickets’ timeline; filtered for sales)
* `SalesNotesEditor` (same surface as ticket internal notes)
* `LeadPriorityPanel` (slider + helper score)
* `ContractHistoryList` (compact history renderer)
* `RelatedWorkfeed` (mixed list of tickets & orders)

> Mark these in the design system as reusable widgets.

---

## Smart UX Suggestions

* **Autosave** for notes/priority with inline toasts
* **Keyboard actions:** “N” to add note, “C” create contract, “O” create order
* **Deep links**: each metric card opens filtered lists
* **State flags** shown as chips at top and editable via dropdown
* **Empty states** with guidance (e.g., “No history yet — add first note”)

---

## Data & Interactions

* **Read model:** Client core data, counts (sites/users/assets), recent tickets & orders, prior contracts, notes, activity log
* **Write actions (require `sales.manage`):**

  * Edit notes (create/update/delete)
  * Set manual priority & state flags
  * Navigate to create Contract/Order (no write here, only redirect)
* **Logging:** All edits produce entries in the audit trail (who/when/what)
* **Real-time:** Live updates for notes and timeline via websockets

---

## Buttons & Icons (suggested)

* Open Client (external-link)
* Create Contract (file-plus)
* Create Order (shopping-cart)
* Add Note (sticky-note)
* Filter (funnel) • Edit (pen) • Save (check) • Priority (gauge)

---

## Empty/Error States

* Lead not found → return to `tech.sales.leads.index` with alert
* Metrics unavailable → show placeholders with retry
* No prior contracts → informational helper text

---

## Controller Notes

* Resolve `{leadId}` → client entity (must be contractless)
* Eager-load: sites, users count, assets count, recent tickets/orders, prior contracts, notes, timeline
* Authorize: `sales.view` for show; gate mutations with `sales.manage`
* Provide lightweight endpoints for: notes CRUD, priority update, flag toggle (Livewire actions)

---

## Testing Checklist

* Permissions gating for view vs. manage actions
* Counts correct and links apply filters
* Notes autosave + audit entries
* Buttons navigate to correct create routes with client preselected
* Real-time updates reflect changes across sessions
