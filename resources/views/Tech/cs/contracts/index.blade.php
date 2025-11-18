@extends('layouts.default_tech')

@section('title', 'Contracts')

@section('pageHeader')
    <h1>Contracts</h1>
@endsection

@section('content')
# tech.cs.contracts.index – View Specification

**Date:** 2025-10-17
**URL:** `tech.cs.contracts.index` → `/tech/cs/contracts`
**Access levels:** `contract.view`, `tech.view`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\CS\\ContractsController@index`
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 3.0 hours

---

## Purpose

Provide a fast, filterable overview of **all client contracts**, highlighting renewal risk, binding status, indexing state, and quick operational actions. The layout is static; list content updates live.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** list, search, filter, calendar, refresh-cw, alert-triangle, check-circle, x-circle, percent, file-text, play-circle.

* **Top header**

  * Title: “Contracts”
  * Global search (ID/Client)
  * Buttons: `Create Contract` (primary, permission-gated), `Run Price Indexing (All)` (permission-gated), `Export CSV`, `Filter`

* **Main content**

  * Collapsible **Filter bar** (chips + inputs)
  * **Flat table** (paginated, sortable)

* **Right slim rail**

  * KPI & health widgets for renewals, statuses, indexing recency, and recent approvals

---

## Primary Components (mark Livewire where applicable)

* **`ContractsFilterBar` (Livewire):** query, chips for Status, Renewal window, Binding, Indexing, Interval, Client.
* **`ContractsTable` (Livewire):** server-side pagination/sorting; row actions; infinite scroll optional.
* **`IndexingRunButton` (Livewire):** triggers global indexing job (permission-gated).
* **`RenewalSummaryWidget` (rail):** buckets by due date (<30d, 30–60d, >60d).
* **`IndexingHealthWidget` (rail):** last index run age; contracts not indexed in >90d.
* **`RecentApprovalsWidget` (rail):** last 5 approvals with approver metadata.
* **`CatalogLinks` (rail):** shortcuts to services and settings.

**Reusable elements:** shared table, shared status chips, shared autocomplete (Client), shared date range picker.

---

## Columns (default set)

* **Contract ID** (DB auto-increment starting 10000; clickable)
* **Client** (name; quick link to client context)
* **Status** (chips: Draft, Active, Pending Renewal, Expired, Floating, Terminated, Suspended, Approved/Pending Start)
* **Start date** / **End date**
* **Binding end** (shows `Floating` indicator when passed and continuing)
* **Auto-renew** (on/off icon)
* **Billing interval** (normalized label)
* **Total monthly price** (computed; currency from settings)
* **Included services (count)**
* **SLA policy** (badge)
* **Indexing allowed** (Yes/No + max %)
* **Last indexed at**
* **Next review/renewal date**

**Default sort:** Next renewal date (ascending).
**Secondary sorts:** Status → Client → Contract ID.

---

## Filters

* **Status:** multi-select (Draft/Active/Pending Renewal/Expired/Floating/Terminated/Suspended/Approved)
* **Renewal window:** presets (≤30d, 31–60d, 61–90d) + custom date range
* **Binding:** Under binding / Floating / No binding
* **Indexing:** Allowed / Not allowed + max % range
* **Billing interval:** Monthly / Quarterly / Yearly / Custom
* **Client:** autocomplete (typeahead)
* **Price range:** min/max monthly (computed)

Chips persist via querystring/local storage (per-user sticky filters).

---

## Row Actions

* **Open** → `tech.cs.contracts.show`
* **Run indexing** (permission-gated)
* **Terminate** (modal: immediate or at period end; permission-gated)
* **Renew** (prefills `tech.cs.contracts.edit` with renewal data)
* **Print/Send** (opens actions on show page)

Bulk actions (when multi-select enabled): `Run indexing`, `Export CSV` (permission-gated where relevant).

---

## Right Rail Widgets (suggested)

* **Upcoming Renewals**: top 5 by date with quick links
* **Indexing Health**: stale indexing list + "Run now" shortcut
* **Recent Approvals**: last 5 approvals with timestamps
* **Status KPIs**: counts by key status (Active, Floating, Pending Renewal, Expired)

---

## Behaviors & Validation

* Live updates: search/filter/table refresh without page reload.
* Empty states with suggested actions (Create, adjust filters).
* Alert banner if monthly cron hasn’t run in >35 days (settings-driven).
* Conflict indicator when `Terminate at period end` collides with `Auto-renew`.
* Permission gating hides restricted actions.

---

## Permissions Matrix (view-level)

* View list: `contract.view`
* Create: `contract.create`
* Run indexing (all): `contract.admin` or `tech.admin` or `superuser`
* Terminate/Renew row actions: `contract.edit` (with additional policy checks)

---

## Performance & Data

* Server-side pagination and sorting; N+1 safe (preload client + renewal/index metadata).
* Optional row virtualization for large datasets.
* KPI widgets cached with short TTL; invalidated on indexing run.

---

## Telemetry & Audit

* Track: filter usage, exports, indexing trigger, terminate/renew clicks.
* Rail widgets surface latest audit items (approve/terminate/index events).

---

## Edge Cases

* **Approved/Pending Start** contracts: show separate status and start date tooltip.
* **Backdated activations**: display Active; audit shows backdate action.
* **Hidden clients** (if any policy): rows visible only to allowed roles.

---

## QA Scenarios (high level)

* Filter `Floating` + `Renewal ≤30d` returns expected subset.
* Run indexing (all) caps increases at contract max % and applies decreases when allowed; audit written.
* Permission checks: non-admins don’t see global indexing button; row actions hidden when lacking rights.

---

## Notes

* No HTML code in this doc; describes components and behavior only.
* Uses the standard top/main/rail page template; dashboard is static; content updates live.
* Names and structure are optimized for developer handoff and GitHub Copilot understanding.
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