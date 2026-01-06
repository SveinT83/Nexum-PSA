# Contract System – Functional Specification

**Date:** 2025-10-17  
 **Status:** Not completed  
 **Difficulty:** High  
 **Estimated time:** 10 hours  
 **Controller namespace:** `App\\Http\\Controllers\\Tech\\CS\\ContractsController` (main) and `App\\Http\\Controllers\\Tech\\Admin\\Settings\\CS\\ContractsController` (settings)

---

## 1. Purpose & Scope

The contract system defines how service agreements are structured, managed, renewed, and indexed within the PSA. Contracts represent binding or non-binding relationships between Trønder Data and its clients, linking together services, pricing, and SLA rules under one client-specific agreement.

Contracts are created as **snapshots** of current service offerings, ensuring stability and predictable billing during their active period. The system must handle both fixed-term (binding) and open-ended (floating) contracts, support automated renewals, and provide optional price indexing linked to global and service-level rules.

---

## 2. Route & View Structure

**Main module:** `tech.cs.contracts.*`

- `tech.cs.contracts.index` – List of all contracts with sort and filter controls.
- `tech.cs.contracts.create` – Create new contract (select client, add services, define terms).
- `tech.cs.contracts.edit` – Edit existing contract (binding-time restrictions apply).
- `tech.cs.contracts.show` – Detailed view of a single contract.
- `tech.admin.settings.cs.contracts` – Global contract behavior and rules.

---

## 3. Layout & Components

All views follow the default page layout: **Top header / Main content / Right slim rail.**

### Common Components

- `ContractListTable` – flat table of all contracts with live filtering and sorting.
- `ContractEditor` – form with live updates of total cost, duration, and price index.
- `ServiceAddPanel` – side drawer for selecting and configuring one service at a time.
- `ContractSummaryRail` – right column with totals, SLA, binding and status widgets.
- `PriceIndexingButton` – trigger manual index recalculation (also run by cron).
- `ContractAuditWidget` – recent audit entries and change log.
- `ContractTerminationModal` – allows end-of-term or immediate termination.

---

## 4. Functional Overview

### 4.1 Contract Creation (`tech.cs.contracts.create`)

- Select client (contracts are client-scoped).
- Add services one by one via search + configuration sidepanel.
- Each service snapshot includes: price, interval, caps, SLA, currency, etc.
- All fields except SKU can be overridden per contract.
- Dynamic total price calculation (Livewire live updates).
- Define binding time, renewal rule, and price indexing parameters.
- Choose whether price indexing is allowed during binding period.
- Max allowed indexing percentage defined per contract.
- Option for additional index percentage outside binding (renewal argument).
- Contract numbering follows DB auto increment starting at 10000.

### 4.2 Contract Editing (`tech.cs.contracts.edit`)

- Contracts under binding cannot be modified unless settings allow it.
- Superadmin override possible, with audit confirmation.
- All pricing and rules follow service-level and global policy restrictions.

### 4.3 Contract Details (`tech.cs.contracts.show`)

- Flat table of included services with snapshot data and overrides.
- Summary panel shows: Status, Binding/Floating state, SLA, cost, index info.
- Actions: Run indexing, Terminate, Renew, Print/Send PDF.
- Audit log and latest changes visible in slim rail.
- Contract approval status and date visible.

### 4.4 Contract List (`tech.cs.contracts.index`)

Default columns:

- Contract ID (DB ID)
- Client
- Status (Active, Draft, Pending Renewal, Expired, Floating, Terminated, Suspended)
- Start/End/Binding dates
- Renewal date
- Billing interval
- Total monthly cost
- Price indexing allowed/max %
- Included services (count)
- SLA policy
- Last indexed at / Next renewal

Sorting default: **Next renewal date ascending**.

### 4.5 Contract Approval & Signing

- Contracts are generated from service terms + optional custom clauses.
- Sent by email link: “Approve / Decline”.
- Default recipient: Client owner (can override per contract).
- Simple one-click confirmation (no OTP).
- Link validity defined in settings (default 7 days).
- Upon click: logs metadata (user, IP, timestamp, device info).
- When sent, contract becomes locked; must be canceled and resent if edited.
- On approval: status changes to **Active** on the defined start date (immediate if date is in the past).

