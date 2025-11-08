# Tech Services – Functional Specification (2025-10-16)

Date: 2025-10-16
Status: Not completed
Difficulty: Medium
Primary namespace (URLs):

* tech.cs.services.index → /tech/cs/services
* tech.cs.services.create → /tech/cs/services/create
* tech.cs.services.edit → /tech/cs/services/{service}/edit
* tech.admin.settings.cs.services → /tech/admin/settings/cs/services

Layout template (Bootstrap): Top header / Main content / Right slim rail.
All views render inside the shared application shell.

Access (permissions):

* View: `service.view` (see everything)
* Create/Edit/Archive own: `service.create` (creator can edit/archive their own services)
* Admin (full control over all services): `service.admin`
* Tech/Admin roles: `tech.admin` and `superuser` implicitly have full control
* Settings page: `service.admin` or `tech.admin` (as per routes map)

Controller namespace:

* App\Http\Controllers\Tech\CS\Services\ServiceIndexController
* App\Http\Controllers\Tech\CS\Services\ServiceCreateController
* App\Http\Controllers\Tech\CS\Services\ServiceEditController
* App\Http\Controllers\Tech\Admin\Settings\CS\ServiceSettingsController

---

## 1) Domain model (UI-facing fields)

Core fields captured across create/edit:

* **name** (string, required)
* **sku** (string/integer, unique)

  * Auto-generated from start number configured under settings; manually editable with uniqueness validation and collision warning.
* **price_ex_vat** (decimal, required; price shown excl. VAT)
* **billing_interval**: minutes | month | year (single select)
* **unit_pricing**: none | per_user | per_device | per_server (single select)

  * If `per_device`: subtype meta (Windows/Mac/Linux) is informational only; single common price applies.
  * A single price applies to the chosen unit type (no per-subtype prices).
* **one_time_fee** (optional decimal)

  * **one_time_fee_recurrence** (optional): none | yearly | every_X_years | every_X_months
  * **recurrence_value_X** (integer, required when recurrence uses X)
  * Recurrence policy allowed intervals are governed by settings (see Service Settings).
* **timebank_enabled** (bool)

  * **timebank_interval**: month | year (independent of billing interval)
  * Carryover/reset policies are defined at **contract/settings** level, not at service level.
* **short_description** (plain text)
* **long_description** (plain text)

  * In contracts, long description can be replaced by terms (see below).
* **terms[]** (list of plain-text clauses)

  * One or more terms entries. Contracts can merge these in, optionally replacing long description.
* **queue_default_id** (reference to Ticket Queue; used as category grouping and default queue on ticket/contract usage)
* **availability_audience**: all | business | private (controls which customer types can see/select)
* **availability_addon_of_service_id** (nullable)

  * If set, this service is an **addon**; it is **not selectable** unless the dependency/base service is selected.
* **availability_client_whitelist[]** (optional set of clients)

  * If list is non-empty, only those clients see this service (custom variant).
* **orderable_in_client_portal** (bool flag; UX to be designed later)
* **icon** (100×100 asset or icon name reference)
* **status**: Draft | Published | Archived

  * Delete is **blocked** when service is referenced by active contracts; use Archive instead.
* **visibility & sorting**: `sort_order` (int) for manual ordering within the selected queue/category.
* **default_discount** (optional): amount OR percent (stored as value + type) – used only as suggestion/prefill in contracts.
* **taxable** (bool) – default from global settings.

Audit: All create/edit/archive/publish actions are recorded in the **global audit system** (no per-service audit widget).

---

## 2) tech.cs.services.index

**URL:** `tech.cs.services.index` → `tech.cs.services.index.view` → `/tech/cs/services`
**Access:** `service.view`
**Status:** Not completed
**Difficulty:** Medium
**Time estimate:** 4.0 hours

### Purpose

A searchable, grouped catalogue of all services (Draft/Published/Archived) to aid technicians and admins in management and contract preparation.

### Layout

* **Top header:** Title, quick actions (Create Service), search input
* **Main content:**

  * Filter bar (collapsible)
  * Grouped list/table by **Queue (category)** with optional manual ordering within group
  * Row actions and status chips
