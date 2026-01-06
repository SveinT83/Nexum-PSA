# tech.cs.contracts.show – View Specification

**Date:** 2025-10-17
**URL:** `tech.cs.contracts.show` → `/tech/cs/contracts/{contract}`
**Access levels:** `contract.view`, `tech.view`, `contract.admin`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\CS\\ContractsController@show`
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 3.5 hours

---

## Purpose

Provide a comprehensive, read-first view of a **single client contract**, including its services (snapshot lines), pricing totals, binding state, indexing status, approval trail, and operational actions. Layout is static; content updates live.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** file-text, eye, calendar, percent, layers, tag, play-circle, rotate-cw, alert-triangle, check-circle, x-circle, printer, mail, link-2, lock.

* **Top header**

  * Title: “Contract #ID – {Client}”
  * Status chips: Draft / Active / Pending Renewal / Expired / Floating / Terminated / Suspended / Approved (Pending Start)
  * Primary actions (permission-gated): `Edit`, `Run Price Indexing`, `Renew`, `Terminate`
  * Secondary actions: `Print PDF`, `Send for Approval`, `Copy Approval Link`, `Cancel Approval` (if pending)

* **Main content**

  1. **Contract Summary** (read-only key/value)

     * Client, Start date, End date (nullable), Binding end, Auto-renew, Billing interval
     * Indexing allowed (Yes/No + max %), Post-binding index %, Last indexed at
     * Approval state (sent/approved/declined), Approval expiry (if sent)
  2. **Services (flat table)**

     * Columns: Name, SKU (read-only), Billing interval, Unit price, Caps (users/assets/hours), SLA policy, Discount, Setup fee, Notes for invoice
     * Footer: computed totals (monthly equivalent, count of services)
     * Row menu (permission-gated): `Open in editor`, `Restore defaults from service`, `Remove` (policy rules apply)
  3. **Terms**

     * Rendered contract terms compiled from service terms + contract-level additions (read-only)
  4. **Activity & Audit**

     * Recent events list (approval, indexing, edits, termination/renewal actions)

* **Right slim rail**

  * **Status & Binding Card**: current status, timeline markers (Start → Binding End → Renewal), `Floating` indicator when applicable
  * **Totals Card**: monthly total, annualized estimate, included services count
  * **Indexing Health**: last run, % applied YTD, quick `Run now` button
  * **Renewal Overview**: next renewal date, auto-renew flag, pending renewal window
  * **Quick Actions**: `Run Price Indexing`, `Renew`, `Terminate (now/period end)`, `Print/Send PDF`
  * **Approval Panel**: approval status, recipient, expiry countdown, buttons (`Copy link`, `Cancel`)

---

## Components (Livewire)

* **`ContractShow`** – loads contract, services, totals, and permissions; handles quick actions.
* **`ServiceLinesTable`** – read-only table with row menu (actions are permission/policy gated).
* **`TotalsWidget`** – computes monthly/annualized totals (normalized intervals) and re-renders live.
* **`BindingStatusWidget`** – calculates and renders binding/floating/renewal markers.
* **`IndexingWidget`** – shows last run, % changes, and exposes `Run now` event.
* **`ApprovalPanel`** – shows approval state, recipient, expiry; emits send/cancel/copy events.
* **`AuditStream`** – paginated recent activity for this contract.

Reusable elements: status chips, currency-aware number formatters, date badges, clipboard helpers.

---

## Behaviors

* **Live refresh** after actions (indexing, terminate/renew, approval send/cancel) updates widgets and table without full reload.
* **Approval lock awareness**: if an approval is pending, show a banner that editing requires cancellation (with link to edit page).
* **Conflict warnings**: banner if `Terminate at period end` conflicts with `Auto-renew` until resolved.
* **Access fallback**: users without edit rights see actions hidden; read-only remains fully functional.

---

## Actions (permission/policy gated)

* **Edit** → navigates to `tech.cs.contracts.edit`.
* **Run Price Indexing** → triggers job for this contract only; writes audit entry.
* **Renew** → opens prefilled editor (duration/pricing per policy) or wizard step (out of scope here).
* **Terminate** → modal: immediate or at period end; requires confirmation and reason if policy demands.
* **Print PDF** → generates printable view; can also `Send PDF via Email`.
* **Send for Approval** → emails approval link to configured recipient; sets approval state; shows expiry.
* **Copy Approval Link** → copies current link (if active); respects expiry.
* **Cancel Approval** → invalidates active link and unlocks editing.

---

## Permissions Matrix

* View: `contract.view`
* Edit/Terminate/Renew/Index: `contract.edit` + policy gates; global indexing requires `contract.admin | tech.admin | superuser`
* Approval send/cancel: `contract.create | contract.admin`

---

## Data & Performance

* Preload service lines, SLA profile names, and latest indexing/renewal metadata to avoid N+1.
* Normalize intervals to monthly equivalent for totals display only.
* Cache heavy widgets (audit list, KPI) with short TTL; invalidate on actions.

---

## Telemetry & Audit

* Log all quick actions with user, timestamp, and result (success/fail).
* Record approval events with recipient, IP, and expiry details.

---

## Edge Cases

* Contract approved with **future start**: show `Approved/Pending Start` with start date.
* Backdated activation: show Active; audit includes backdate entry.
* Terminated/Suspended: restrict actions and display reason in header banner.

---

## QA Scenarios (high level)

* Run indexing; verify capped increase and immediate decreases per policy; widgets update; audit entry created.
* Send for approval; banner shows locked state; cancel approval; actions unlock.
* Renew from `Floating` state; verify next dates and totals recalc.
* Print PDF and confirm all service lines and totals are present.

---

## Notes

* No HTML; describes components and behavior only.
* Standard top/main/rail layout; static structure with live data updates.
* Optimized for developer handoff and GitHub Copilot understanding.