### 4.6 Contract States

- Draft
- Active
- Pending Renewal
- Expired
- Floating (binding expired, continues automatically)
- Terminated
- Suspended

---

## 5. Price Indexing & Automation

- Manual trigger: available globally, in list header, and per contract.
- Access: `contract.admin`, `tech.admin`, `superuser`.
- Cron: monthly run.
- Checks linked services for price changes.
- If price increased, applies new price up to contract's max allowed %.
- If price decreased, applies immediately (allowed setting in binding-time policies).
- Binding and decrease rules defined in `tech.admin.settings.cs.contracts`.

---

## 6. Global Settings (`tech.admin.settings.cs.contracts`)

Define system-wide defaults and permissions:

- Allow contracts to auto-renew or require explicit renewal.
- Default binding policies (duration, downgrade restrictions).
- Default indexing behavior and percentage caps.
- Whether price decreases apply inside binding.
- Notification rules (global toggles): renewal, termination, indexing events.
- Notification override allowed per contract (only enabling locally, not disabling).
- Contract termination permissions and allowed methods (immediate/end-of-term).
- Whether binding-time changes are allowed and for which roles.
- Contract approval link validity (default 7 days).
- Control for whether services with their own binding cannot be downgraded.

---

## 7. Relations & Data Model

- `contracts` – main contract table (client_id, status, start, end, binding_end, renewal, totals, settings snapshot).
- `contract_services` – linked services (contract_id, service_id, snapshot fields, raw_snapshot JSON, overrides).
- `services` – existing service catalog.
- `clients` – existing client table.

Relations:

- One contract → many contract_services.
- Contract belongs to one client.
- Each contract_service stores its original service reference for integrity.

Audit logging is mandatory for all contract changes, approvals, indexing actions, and overrides.

---

## 8. Permissions

| Area                   | Permission                            | Roles                      |
|------------------------|---------------------------------------|----------------------------|
| View contracts         | `contract.view`                         | tech, admin                |
| Create/edit contracts  | `contract.create`, `contract.edit`        | contract.admin, tech.admin |
| Run indexing           | `contract.admin`, `tech.admin`, `superuser` |                            |
| Terminate contract     | `contract.delete`                       | admin, superuser           |
| Manage global settings | `contract.settings.manage`              | tech.admin, superuser      |

---

## 9. UI Behavior Summary

- Livewire updates on all pricing and totals.
- Add services via right-hand drawer, one at a time.
- Immediate feedback on binding, pricing, and renewal schedule.
- “Floating” label shown when binding expired but contract continues.
- Right rail always visible with quick metrics and action buttons.
- Contract ID used as human reference starting at 10000.

---

## 10. Future Extensibility

- Integration with Billing & Time Entries module.
- SLA metrics linkage for automatic breach tracking.
- Client portal read-only view of active contracts.
- Reporting widgets for revenue forecasting and renewal pipelines.

---

**End of Specification**

---

## View Spec: tech.cs.contracts.index

**Date:** 2025-10-17  
 **URL:** `tech.cs.contracts.index` → `/tech/cs/contracts`  
 **Access levels:** `contract.view`, `tech.view`, `tech.admin`, `superuser`  
 **Controller:** `App\Http\Controllers\Tech\CS\ContractsController@index`  
 **Status:** Not completed  
 **Difficulty:** Medium  
 **Estimated time:** 3.0 hours

### Purpose

Read-only, fast overview of all contracts with robust filtering/sorting, renewal visibility, and quick actions. The list is static in layout, dynamically live-updated.

### Layout (Bootstrap template)

- **Top header:** title, search, filter toggles, bulk/context actions
- **Main content:** flat table (virtualized if dataset is large)
- **Right slim rail:** summary widgets (renewals, statuses, indexing health), recent audit highlights

### Primary Components (Livewire unless noted)

