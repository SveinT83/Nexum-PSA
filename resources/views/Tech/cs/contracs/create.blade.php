# tech.cs.contracts.create – View Specification

**Date:** 2025-10-17
**URL:** `tech.cs.contracts.create` → `/tech/cs/contracts/create`
**Access levels:** `contract.create`, `contract.admin`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\CS\\ContractsController@create` (GET) / `@store` (POST)
**Status:** Not completed
**Difficulty:** High
**Estimated time:** 5.0 hours

---

## Purpose

Create a new **client-specific contract**, define its terms, binding, and pricing. The contract acts as a snapshot of chosen services with overrideable fields. The process ensures contract stability while allowing flexible pricing, auto-renewal, and indexation control.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** plus-circle, layers, tag, percent, calendar, file-text, refresh-cw, check-circle, alert-triangle, save.

* **Top header**

  * Title: “Create Contract”
  * Buttons: `Save`, `Cancel`, `Preview PDF`
  * Display auto-assigned ID (starts at 10000; generated on save)

* **Main content (fieldsets)**

  1. **Client Selection**

     * Dropdown/autocomplete (search clients)
     * Once selected, locks client for the remainder of the session.
  2. **Contract Period**

     * Start date / End date
     * Binding enabled (checkbox) + Binding duration (months)
     * Auto-renew toggle + Renewal duration (months)
     * “Floating allowed after binding” (checkbox)
  3. **Indexing Policy**

     * Allow price indexing during binding (checkbox)
     * Max indexing % (numeric)
     * Post-binding index % (optional field)
     * Apply decreases during binding (checkbox)
  4. **Add Services** (core section)

     * Button: `Add Service` (opens right-side drawer)
     * Flat table showing added services with key info:

       * Name, SKU (read-only), Billing interval, Unit price, SLA, Caps, Discount, Setup fee
       * Row actions: Edit (drawer), Remove, Duplicate
     * Totals recalculated live (unit price × interval normalization)
  5. **Terms & Legal**

     * Terms preview compiled dynamically from selected services + global defaults
     * Editable rich text for additional clauses (contract-level)
     * Internal notes (non-public)

* **Right slim rail**

  * **SummaryCard**: live totals (monthly equivalent), binding end, next renewal
  * **PolicySummaryCard**: highlights binding/indexing selections
  * **AuditInfo**: shows user creating + timestamp
  * **Save actions**: `Save Draft`, `Save & Send for Approval`

---

## Components (Livewire)

* **`ContractCreator`** – orchestrates the full workflow; holds client, terms, totals.
* **`ServiceAddPanel`** – side drawer for adding one service at a time (search existing, configure overrides, add).
* **`ContractTotals`** – reactive computation of totals based on service lines.
* **`IndexingPanel`** – handles both binding and post-binding index rules.
* **`BindingDurationPanel`** – computes binding end and renewal markers.
* **`ContractTermsEditor`** – merges service-level terms and allows inline additions.
* **`ApprovalOptions`** – define approval path (send email link, internal record).

Reusable: shared number/date inputs, client autocomplete, table component.

---

## Behaviors & Validation

* **Client** must be selected before services can be added.
* **Service addition** uses right-side drawer → adds line with snapshot fields.
* **Live recalculation** of totals as services are added/edited/removed.
* **Binding validation**: if binding enabled, duration > 0 required.
* **Indexing rules**: allow/decrease toggles depend on policy in settings.
* **Duplicate prevention**: identical service/SKU cannot be added twice unless multi-instance allowed.
* **Save as Draft** allowed anytime; unapproved contracts can be reopened.
* **Approval flow**: sending triggers email with approve/decline link (7-day expiry, configurable).
* **Cancellation of approval** invalidates prior link.

---

## Right Rail Widgets

* **Totals Summary**: monthly/annualized cost, included services count
* **Indexing Health**: displays max allowed % and whether decreases apply
* **Renewal Overview**: renewal rule and next review date
* **Checklist Widget**: visual completeness indicator (client chosen, services added, binding valid, terms ready)

---

## Permissions

* View client list: `client.view`
* Create: `contract.create`
* Run indexing immediately after create (manual): `contract.admin | tech.admin | superuser`
* Send for approval: `contract.create | contract.admin`

---

## Audit & Notifications

* Audit log on create with all major fields (client, binding, indexing, totals, services snapshot).
* Optional notification to admin when new draft created.
* Approval email: logs IP, time, and approver metadata.

---

## Edge Cases

* Start date > end date → validation error.
* Adding service missing SKU → blocked until fixed in catalog.
* Attempting to bind for 0 months → error.
* Duplicate service in same contract → blocked unless multi-instance flag set.

---

## QA Scenarios (high level)

* Create contract for a client with binding 12 months and 3 services; totals update live.
* Disable binding; verify floating option toggles on.
* Set max indexing % and post-binding index %; verify live preview in rail.
* Send for approval; contract becomes locked; editing blocked until canceled.
* Add service with discount and verify monthly recalculation reflects reduction.

---

## Notes

* No HTML or code here—only logical structure and UI definition.
* Dashboard/layout static; all dynamic content handled via Livewire.
* Consistent top/main/rail layout with other tech.cs modules.
