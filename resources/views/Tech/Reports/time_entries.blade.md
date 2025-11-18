Tech Reports – time_entries.blade.php (formerly billing.blade.php)

Date: 2025-10-16
Primary URL (proposed): tech.reports.time → /tech/reports/time
Legacy URL (temporary alias): /tech/reports/billing → redirects to /tech/reports/time (optional)
Access (permissions): report.view + report.time.view (rename from report.billing.view in scaffolding); Admin/SuperAdmin will implicitly have these via role bundles.
Controller namespace: App\Http\Controllers\Tech\Reports\TimeEntriesController@index
Status: Not completed
Difficulty: Medium–High
Estimated time: 5.0 hours

Position in layout (Bootstrap):
Top header / Main content / Right slim rail (static dashboard layout; dynamic/live data)

Settings (currency, rates, contracts, retention, etc.) live under Admin → Settings and are intentionally not editable here.

Purpose

A read-only time entries reporting view that aggregates time usage sourced from ticket-linked time entries (initial scope) with rollups by client, site, technician, queue/category, month/year. Output is optimized for on-screen review + browser print to PDF; no CSV in v1.

Data source: ticket time tracking (manual time + any approved fallback time).

Out of scope here: contract pricing logic, invoice generation, or settings editing (handled elsewhere in Admin).

Filters & Grouping

Filter bar (sticky):

Date range picker (absolute & relative presets: This month, Last month, Custom).

Scope picker: Client → Site → Technician (multi-select capable).

Taxonomy: Queue, Category (multi-select).

Billing flags:

Billable (explicitly billable time entries)

Contract-covered (time entries marked “covered by contract”)

All entries (no filtering; shows both)

Currency display: Read-only label showing active currency code (from Settings), used for monetary columns. (Reminder: document currency settings under Admin later.)

Grouping options (single select): Client, Site, Technician, Queue, Category, Month, Year.

Behavior:

Filters are deep-linkable via querystring (shareable URLs).

No auto-refresh/polling; the user changes filters and clicks Generate to refresh results (idempotent fetch).

Last-used filters remembered per user.

Output & Widgets

Top KPI row (reusable Reports/Widgets/KPIStat):

Total hours (filtered)

Billable hours

Contract-covered hours

Non-billable hours

Trend (reusable Reports/Widgets/TrendChart):

Hours over time (units: hours per period, respecting selected grouping; month if time-based).

Main table (reusable Reports/Tables/TimeEntriesRollupTable):

Columns by grouping:

Group key (e.g., Client / Site / Technician / Month)

Total Hours

Billable Hours

Contract-Covered Hours

Non-billable Hours

Amount (optional): if a billing rate is available; otherwise show “—”

Row click → drill-down drawer listing individual time entries (ticket #, subject, technician, duration, flags).

Subtotals per group; sticky grand total footer.

Right slim rail:

Saved presets (per-user): list + apply.

Definitions/help (drawer): explains billable vs. contract-covered, data freshness, and currency source.

Quick links: open filtered Ticket list in a new tab (for reconciliation). Ticket module remains the single source of truth for SLA/time semantics.

Livewire components (reusable)

Reports/Filters/DateRangePicker

Reports/Filters/ScopePicker (Client → Site → Technician)

Reports/Filters/TaxonomyPicker (Queue, Category)

Reports/Filters/BillingFlags (Billable / Contract-covered / All)

Reports/Filters/GroupingPicker

Reports/Toolbar/Actions (Generate, Print)

Reports/Widgets/KPIStat

Reports/Widgets/TrendChart

Reports/Tables/TimeEntriesRollupTable

Reports/Partials/EmptyState

These are shared patterns across /tech/reports/* and align with the scaffolding in Views, Routes & Permissions.

Actions

Generate (primary): fetches aggregated results with current filters.

Print: launches browser print dialog; users can “Print to PDF” to produce a portable report. (No CSV in v1, per decision.)

Drill-down: open drawer of row’s underlying entries; from there, “Open Ticket” links to ticket show page.

Data & Semantics

Time included: “Time entries tied to tickets” only (v1). Includes “manual time” and any approved fallback estimate per Ticket spec.

Flags:

Billable: boolean on time entry.

Contract-covered: boolean on time entry (derived from contract mapping rules; read-only here).

Rates:

Billing rate and cost rate may exist per entry (if already stored by the time tracking subsystem). If rates are not documented/implemented yet, the Amount column displays “—” and the KPI shows only hours. (We will add margin/amounts once cost/billing rates are documented.)

Currency:

Display currency comes from Settings (global/tenant currency); show code (e.g., “NOK”) in header and table footers. (We will document Currency Settings under Admin later.)

Security & scope:

Page requires report.view + report.time.view (rename from report.billing.view). Record-level scoping enforced by policies consistent with our global approach. Admin/SuperAdmin inherently see all.

Smart UX details

Deterministic exports: print layout includes a Filter Summary header (date range, flags, grouping, scope).

Performance: server-side aggregation; pagination for drill-down lists; lazy chart hydration.

Accessibility: keyboard focus order through filter bar → Generate → results; ARIA labels on charts/tables.

Icons (suggested; no colors)

Time / clock: clock

Filters: filter

Generate: play (or refresh-ccw)

Print: printer

Drill-down: chevron-right

Routing & Migration Notes

Current scaffolding lists /tech/reports/billing with permission report.billing.view. We’ll rename to /tech/reports/time with report.time.view; keep the old route as a 302/alias until templates are updated. Update Views, Routes & Permissions accordingly.

Blade path: resources/views/tech/reports/time_entries.blade.php (new) replacing billing.blade.php once references are migrated.

Audit & Logging

Log Generate actions (who, when, filter JSON, result row counts) to the general audit trail. This aligns with platform-wide audit requirements.

Open follow-ups (to document later)

Currency settings page (Admin): define where tenant currency is set and how it flows into reports.

Rate model spec: define cost and billing rate sources (per technician, per contract, per queue/category) and how to snapshot them on time entry (to avoid retroactive drift).

Contract coverage rules: formalize how a time entry is marked “covered by contract” (mapping priorities & queues to contract coverage).