* **Right rail:** Contextual help, selected service preview (name, price, interval, status), and “Active clients” widget (see below)

### Filters & search

* Search box (name / sku)
* Status: Draft | Published | Archived
* Billing interval: minutes | month | year
* Unit pricing: none | per_user | per_device | per_server
* Queue (category): multi-select
* Audience: all | business | private
* Addon filter: show only addons / exclude addons
* Orderable in client portal: yes/no

### Table/List columns

* Icon + Name
* SKU
* Queue (category)
* Price excl. VAT (shows unit type if any; e.g., “NOK 99 / user / month”)
* Status (chip)
* Audience
* Addon-of (if applicable)
* Last updated (timestamp)

### Row actions

* View (opens preview drawer)
* Edit
* Archive / Unarchive
* Delete (only allowed when **not** used in active contracts; otherwise disabled with tooltip)

### Widgets (recommended)

* **Active Clients using this service** (right rail; Live updates): count + clickable list (client name → client page)
* **Quick Filters** saved presets (per user)

### Livewire usage

* Optional for dynamic filters, group expand/collapse, and right-rail preview
* If omitted initially, implement classic pagination + standard form submits

---

## 3) tech.cs.services.create

**URL:** `tech.cs.services.create` → `/tech/cs/services/create`
**Access:** `service.create`
**Status:** Not completed
**Difficulty:** Medium
**Time estimate:** 3.0 hours

### Purpose

Create a new modular service building block with price, interval, availability, and policy flags.

### Form sections (components)

* **Basics**: name, sku (auto-filled; editable), status (Draft default), icon
* **Pricing**: price_ex_vat, billing_interval, unit_pricing, one_time_fee (+ recurrence fields), default_discount (amount|%)
* **Timebank**: timebank_enabled, timebank_interval (month|year)
* **Availability**: audience (all/business/private), addon_of (dropdown), client whitelist (multi-select), orderable_in_client_portal
* **Categorization**: queue_default (Ticket Queue), sort_order
* **Descriptions & Terms**: short_description, long_description, terms[] list editor
* **Tax & Currency (read-only hints)**: shows inherited defaults from global billing settings

### Buttons / actions

* Save as Draft
* Publish (if approval not required)
* Cancel

### Validation & rules

* Name, price_ex_vat, billing_interval required
* SKU: unique; collision shows inline warning; offer “generate next” helper
* If addon_of set → hide from selection unless base is present (enforced by downstream usage)
* If one_time_fee recurrence uses X → require integer X value

---

## 4) tech.cs.services.edit

**URL:** `tech.cs.services.edit` → `/tech/cs/services/{service}/edit`
**Access:** `service.create` (own) or `service.admin` (any)
**Status:** Not completed
**Difficulty:** Medium
**Time estimate:** 2.5 hours

### Purpose

Update existing service data; enforce lifecycle rules (archive vs delete; approval gate if enabled).

### Panels (components)

* Basics (name, sku, icon, status)
* Pricing (as in create)
* Timebank
* Availability (audience, addon-of, client whitelist, portal flag)
* Categorization (queue_default, sort_order)
* Descriptions & Terms
* Read-only: Global billing settings preview (currency, VAT default)
* **Active Clients** list (shows clients with active contracts using this service)

### Actions

* Save
* Publish (if approval not required)
* Archive / Unarchive
* Delete (only when not referenced by active contracts)
* Cancel

### Extra rules

* When attempting **Delete** and service is referenced → block and suggest **Archive** (with explanation)
* On publish when settings require approval → action disabled; show tooltip: “Approval required (Draft only).”
* On SKU change → uniqueness validation + collision warning

---

## 5) tech.admin.settings.cs.services

**URL:** `tech.admin.settings.cs.services` → `/tech/admin/settings/cs/services`
**Access:** `service.admin` or `tech.admin`
**Status:** Not completed
**Difficulty:** Medium
**Time estimate:** 3.5 hours

### Purpose

Govern global defaults and constraints used by the Services module.

