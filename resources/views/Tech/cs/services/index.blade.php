# tech.cs.services.index – View Specification

**Date:** 2025-10-17
**URL:** `tech.cs.services.index` → `/tech/cs/services`
**Access levels:** `service.view`, `tech.view`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\CS\\ServicesController@index`
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 3.0 hours

---

## Purpose

Discover, review, and manage the **service catalog** used by contracts. The list is static in structure but supports **live updates** (search, filters, pagination). Enables quick navigation to create/edit flows and visibility into SKU health and policy defaults.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** layers, tag, search, filter, file-text, refresh-cw, shield, calendar, percent, alert-triangle, plus-circle, edit, copy.

* **Top header**

  * Title: “Services”
  * Search input (SKU/Name) with debounce
  * Buttons: `Create Service` (primary), `Export CSV`, `Filter` (toggle)

* **Main content**

  * **Filter row** (collapsible)
  * **Flat table** with sortable columns and row actions

* **Right slim rail**

  * KPI cards and health widgets (see below)

---

## Components (Livewire)

* **`ServicesFilterBar`** – search + filter chips; persists state via querystring/local storage.
* **`ServicesTable`** – server-side pagination/sorting; infinite scroll optional.
* **`SkuHealthWidget`** (rail) – shows collisions/missing SKUs.
* **`CatalogStatsKPI`** (rail) – counts by status and category.
* **`RecentChangesWidget`** (rail) – last 5 creates/edits/deprecations (from audit).

Reusable elements: shared badge/chip set (Status, Binding, Indexing), shared autocomplete for categories.

---

## Columns (default set)

* **Service Name** (click → show or edit, depending on permission)
* **SKU** (read-only; collision indicator if duplicate detected)
* **Status** (Active / Hidden / Deprecated)
* **Category** (e.g., Licensing, Managed, Support, Project)
* **Billing model** (Per user / Per asset / Fixed / Tiered)
* **Billing interval** (Monthly / Quarterly / Yearly / Custom)
* **Unit price** (currency from settings)
* **Binding required** (Yes/No + duration if set)
* **Downgrade allowed** (Yes/No)
* **Indexing default** (suggested max %; decreases allowed flag)
* **Linked contracts** (count; click to filter contracts by this service)
* **Last modified** (timestamp)

**Default sorting:** Status (Active first) → Category → Service Name (A–Z).
**Secondary sorts:** SKU, Last modified, Unit price.

---

## Filters

* **Status**: Active, Hidden, Deprecated (multi-select)
* **Category**: multi-select (typeahead)
* **Billing model**: multi-select
* **Binding**: Required / Not required; duration range
* **Indexing**: Has default %; decreases allowed (on/off)
* **Price**: range slider
* **SKU health**: Missing / Duplicate / OK

Preset quick chips: `Active`, `Deprecated`, `Binding required`, `Indexing defaults set`.

---

## Row Actions

* **Open** (default click → `tech.cs.services.edit`)
* **Duplicate** (prefill create form with copied fields)
* **Deprecate** (mark as Deprecated; permission-gated)
* **Hide/Unhide** (toggle visibility)
* **Copy SKU** (clipboard helper)

Bulk actions (when rows selected): `Deprecate`, `Hide/Unhide`, `Export CSV`.

---

## Right Rail Widgets (suggested)

* **Catalog KPIs**: Active count, Deprecated count, Hidden count
* **Category Breakdown**: top categories by count
* **SKU Health**: duplicates detected, missing SKUs
* **Recent Changes**: last 5 edits/creates (from audit log)

---

## Behaviors & Validation

* **Live search** with debounce; URL updates (deep-linkable filters).
* **SKU collision indicator** computed server-side; clicking shows a modal listing conflicts.
* **Deprecate** disables new use in contracts but preserves existing links; confirmation dialog.
* **Hide** removes from default searches but remains selectable when explicitly filtered.
* **Permission gating** hides actions from non-authorized users.

---

## Permissions

* View: `service.view`
* Create: `service.create`
* Edit/Deprecate/Hide: `service.edit` or `tech.admin`
* Export: `service.view`

---

## Performance & Data

* Server-side pagination and sorting (N+1 safe with category mapping).
* Optional caching of KPI widgets with short TTL.
* Preload counts for linked contracts (aggregated query with index on `contract_services.service_id`).

---

## Telemetry & Audit

* Log: filter usage, CSV exports, bulk actions, deprecations/hide toggles.
* Surface last 5 audit events in rail widget.

---

## Edge Cases

* Service with **no SKU**: row flagged; actions limited until SKU set.
* Attempt to **deprecate** a service used by active contracts: warning with count; allow or block based on settings.
* **Duplicate SKU** across services: collision modal suggests resolution paths.

---

## QA Scenarios (high level)

* Filter: Status=Active + Binding required → correct subset with counts.
* Deprecate a service and verify it no longer appears in contract `Add Service` search unless explicitly filtered.
* Detect duplicate SKU and open collision modal; resolve by editing conflicting item.
* CSV export respects current filters and column order.

---

## Notes

* No HTML code; components and behaviors only.
* Follows top/main/rail layout convention; dashboard is static, content live-updating.
* Naming and structure optimized for developer handoff and GitHub Copilot understanding.
