@extends('layouts.default_tech')

@section('title', 'Service Settings')

@section('pageHeader')
    <h1>Service Settings</h1>
@endsection

@section('content')
# tech.admin.settings.cs.services – View Specification

**Date:** 2025-10-17
**URL:** `tech.admin.settings.cs.services` → `/tech/admin/settings/cs/services`
**Access levels:** `service.settings.manage`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\Admin\\Settings\\CS\\ServicesController@index`
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 3.5 hours

---

## Purpose

System-wide configuration for the **Service Catalog** that feeds contracts. Controls SKU generation, category taxonomy, default billing models, binding rules, indexing defaults, and deprecation/visibility policies. Ensures catalog consistency and safe reuse across clients and contracts.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** settings, layers, tag, barcode, calendar, percent, shield, toggle-right, bell, save, refresh-cw, alert-triangle.

* **Top header**

  * Title: “Service Settings”
  * Buttons: `Save`, `Revert to Defaults`

* **Main content (fieldsets)**

  1. **SKU Policy**

     * SKU format hint (read-only help)
     * Auto-generation: prefix (text), numeric start (e.g., 10000), padding (digits)
     * Collision handling: block save / warn only (radio)
     * Manual override allowed (checkbox; default ON)
  2. **Categories & Taxonomy**

     * Manage categories (add, rename, deactivate)
     * Default category for new services
     * Enforce category selection (checkbox)
  3. **Defaults for New Services**

     * Default billing model: Per user / Per asset / Fixed / Tiered
     * Default billing interval: Monthly / Quarterly / Yearly
     * Default currency (from global settings; read-only link)
     * Default SLA profile (select)
     * Default visibility: Active / Hidden
  4. **Binding & Downgrade Rules**

     * Allow binding by default (checkbox)
     * Default binding duration (months)
     * Downgrade allowed during binding (checkbox; default OFF)
     * Respect service-level binding in contracts (info: enforced via contract settings)
  5. **Indexing Defaults**

     * Suggest allow price indexing (checkbox)
     * Suggested max indexing % (numeric)
     * Suggested allow decreases (checkbox)
     * Note: contracts can restrict these further
  6. **Deprecation & Visibility**

     * Deprecation policy: allow deprecate when linked to active contracts (block/warn/allow)
     * Hide deprecated from search by default (checkbox)
     * Require reason for deprecate (checkbox)
  7. **Notifications**

     * Notify admins when a service is created/edited/deprecated (toggles)
     * Recipients: service.editors, tech.admin, superuser (checkboxes)

---

## Components (Livewire)

* **`ServiceSettingsPanel`** – master controller for all panels and save logic.
* **`SkuFormatEditor`** – handles prefix, start, padding; shows live example (read-only preview).
* **`CategoryManager`** – CRUD for categories with usage counts and deactivate flow.
* **`DefaultsPanel`** – billing/SLA/visibility defaults.
* **`BindingRulesPanel`** – binding and downgrade defaults.
* **`IndexingDefaultsPanel`** – suggested indexing/decrease options.
* **`DeprecationVisibilityPanel`** – controls for deprecate/hide behavior.
* **`NotificationsPanel`** – toggles and recipients.
* **`CronStatusWidget`** (rail, optional) – links to indexing cron status page.

---

## Right Slim Rail

* **Catalog Health**: total services, active/deprecated/hidden counts (cached KPI)
* **SKU Health**: duplicates detected, next generated SKU preview
* **Recent Changes**: last 5 setting edits (audit snapshot)
* **Quick Links**: `Services`, `Contracts`, `Global SLA Profiles`, `Contract Settings`

---

## Behaviors & Validation

* Validate numeric ranges (start, padding, percentages, months).
* Changing SKU generator updates the next SKU preview immediately.
* If collision policy is set to block, saving a service with duplicate SKU is refused.
* Category deactivation warns if services still use it; offers bulk remap flow (link to services index with prefilter).
* Defaults apply to `tech.cs.services.create` as initial values.
* All changes are audited with before/after values.

---

## Permissions

* View: `tech.admin`
* Edit/Save: `service.settings.manage`
* Revert to defaults: `superuser`

---

## Telemetry & Audit

* Logs for every change with user, timestamp, and field diffs.
* Notification logs when admin alerts are sent on service events.

---

## Edge Cases

* Reducing padding or changing prefix might collide with existing SKUs → show computed conflict count and require confirmation.
* Disallowing manual SKU override may block current drafts that rely on it → warn before saving.
* Deactivating a heavily used category prompts bulk-remap guidance.

---

## QA Scenarios (high level)

* Change SKU start to 20000, create a new service, verify SKU matches preview.
* Set deprecate=block when linked; attempt to deprecate a service in use; action refused with policy message.
* Toggle default billing model and see it reflected as default in `tech.cs.services.create`.
* Deactivate a category and verify services index prefilters to impacted rows via quick link.

---

## Notes

* No HTML or code; this is a behavioral/UX spec for developers and GitHub Copilot.
* Follows top/main/rail layout; content updates live via Livewire.
* Settings here influence both `tech.cs.services.*` and `tech.cs.contracts.*` flows.
@endsection

@section('sidebar')
    <h3>Tech Sidebar</h3>
    <ul>
        <li><a href="#">System Status</a></li>
        <li><a href="#">Task Management</a></li>
        <li><a href="#">Reports</a></li>
    </ul>
@endsection

@section('rightbar')
    <h3>Notifications</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection