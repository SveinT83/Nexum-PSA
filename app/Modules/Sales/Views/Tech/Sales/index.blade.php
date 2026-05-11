@extends('layouts.default_tech')

@section('title', 'Sales')

@section('pageHeader')
    <h1>Sales</h1>
@endsection

@section('content')
# tech.sales.index — View Specification

**URL:** `tech.sales.index` → `/tech/sales`

**Access & permissions:** `lead.view` (read), `lead.create` (New Order button), `sales.admin` (settings links hidden here)

**Creation date:** 2025-10-28

**Controller:** `App\Http\Controllers\Tech\Sales\OrdersController@index`

**Status:** Not started

**Difficulty:** Medium

**Estimated time:** 3.0 hours

**Layout template:** Top header / Main content / Right slim rail (Bootstrap)

---

## Purpose

List and operate on **sales orders lifecycle** (Quotes → Approved → Fulfillment → Invoiced → Archived). *Leads are not shown here* (handled in `tech.sales.leads.*`).

---

## Navigation & Tabs

* **Tabs (static):** Quotes (Draft/Sent), Approved, Fulfillment, Invoiced, Archived
* **Saved filter toggle:** *Mine only* (remembered per user in local storage)
* **Routing:** Row click opens `tech.sales.show:{id}` (full-page details)

---

## Filters & Search (top bar)

* **Search:** free text across *Order #, Title, Customer*
* **Selectors:** Customer, Owner/Assigned, Date range (Created/Updated), Value range, Status (when in *All* context)
* **Utilities:** Clear filters, Refresh, Auto-refresh indicator (“Updated X min ago”)

---

## List (Main table)

* **Columns (minimal set):** Order # · Customer · Title · Status · Value · Assigned To · Updated
* **Row affordances:**

  * Status **badge** with color coding (consistent with tickets)
  * Hover reveals quick actions (icons): View, Edit, Assign
  * Context menu (⋮): Send Quote, Approve, Move to Fulfillment, Mark as Invoiced, Archive, Delete
* **No bulk actions**
* **Row density:** compact, single-line (truncate long titles)

---

## Right Slim Rail (aux)

* **Widgets:**

  * *Quick Filters* (Mine only, Customer quick-pick)
  * *Shortcuts* (New Order, Go to Leads, Sales Settings)
  * *Help* (keyboard tips, status legend)

---

## Actions & Transitions (per row)

* **Send Quote** → opens send modal (select template, preview, recipient list) → audit log entry
* **Approve** → status → *Approved* (confirmation modal optional)
* **Move to Fulfillment** → status → *Fulfillment*
* **Mark as Invoiced** → status → *Invoiced*
* **Archive** → status → *Archived*
* **Assign** → choose user/team; show avatar chip in list
* **Delete** → guarded by confirmation; audited

> All actions are **single-item** only; no multi-select.

---

## Status & Color Coding (shared tokens)

* Quotes (Draft/Sent)
* Approved
* Fulfillment
* Invoiced
* Archived

> Use shared badge styles from ticket module to keep consistency (no custom colors defined here).

---

## Reusable Components (mark for library)

* **TabsWithCounts** (badges show counts per tab)
* **SavedFiltersToggle** (persist to local storage)
* **SearchBar** (with clear/reset)
* **StatusBadge** (shared with tickets)
* **RowActionsMenu** (icon set + labels)
* **AutoRefreshChip** (spinner + last-updated label)

---

## Icons (suggested — Lucide)

* Quotes: `file-text`
* Approved: `check-circle`
* Fulfillment: `truck` or `package`
* Invoiced: `receipt`
* Archived: `archive`
* Assign: `user-plus`
* Edit: `pencil`
* Delete: `trash-2`
* Refresh: `refresh-cw`

---

## Empty & Error States

* **Empty:** “No orders in this view.” Button: *New Order*
* **Filtered empty:** show applied filters with *Clear filters*
* **Error:** inline alert with retry; link to Sales Health (if available)

---

## Performance & UX Notes

* Paginate with infinite scroll or page controls (choose one; default: page controls)
* Remember last tab and filters per user (local storage)
* Real-time ready (optional WebSocket push → soft refresh)

---

## Related Views

* `tech.sales.leads.index` (leads pipeline)
* `tech.sales.leads_create` (lead create)
* `tech.sales.show` (order detail)
* `tech.sales.create` (new order)

---

## Security & Audit

* Actions require per-item permission checks
* All state changes (approve/fulfill/invoice/archive/delete) are logged in audit with actor, timestamp, and before/after status

---

## Notes for Controller/Presenter

* Provide tab-scoped datasets via query params: `?tab=quotes|approved|fulfillment|invoiced|archived`
* Apply server-side sorting: default by `updated_at` desc
* Support lightweight counts for tab badges (avoid N+1 by aggregate queries)
* Accept filters via query: `customer_id, owner_id, date_from, date_to, value_min, value_max, q`

---

## QA Checklist

* Tabs switch without losing filters unless explicitly cleared
* Actions enforce allowed transitions only
* Color/status badges match shared design
* Row click vs. action click do not conflict
* Local storage persists *Mine only* and last tab
@endsection

@section('sidebar')
    <h3>Left Sidebar</h3>
    <ul>
        <li><a href="#">System Status</a></li>
        <li><a href="#">Task Management</a></li>
        <li><a href="#">Reports</a></li>
    </ul>
@endsection

@section('rightbar')
    <h3>Right Sidebar</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection