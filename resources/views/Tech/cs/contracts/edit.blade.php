# tech.cs.contracts.edit – View Specification

**Date:** 2025-10-17
**URL:** `tech.cs.contracts.edit` → `/tech/cs/contracts/{contract}/edit`
**Access levels:** `contract.edit`, `contract.admin`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\CS\\ContractsController@edit` (GET) / `@update` (PUT/PATCH)
**Status:** Not completed
**Difficulty:** High
**Estimated time:** 4.5 hours

---

## Purpose

Edit an existing client-scoped contract while enforcing **binding rules**, **approval locks**, and **global settings**. Supports superadmin override with audit reason. Uses live calculations for totals and indexing limits. Snapshot principle remains: services in the contract keep their stored snapshot values unless explicitly overridden here.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** edit, lock, unlock, calendar, percent, alert-triangle, scales, rotate-cw, save, file-text, shield.

* **Top header**

  * Title: “Edit Contract #ID” (ID is DB auto-increment)
  * Status chips: Draft / Active / Pending Renewal / Expired / Floating / Terminated / Suspended / Approved (Pending Start)
  * Primary actions: `Save`, `Save & Close`
  * Secondary: `Cancel` (back to show), `Print/Send PDF` (opens show page action)

* **Main content** (grouped fieldsets)

  1. **Client & Period**

     * Client (read-only)
     * Start date (editable if allowed; backdating logs audit)
     * End date (nullable for open-ended)
     * Binding enabled (toggle) + Binding end date (auto from duration or manual if policy allows)
     * Auto-renew (toggle) + Renewal rule (duration)
  2. **Indexing Policy**

     * Allow price indexing during binding (toggle)
     * Max indexing % (number)
     * Post-binding index % (separate field)
     * Apply decreases during binding (toggle; policy can lock)
  3. **Services (flat table)**

     * Each row shows: Name, SKU (read-only), Billing interval, Unit price, Caps (users/assets/hours), SLA policy, Discount, Setup fee, Notes for invoice
     * Row-level actions: `Edit` (inline drawer), `Remove` (policy-gated), `Restore defaults from service` (snapshot refresh), `Duplicate row`
     * Button below table: `Add Service` (opens side drawer with search + override form)
  4. **Contract Terms & Notes**

     * Contract terms (rich text)
     * Internal notes (not exposed to client)

* **Right slim rail**

  * **Status & Binding Card**: current status, binding window,

    * badges: `Floating` when binding passed and contract continues
  * **Totals Card**: computed monthly total, next renewal date, last indexed at
  * **Policy Summary**: indexing limits, downgrade rules (derived from settings + service-level)
  * **Risk/Conflicts**: warnings if edits violate policy (e.g., downgrade not allowed, binding lock)
  * **Audit Preview**: list of fields changed (dirty diff) prior to save
  * **Actions** (shortcuts): `Run Price Indexing`, `Terminate (now/period end)`, `Renew`

---

## Components (Livewire)

* **`ContractEditor`** – orchestrates form state, server validation, live totals, and locks.
* **`ServiceLinesTable`** – editable table with pagination for large contracts, exposes row-level events.
* **`ServiceLineDrawer`** – side panel for editing a single service line (all fields except SKU overridable).
* **`IndexingPolicyPanel`** – manages binding vs. post-binding index settings and validation.
* **`BindingGuard`** – enforces binding-time restrictions; shows locks and reasons; supports superadmin override modal.
* **`AuditDiffPanel`** – computes and renders diff summary before save.
* **`PolicyWarnings`** – aggregates rule violations and displays non-blocking/blocking banners.

Reusable elements: shared number inputs (currency-aware), shared date pickers, shared badges for status/binding.

---

## Behaviors & Validation

* **Edit Locks**

  * If contract is **awaiting approval** (sent for e-sign), the contract is **locked**. Editing requires `Cancel approval` which invalidates the link; then editing is allowed and a new approval must be sent from show view.
  * During **binding**, disallow **downgrades/removals** if global/service policy forbids it. Allow upgrades/add-ons if settings permit.
  * **Superadmin override** can bypass specific locks after confirming a mandatory audit reason.

* **Service Line Rules**

  * SKU is immutable.
  * All other fields are overridable; validation ensures positive numbers and compliant intervals.
  * `Restore defaults from service` pulls latest service catalog data and applies within allowed scope; if binding-lock forbids, action is disabled.

* **Indexing Rules**

  * Live validation of max % during binding.
  * Decreases can be applied immediately when allowed; if not allowed in binding per settings, toggle is disabled with explanation.

* **Totals**

  * Monthly total recomputed on every relevant change (unit price × qty; interval normalized to monthly equivalent for display only).

* **Conflict Detection**

  * Terminate-at-period-end vs Auto-renew: show conflict banner; require explicit choice.
  * Backdate start when already Active: warn and require confirmation.

* **Save Workflow**

  * `Save` applies changes and writes audit entries (user, timestamp, reason when override).
  * `Save & Close` returns to show page.

---

## Permissions Matrix

* View edit form: `contract.edit`
* Modify binding/indexing policies: `contract.admin | tech.admin | superuser`
* Remove service lines during binding: policy-gated; override requires `superuser`
* Cancel approval (unlock): `contract.admin | tech.admin | superuser`
* Run Price Indexing shortcut: `contract.admin | tech.admin | superuser`

---

## Notifications & Audit

* On save: create audit log with changed fields, old/new values, and any override reason.
* Optional notifications (per settings) when: binding toggled, indexing policy changed, or significant price delta detected (> configured threshold).

---

## Right Rail Widgets (suggested)

* **Binding Timeline** (start → binding end → renewal) with markers
* **Indexing Health** (last index run, % applied YTD)
* **Change Impact** (delta monthly vs previous)

---

## Edge Cases

* Contract in `Floating` status: editing allowed as per post-binding rules.
* Contract `Terminated` or `Suspended`: editing disabled except admin actions (reactivate/notes).
* Future-dated start with Approved status: editable within policy; saving may flip to Active if backdated to past.

---

## QA Scenarios (high level)

* Attempt to remove a bound service when downgrade is disallowed → blocked with policy message; allowed with superadmin override + audit reason.
* Edit while awaiting approval → forced to cancel approval before fields become editable; link invalidated.
* Increase unit price and verify monthly total updates; audit shows changed lines.
* Toggle indexing allowed and set max %; run indexing shortcut and verify capping behavior.
* Restore defaults from service catalog for a line; verify snapshot overwrite within policy.

---

## Reuse & Dependencies

* Shares `ServiceAddPanel` and badges with `tech.cs.contracts.create` and `tech.cs.contracts.show`.
* Currency and interval settings pulled from global settings.

---

## Controllers (mirrored folder structure)

* `App\\Http\\Controllers\\Tech\\CS\\ContractsController@edit` (view)
* `App\\Http\\Controllers\\Tech\\CS\\ContractsController@update` (persist)

---

## Notes

* No HTML code in this doc; components and behavior only.
* Dashboard/layout is static; content is dynamic with live updates.
* Designed for GitHub Copilot to infer component structure and reuse patterns.