### Sections (components)

* **Approval Policy**

  * Toggle: *Require admin approval before publishing services*
  * Behavior: If enabled, non-admins can only save **Draft**; Publish actions disabled.
* **SKU Generation**

  * `sku_start_number` (integer)
  * Helper: Preview next SKU; reset/gap handling policy (display-only)
* **Billing Settings (read-only pointers or links)**

  * Currency (from global billing settings)
  * Default VAT rate (e.g., 25%)
  * `taxable_default` toggle
  * Note: Services read these on create/edit; pricing is stored excl. VAT
* **Intervals Allowed for One-Time Fee Recurrence**

  * Enable/disable: yearly | every_X_years | every_X_months
  * Min/max bounds for X

### Widgets

* Recently published/archived services (quick overview)
* Exceptions: services missing queue/category (validation list)

### Livewire usage

* Optional for instant toggles and preview of next SKU

---

## 6) Routing & Permissions (summary)

* `GET /tech/cs/services` → index (permission: `service.view`)
* `GET /tech/cs/services/create` → create form (permission: `service.create`)
* `POST /tech/cs/services` → store (permission: `service.create`)
* `GET /tech/cs/services/{service}/edit` → edit form (permission: `service.create` owner OR `service.admin`)
* `PUT/PATCH /tech/cs/services/{service}` → update (same as edit)
* `POST /tech/cs/services/{service}/archive` → archive (permission: as edit)
* `DELETE /tech/cs/services/{service}` → delete (only if no active references)
* `GET/POST /tech/admin/settings/cs/services` → settings (permission: `service.admin` or `tech.admin`)

---

## 7) UX policies & behaviors

* **Queues as categories:** Service lists group by default Ticket Queue; queue also seeds default on ticket/contract creation.
* **Addon dependency:** Addons hidden/not orderable unless base service included.
* **Audience gating:** all | business | private controls visibility in client-facing contexts.
* **Client whitelist:** If populated, only those clients can see/select the service (custom variant).
* **Orderability flag:** Client portal implementation is deferred; store flag now.
* **Archive vs Delete:** Delete is disallowed when referenced by active contracts; Archive removes from new selection while preserving historical references.
* **Active clients widget:** Visible on index (right rail) and edit; lists clients currently using the service.
* **Discount handling:** Default discount is a **prefill** for contracts; not enforced globally.
* **Audit:** Rely on global audit logging for create/edit/archive/publish.

---

## 8) Suggested UI components (Bootstrap; no HTML)

* Page header with title, action buttons (Create, Filters)
* Search input (debounced)
* Filter bar (status, interval, unit, queue, audience, addon, portal flag)
* Grouped list/table with sticky group headers (by queue)
* Status chips (Draft/Published/Archived)
* Price pill ("NOK 99 / user / month")
* Right-rail cards: Preview, Active Clients, Tips
* Form controls: text inputs, selects, multi-selects, toggle switches, list editor for terms
* Toasts for save/publish/archive
* Modal: Archive confirmation; Delete confirmation with dependency check

---

## 9) Data validation & error states

* SKU uniqueness (server-side check; show collision message and propose next)
* Required fields: name, price_ex_vat, billing_interval
* Conditional: recurrence value X must be set when using X-based schedule
* Dependency guard: cannot publish addon if base is Archived (warn; allow but mark unusable in selection contexts)
* Delete guard: block when referenced; surface list of referencing contracts

---

## 10) Estimates & sequencing

* Index view: 4.0 h
* Create view: 3.0 h
* Edit view: 2.5 h
* Settings view: 3.5 h
* Wiring (routes, policies, seeds for permissions): 1.5 h
  **Total:** 14.5 hours

---

## 11) Notes for GitHub Copilot

* Mark Livewire usage only where dynamic filtering/preview is needed; prefer classic controllers otherwise.
* Reuse shared components for tables, filter bars, chips, and right-rail cards.
* Maintain URL namespacing exactly as above for consistency with other modules.
* No user or company names in documentation.
* Keep all strings in English; apply translation later via templates.
