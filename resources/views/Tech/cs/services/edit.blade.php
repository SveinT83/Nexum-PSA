# tech.cs.services.edit – View Specification

**Date:** 2025-10-17
**URL:** `tech.cs.services.edit` → `/tech/cs/services/{service}/edit`
**Access levels:** `service.edit`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\CS\\ServicesController@edit` (GET) / `@update` (PUT/PATCH)
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 3.5 hours

---

## Purpose

Edit an existing **service offering** in the catalog while respecting SKU integrity and downstream contract stability. Changes here affect **future contract snapshots**; existing contracts retain their stored snapshot values unless a refresh is explicitly applied from contract views.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** edit, layers, tag, barcode, calendar, percent, shield, alert-triangle, save, history, copy.

* **Top header**

  * Title: “Edit Service – {Service Name}”
  * Status chip: Active / Hidden / Deprecated
  * Buttons: `Save`, `Save & Close`, `Cancel`

* **Main content** (grouped fieldsets)

  1. **Identity**

     * Service name (required)
     * Category (select)
     * Visibility (Active / Hidden / Deprecated)
  2. **SKU & Catalog**

     * SKU (read-only if policy forbids change; otherwise editable with collision checks)
     * `Generate SKU` button (if allowed) with live preview
     * Collision warning component
  3. **Pricing & Billing**

     * Billing model (Per user / Per asset / Fixed / Tiered)
     * Unit price (currency from settings)
     * Billing interval (Monthly / Quarterly / Yearly / Custom)
     * Setup fee (optional)
     * Discount default (percent or amount)
  4. **Inclusions & Caps**

     * Included users / assets / hours (optional)
     * Caps/overage policy fields
  5. **SLA Defaults**

     * SLA policy (select profile or custom)
     * Response/Resolution targets (read-only from profile or editable if custom)
  6. **Binding & Downgrade**

     * Binding required (checkbox)
     * Binding duration (months)
     * Downgrade allowed during binding (checkbox)
     * Notes
  7. **Indexing Defaults**

     * Suggest allow price indexing (checkbox)
     * Suggested max indexing % (numeric)
     * Allow decreases (checkbox)
  8. **Terms & Descriptions**

     * Short description
     * Invoice description
     * Service terms (rich text)

* **Right slim rail**

  * **ImpactCard**: shows number of **linked contracts** (count) with quick link to filter contracts by this service
  * **PreviewCard**: summarized read-only preview of key fields
  * **PolicySummary**: binding, downgrade, indexing defaults
  * **AuditTrail (recent)**: last 5 changes to this service
  * **Quick Actions**: `Duplicate`, `Deprecate`, `Hide/Unhide`

---

## Components (Livewire)

* **`ServiceEditor`** – orchestrates field state and validation.
* **`SkuCollisionAlert`** – checks SKU uniqueness on blur + pre-save.
* **`PricingPanel`** – manages price, interval, and discount with live computed monthly equivalent.
* **`BindingRulesPanel`** – controls binding/downgrade inputs with policy hints.
* **`SlaSelector`** – profile vs custom handling.
* **`TermsEditor`** – rich text editor with word count.
* **`SideRail`** – renders preview, impact, and quick actions.

Reusable: shared currency inputs, select with typeahead for categories and SLA profiles, shared status chips.

---

## Behaviors & Validation

* **SKU policy**

  * If `service.settings.manage` forbids manual SKU editing, field is read-only; show help text with reason.
  * Collision policy: block or warn based on admin settings; superuser can override with reason.
* **Status changes**

  * Deprecating a service warns if active contracts reference it; follows global policy (block/warn/allow).
  * Hidden services do not appear in default contract searches unless explicitly filtered.
* **Pricing model**

  * Changes recalculate previewed monthly equivalent in rail (display only).
  * Tiered model shows inline helper link to open a tier editor (out of scope here).
* **SLA**

  * Selecting a profile locks target fields; `Custom` unlocks them.
* **Binding**

  * If binding required is enabled, duration > 0 enforced.
* **Indexing defaults**

  * Provide hints that contracts may further restrict during binding.

---

## Permissions

* View: `service.view`
* Edit/Save: `service.edit`
* Override SKU collision or edit SKU when restricted: `superuser`
* Deprecate/Hide: `service.edit` or `tech.admin` (subject to global policy)

---

## Audit & Notifications

* On save: audit log with field diffs, user, timestamp; if override, require comment.
* Optional notifications to admins on deprecate/hide or significant price change (> threshold from settings).

---

## Right Rail Widgets (suggested)

* **Linked Contracts**: count + link to `tech.cs.contracts.index` prefiltered by this service SKU
* **Recent Changes**: last 5 audit events
* **Policy Summary**: binding/indexing/downgrade at-a-glance

---

## Edge Cases

* Attempt to change SKU when duplicate exists and policy is block → show modal and prevent save.
* Deprecate a service used by active `Floating` contracts → show stronger warning; allow/deny per settings.
* Decrease unit price: note that contracts may be updated by indexing job if allowed.

---

## QA Scenarios (high level)

* Edit price and ensure monthly preview updates.
* Try to change SKU to an existing one; collision handling follows settings.
* Deprecate a service with 10 linked contracts; see warning and behavior per policy.
* Toggle binding required and set duration; validation enforces duration > 0.

---

## Notes

* No HTML in this document; components and behaviors only.
* Static layout; content is live-updating via Livewire interactions.
* Designed for developer handoff and GitHub Copilot comprehension.