- `ContractsFilterBar` **(Livewire):** global search (client, ID, SKU), filter chips (Status, Renewal window, Binding state, Interval, Indexing allowed), quick reset.
- `ContractsTable` **(Livewire):** paginated, sortable columns, row actions, infinite scroll optional.
- `IndexingRunButton` **(Livewire):** visible if user has `contract.admin|tech.admin|superuser`.
- `RenewalSummaryWidget` **(rail):** counts: due <30d, 30–60d, >60d.
- `AuditHighlights` **(rail):** last 5 contract events (approve/terminate/index).
- `StatsKPI` **(rail):** totals: Active, Floating, Pending Renewal, Expired.

### Columns (default set)

- **Contract ID** (DB ID starting 10000; clickable)
- **Client** (name + quick link to client context)
- **Status** (chips: Draft, Active, Pending Renewal, Expired, Floating, Terminated, Suspended)
- **Start date** / **End date**
- **Binding end date** (shows “Floating” when binding passed and continuing)
- **Auto-renew** (on/off icon)
- **Billing interval**
- **Total monthly price** (calculated, currency from settings)
- **Included services (count)**
- **SLA policy** (badge)
- **Price indexing allowed** (max %)
- **Last indexed at**
- **Next review/renewal date**

**Default sorting:** Next renewal date (ascending).  
 **Secondary sorts:** Status → Client → Contract ID.

### Row Actions

- **Open** (default click → `tech.cs.contracts.show`)
- **Run indexing** (permission-gated)
- **Terminate** (modal; immediate or at period end; permission-gated)
- **Renew** (creates renewal flow → `tech.cs.contracts.edit` prefilled)
- **Print/Send** (PDF render/send from show page shortcut)

### Filter Set (details)

- **Status:** multi-select (Draft/Active/Pending Renewal/Expired/Floating/Terminated/Suspended)
- **Renewal window:** preset chips (≤30d, 31–60d, 61–90d, custom date range)
- **Binding:** `Under binding`, `Floating`, `No binding`
- **Indexing:** `Allowed`, `Not allowed`, percent range slider
- **Billing interval:** Monthly/Quarterly/Yearly/Custom
- **Client:** autocomplete (typeahead)

### Smart Behaviors

- **Live counters** update as filters change (no page reload).
- **Sticky filters** (persist via querystring/local storage per user).
- **Empty states**: show helpful actions (“Create contract”, “Adjust filters”).
- **Alert banner** when monthly cron hasn’t run in >35 days (settings-driven).
- **Renewal attention**: rows within 30 days are elevated (icon only, no color spec).

### Right Rail Widgets (proposed)

- **Upcoming Renewals** (top 5 by date)
- **Indexing Health** (contracts not indexed in >90d)
- **Recent Approvals** (last 5 with approver metadata)

### Buttons (top header)

- **Create Contract** (if `contract.create`)
- **Run Price Indexing (All)** (if `contract.admin|tech.admin|superuser`)
- **Export (CSV/PDF)** (CSV for ops; PDF list report optional later)
- **Filter toggle** (show/hide filter bar)

### Reused Elements / Libraries

- **Table**: shared table component used elsewhere (sortable headers, fixed first column)
- **Chips/Badges**: shared status chip set
- **Autocomplete**: shared client search input

### Permissions Matrix (view-level)

- **View table:** `contract.view` (all rows user is allowed to see)
- **Row actions:** gated by `contract.edit`, `contract.delete`, `contract.admin`
- **Global indexing button:** `contract.admin|tech.admin|superuser`

### Performance & Data

- Server-side pagination and sorting
- Optional row virtualization client-side
- N+1 safe: preload client and latest index/renewal metadata

### Telemetry & Audit

- Log: filter usage, export, run indexing clicked, terminate/renew initiated
- Audit entries surfaced in rail widget

### Edge Cases

- Contracts with **future start**: show status `Approved/Pending Start` in table
- Contracts with **backdated start**: show Active; audit captures backdate action
- Conflicts on terminate-at-period-end vs auto-renew: table displays a conflict icon; details in tooltip

### QA Scenarios (high level)

- Filter by `Floating` + `Renewal ≤30d` returns expected subset
- Run indexing updates prices within max % and writes audit entries
- Permission checks hide actions from non-authorized roles