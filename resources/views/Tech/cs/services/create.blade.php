# tech.cs.services.create – View Specification

**Date:** 2025-10-17
**URL:** `tech.cs.services.create` → `/tech/cs/services/create`
**Access levels:** `service.create`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\CS\\ServicesController@create` (GET) / `@store` (POST)
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 3.5 hours

---

## Purpose

Create a new **service offering** that can be used inside contracts. The service defines defaults (pricing, billing interval, SLA, binding, downgrade policy, terms) that are **snapshotted** into contracts. SKU must be unique; manual entry is allowed with collision warning.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** plus-circle, tag, layers, calendar, percent, shield, scale, clock, file-text, alert-triangle, save.

* **Top header**

  * Title: “Create Service”
  * Secondary actions: `Cancel` (back to index), `Preview` (opens read preview in modal)

* **Main content** (grouped fieldsets)

  1. **Identity**

     * Service name (required)
     * Category (select; e.g., Licensing, Support, Managed, Project)
     * Visibility (Active / Hidden / Deprecated)
  2. **SKU & Catalog**

     * SKU (text, required) – manual entry allowed
     * Button: `Generate SKU` (uses configured range; warns on collisions)
     * Collision warning component (non-blocking until resolved)
  3. **Pricing & Billing**

     * Billing model (dropdown): Per user / Per asset / Fixed / Tiered
     * Unit price (currency from settings)
     * Billing interval (Monthly / Quarterly / Yearly / Custom)
     * Setup fee (optional)
     * Discount default (percent or amount)
  4. **Inclusions & Caps**

     * Included users (optional)
     * Included assets (optional)
     * Included hours / timebank (optional)
     * Caps/overage policy (describe text + numeric fields)
  5. **SLA Defaults**

     * SLA policy (select from SLA profiles)
     * Response/Resolution targets (read-only when profile selected; editable if custom)
  6. **Binding & Downgrade**

     * Binding required (checkbox)
     * Binding duration (months: 12/24/36/custom)
     * Downgrade allowed during binding (checkbox)
     * Notes for binding (short text)
  7. **Indexing Defaults**

     * Allow price indexing link (info text: contracts may restrict)
     * Suggested max indexing % (number)
     * Apply decreases allowed (checkbox)
  8. **Terms & Descriptions**

     * Short description (for lists)
     * Invoice description (will show on invoices)
     * Service terms (rich text; becomes part of contract document)

* **Right slim rail**

  * **ServicePreviewCard**: name, category, SKU, interval, unit price, estimated monthly value (computed)
  * **PolicySummary**: binding, downgrade, indexing, SLA
  * **AuditPreflight**: who is creating, timestamp, will be logged
  * **Actions**: `Save`, `Save & New`

---

## Components (Livewire)

* **`ServiceEditor`** – orchestrates all sections and validations.
* **`SkuCollisionAlert`** – inline status for SKU uniqueness (checks on blur and before save).
* **`PricingPanel`** – handles model/interval/unit price/discount with live totals.
* **`BindingPolicyPanel`** – binding duration and downgrade rule.
* **`IndexingPolicyPanel`** – suggested defaults for indexing and decreases.
* **`SlaSelector`** – choose profile or custom; displays derived targets.
* **`TermsEditor`** – rich text (no colors) with word count.
* **`PreviewRail`** – condensed read-only preview of key fields.

Reusable elements: shared number inputs with currency suffix; shared select/autocomplete.

---

## Behaviors & Validation

* **Live validation** on name, SKU uniqueness, numeric ranges, and required fields.
* **SKU policy**: must be unique; generating uses settings-defined prefix/range; manual override allowed with collision warning.
* **Currency**: pulled from settings; display symbol and ISO code; no conversion in this view.
* **Binding logic**: if Binding required is enabled, enforce duration > 0; show downgrade toggle.
* **Indexing defaults**: inform that contracts may further restrict indexing; decreases allowed as default toggle.
* **SLA**: selecting a profile locks underlying targets; custom unlocks fields.
* **Autosave guard**: warn before leaving if unsaved changes exist.

---

## Permissions

* View: `service.view` (for prefill lists like SLA profiles)
* Create: `service.create`
* Advanced (SKU generator, indexing defaults): `tech.admin` or above
* Only `superuser` can bypass SKU collision in extreme cases (with audit reason)

---

## Audit & Logging

* On create, log all core fields and policy toggles.
* If SKU collision override by superuser, require mandatory comment.
* Capture user, timestamp, and diff snapshot for initial version (service creation only).

---

## Right Rail Widgets (suggested)

* **Readiness Checklist**: highlights missing mandatory fields before enabling Save
* **Impact Hint**: “This service will be snapshotted into contracts; SKU cannot be changed there.”
* **Quick Links**: to `tech.cs.services.index` and `tech.admin.settings.services`

---

## QA Scenarios (high level)

* Creating a service with duplicate SKU shows collision warning and blocks Save (unless superuser override with reason).
* Binding required + duration = 0 prevents form submit.
* Changing billing model updates computed monthly estimation in rail.
* Selected SLA profile locks targets; switching to custom unlocks them.
* Save & New returns a cleared form with success confirmation.

---

## Notes

* No HTML code in this doc; describes components and behaviors only.
* Dashboard is static; content is live-updating as fields change.
* Follows the same top/main/rail layout as other tech views.
