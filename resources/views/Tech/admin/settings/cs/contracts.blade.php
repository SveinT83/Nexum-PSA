# tech.admin.settings.cs.contracts – View Specification

**Date:** 2025-10-17
**URL:** `tech.admin.settings.cs.contracts` → `/tech/admin/settings/cs/contracts`
**Access levels:** `contract.settings.manage`, `tech.admin`, `superuser`
**Controller:** `App\\Http\\Controllers\\Tech\\Admin\\Settings\\CS\\ContractsController@index`
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 4.0 hours

---

## Purpose

Provide global configuration for **contract behavior, binding policies, indexing rules, notifications, and renewal logic**. Defines defaults that apply across all client contracts and may be overridden at contract or service level where allowed.

---

## Design & Layout (Bootstrap)

**Template regions:** Top header / Main content / Right slim rail.
**Icons (suggested):** settings, shield, percent, calendar, refresh-cw, bell, alert-triangle, lock, toggle-right, save.

* **Top header**

  * Title: “Contract Settings”
  * Buttons: `Save`, `Revert to Defaults`

* **Main content (fieldsets)**

  1. **General Contract Policy**

     * Default renewal behavior: auto-renew / must-renew / floating after binding
     * Allow contracts without binding (checkbox)
     * Default binding duration (months)
     * Allow downgrade during binding (checkbox)
     * Allow backdating contracts (checkbox; admin only)
  2. **Indexing Rules**

     * Allow price indexing during binding (checkbox)
     * Max indexing % (numeric, global cap)
     * Allow decreases during binding (checkbox)
     * Default post-binding index % (numeric)
     * Global cron frequency for indexing (Monthly fixed; info display)
     * Cron health indicator (last run timestamp, duration, status)
  3. **Notifications**

     * Global toggles for event-based notifications:

       * Renewal reminders (default ON)
       * Termination alerts (default ON)
       * Indexing events (default OFF)
     * Recipient roles (checkboxes): contract.admin, tech.admin, superuser
     * Enable per-contract override (checkbox; default ON)
  4. **Approval & Workflow**

     * Default approval email expiry (days; default 7)
     * Allow contract edit during approval (checkbox; default OFF)
     * Require comment on superadmin overrides (checkbox; default ON)
     * Record IP and timestamp on approval (always ON; read-only info)
  5. **Service-Level Rules**

     * Respect service binding (checkbox; enforces service-specific binding restrictions)
     * Default service binding priority: Service > Global (radio)
     * Prevent removal of bound services (checkbox; default ON)
  6. **Data Retention & Archiving**

     * Retain terminated contracts for X months (numeric; 0 = indefinite)
     * Retain audit logs for X months (numeric)
     * Allow manual purge by superuser (checkbox)

---

## Components (Livewire)

* **`ContractSettingsPanel`** – master component controlling all subpanels.
* **`IndexingRulesPanel`** – manages index/decrease toggles and limits.
* **`NotificationsPanel`** – toggles and recipients.
* **`WorkflowPanel`** – approval and override policies.
* **`ServiceBindingPanel`** – rules about service binding and downgrades.
* **`DataRetentionPanel`** – retention settings and purge actions.
* **`CronStatusWidget`** (rail) – last cron run, next scheduled, errors.

---

## Right Slim Rail

* **System Health**: indexing cron status, queue health, notification delivery log count.
* **Quick Links**: `Contracts`, `Services`, `Global SLA Profiles`.
* **Audit Log Snapshot**: last 5 setting changes.

---

## Behaviors & Validation

* Validation: numeric ranges for durations and percentages.
* Revert to Defaults resets to system preset values.
* Saving updates cached policy used by all contract-related modules.
* Changes write audit entries with before/after snapshots.
* Toggles that impact active contracts (e.g., downgrade policy) show warning modal.
* Notification toggles update linked background jobs instantly.

---

## Permissions

* View: `tech.admin`
* Edit/Save: `contract.settings.manage`
* Purge/Reset defaults: `superuser`

---

## Telemetry & Audit

* Log every change with user, timestamp, affected field, old/new values.
* System logs cron executions and notification delivery success/fail.

---

## Edge Cases

* Attempting to disable indexing globally while active indexing jobs queued: warning + must confirm.
* Setting binding duration = 0 while binding required globally: validation error.
* Deactivating notifications disables both global and per-contract triggers.

---

## QA Scenarios (high level)

* Change default binding duration; create new contract and verify applied.
* Disable global indexing; ensure indexing buttons hidden in contract views.
* Enable per-contract notification override; confirm toggle visible in contract editor.
* Adjust approval expiry; send contract for approval and verify new expiry date in email.
* Run Revert to Defaults; values match factory settings.

---

## Notes

* No HTML; describes logic, structure, and intended UX only.
* Static admin template; Livewire handles updates inline.
* Changes propagate to contract create/edit views dynamically.
