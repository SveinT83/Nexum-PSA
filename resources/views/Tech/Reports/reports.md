# Tech Reports – General Documentation

Date: 2025-10-16  
 Applies to: `resources/views/tech/reports/{sales|tickets|billing}.blade.php`  
 Layout (Bootstrap): Top header / Main content / Right slim rail

> All configuration (rules, SLAs, templates, etc.) lives under **Admin** and is intentionally **not** exposed here. 

---

## tech/reports/sales.blade.php

**Primary URL:** `tech.reports.sales` (route `/tech/reports/sales`)  
 **Access (permissions):** `report.sales.view` (requires auth)  
 **Controller namespace:** `App\Http\Controllers\Tech\Reports\SalesController@index`  
 **Status:** Not completed  
 **Difficulty:** Medium  
 **Estimated time:** 4.0 hours

**Purpose**  
 Sales performance and pipeline summary with breakdowns by **client**, **site**, **contact/user**, **month**, **quarter**, **year**. Reuse shared report filters and table/chart components.

**Suggested Livewire components (reusable)**

- `Reports/Filters/DateRangePicker` (relative & absolute)
- `Reports/Filters/ScopePicker` (Client → Site → User)
- `Reports/Filters/GroupingPicker` (None, Client, Site, User, Month, Quarter, Year)
- `Reports/Toolbar/ExportToolbar` (CSV now; PDF later)
- `Reports/Widgets/KPIStat` (cards: New Leads, Qualified, Won, Lost, Win-Rate %, Avg. Cycle)
- `Reports/Widgets/TrendChart` (time series: leads per period, win-rate trend)
- `Reports/Tables/LeadsSummaryTable` (grouped rollups + drill-down)
- `Reports/Partials/EmptyState`

**Data slices**

- Leads created, qualified, won/lost (counts & percentages)
- Cycle time (days to win) distribution
- Top sources/tags (if tracked)
- Per-client/site funnel (created → qualified → won)  
   *(Note: page surfaces read-only reporting; all sales settings remain under Admin.)* 

**Right rail (slim)**

- Saved filter presets (per user)
- Quick KPI notes (tooltips explain formulas)
- “Data freshness” badge (last refresh timestamp)

**Smart UX**

- Deep-linkable filters via querystring (shareable URLs)
- Remembers last used scope & range (per user)
- Instant CSV export respects current filters & grouping

**Icons (suggested)**

- Filters: filter
- Export: file-down
- Trend: line-chart
- KPI: gauge

---

## tech/reports/tickets.blade.php

**Primary URL:** `tech.reports.tickets` (route `/tech/reports/tickets`)  
 **Access (permissions):** `report.ticket.view` (requires auth)  
 **Controller namespace:** `App\Http\Controllers\Tech\Reports\TicketsController@index`  
 **Status:** Not completed  
 **Difficulty:** Medium–High  
 **Estimated time:** 6.0 hours

**Purpose**  
 Operational ticket analytics: SLA, workloads, quality KPIs, and time usage — groupable by **client**, **site**, **user/technician**, **queue**, **category**, **month/year**. Draw measures from the Ticket module. 

**Suggested Livewire components (reusable)**

- `Reports/Filters/DateRangePicker`
- `Reports/Filters/ScopePicker` (Client → Site → Technician/User)
- `Reports/Filters/TaxonomyPicker` (Queue, Category, Priority)
- `Reports/Filters/GroupingPicker` (Client, Site, Technician, Queue, Category, Month, Year)
- `Reports/Toolbar/ExportToolbar`
- `Reports/Widgets/KPIStat`:
  - First Response within SLA %
  - Resolve within SLA %
  - Average First Response (hh:mm)
  - Average Resolve time (hh:mm)
  - Reopen rate %
  - Tickets created / closed
- `Reports/Widgets/TrendChart`:
  - SLA breach trend
  - Backlog over time
  - Created vs. Resolved
- `Reports/Tables/TicketSlaTable` (grouped rollups + drill-down to ticket list)
- `Reports/Tables/TimeUsageTable` (manual vs. fallback time, by technician)
- `Reports/Partials/EmptyState`

**Data slices (examples)**

- SLA: first response/resolve targets & breaches by priority (P1–P4)
- Time tracking: manual vs. fallback time, cost-account mapping hints
- Quality: % tickets closed with required workflow checklist completed
- Volume: created/closed, backlog delta, status distributions  
   *(Measures & definitions map to the Ticket spec’s SLA, workflow, and time tracking sections.)* 

**Right rail (slim)**

- Saved presets (e.g., “Monthly SLA by Client”)
- Definitions drawer (SLA & KPI formula help)
- “View in Tickets” quick-links (opens filtered ticket list)

**Smart UX**

- Drill-down on any grouped cell → filtered ticket list in a new tab
- Sticky filter bar; async loads with skeleton states
- All exports include filter summary header

**Icons (suggested)**

- SLA: timer
- Backlog: inbox
- Time usage: clock
- Quality: shield-check

---

## tech/reports/billing.blade.php

**Primary URL:** `tech.reports.billing` (route `/tech/reports/billing`)  
 **Access (permissions):** `report.billing.view` (requires auth)  
 **Controller namespace:** `App\Http\Controllers\Tech\Reports\BillingController@index`  
 **Status:** Not completed  
 **Difficulty:** Medium–High  
 **Estimated time:** 5.0 hours

**Purpose**  
 Billable overview combining **time entries** and **ticket context** with rollups by **client**, **site**, **technician**, **queue/category**, **month/year**. Export-ready for accounting.

**Suggested Livewire components (reusable)**

- `Reports/Filters/DateRangePicker`
- `Reports/Filters/ScopePicker` (Client → Site → Technician)
- `Reports/Filters/BillingFlags` (billable, non-billable, internal, contract-covered)
- `Reports/Filters/GroupingPicker` (Client, Site, Technician, Queue, Category, Month, Year)
- `Reports/Toolbar/ExportToolbar` (CSV; totals & subtotals)
- `Reports/Tables/BillingRollupTable` (qty hours, effective rate, amount, subtotals)
- `Reports/Widgets/KPIStat` (Total billable hours, Non-billable hours, Write-offs, Coverage %)
- `Reports/Widgets/TrendChart` (billable hours & revenue trend)
- `Reports/Partials/EmptyState`

**Data slices (examples)**

- Hours and amounts by client/site/technician (respecting contract mappings)
- Non-billable and write-off totals
- Internal vs. external time (based on cost-account from queue/category)
- Reconciliation aids (e.g., time without ticket, ticket without time)

**Right rail (slim)**

- Export history (last 5 exports with timestamp & filter snapshot)
- Notes field (per-export memo saved in audit)
- “Open client” quick-link

**Smart UX**

- “Recalculate totals” action (idempotent) for long ranges
- Inline subtotal rows per group; grand total in sticky footer
- CSV export includes unique export-id for downstream reconciliation

**Icons (suggested)**

- Billing: receipt
- Export: file-down
- Recalc: refresh-ccw
- Amounts: banknote

---

## Shared patterns (all three views)

- **Header bar:** Title, filter summary chips, Export button
- **Main:** KPIStat row → TrendChart → Grouped table with drill-downs
- **Right rail:** Presets, help/definitions, quick links
- **Performance:** Server-side pagination for tables; cached rollups; lazy chart hydration
- **Accessibility:** Keyboard-navigable filters; table caption/aria labels
- **PWA:** Live updates badge when filters are “current month/week” (auto refresh)

**Reusable permissions & routes reference**  
 Routes and permissions for Reports pages come from our normalized scaffolding:  
 `/tech/reports` → `report.view`  
 `/tech/reports/billing` → `report.billing.view`  
 `/tech/reports/tickets` → `report.ticket.view`  
 `/tech/reports/sales` → `report.sales.view`. 

**Ticket KPI source reference**  
 SLA, workflow checks, time tracking, and reporting measures are defined in the Ticket spec and should be the single source of truth for calculations and labels surfaced in **tickets** and **billing** reports. 

---

## Notes for implementation

- **No settings here:** redirect users lacking permissions to a friendly denial page; link Admin → Settings where relevant. 
- **Controllers follow folder structure:** `Tech/Reports/{Sales|Tickets|Billing}Controller` with thin methods delegating to report services.
- **Audit:** log exports (who, when, filter JSON, row counts).
- **I18n:** labels via shared translation keys; numbers & dates respect locale.
- **Security:** all queries scoped by permissions and client/site visibility policies. 