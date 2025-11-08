# tech.admin.settings.email.config.index

**URL:** `/tech/admin/settings/email/config`

**Access & Permissions:**

* Roles: `superadmin`, `emailadmin`
* Permission required: `email.settings.manage`

**Creation date:** 2025-10-23

**Controller (PSR-4 path):** `App\\Http\\Controllers\\Tech\\Admin\\Settings\\Email\\ConfigController@index`

**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 3.0 hours

---

## 1) Purpose

System-wide configuration of the Email Hub: transport/security, ingest/polling, identification/threading policy (read-only), retention, error thresholds, and outbound defaults. This page defines how email behaves globally; per‑account details remain under **Accounts**, and rule logic under **Rules**.

---

## 2) Layout (Bootstrap template)

Top header / Main content / Right slim panel

### Header

* Title: **Email Configuration**
* Buttons: `Save`, `Reset to Defaults`, `Run Health Test`
* Breadcrumbs: Settings → Email → Config

### Main Sections (cards)

1. **Ingest & Polling**
   **Fields**

   * *Global poll interval* (select): 1 min, 5 min (default), 15 min, 30 min.
   * *Max concurrent fetch* (select): 1, 2 (default), 4, 8.
   * *Pause all ingest* (toggle): Off (default). Tooltip: Maintenance mode for IMAP polling.
   * *Retry/backoff policy* (inputs): `1m → 5m → 15m` (editable).
   * *Alarm after consecutive failures* (number): default 3.

   **Validation**: poll ≥ 1 min; max fetch ≥ 1; backoff ascending.

2. **Security & Transport**
   **Fields**

   * *Require encryption (inbound)* (select): `TLS/SSL required` (default), `Allow STARTTLS`, `Allow plain (discouraged)`.
   * *Require encryption (outbound)* (select): `TLS/SSL required` (default), `Allow STARTTLS`, `Allow plain (discouraged)`.
   * *Allowed ciphers/min TLS* (select): System default (recommended), TLS 1.2+, TLS 1.3.
   * *Credentials storage note* (read-only): Secrets are masked; re-entry required to change.

   **Validation**: If “plain” chosen, confirmation modal.

3. **Identification & Threading (Policy)**
   **Content (read-only info with toggles locked)**

   * Precedence: **Headers** (Message-ID / In-Reply-To / References) over **Subject token**.
   * Subject token format (info): `[#{number}]` used across modules.
   * Conflict handling: Prefer headers; log discrepancy.

4. **Defaults & Handover**
   **Fields**

   * *Unknown/ambiguous messages* (read-only): Route to **Global Inbox (Needs Triage)**.
   * *Known contact or mapped domain* (read-only): Create **Ticket** (handover to Ticket module).

5. **Retention & Deletion**
   **Fields**

   * *Delete on success* (toggle): On (default). Help: Remove message from server after successful processing.
   * *Fallback Inbox retention (days)* (number): default 30.
   * *Spam/Trash retention (days)* (number): default 30.
   * *Attachment retention (months)* (number): default 12.

   **Validation**: days ≥ 0; months ≥ 0.

6. **Outbound Defaults**
   **Fields**

   * *Outbound account selection* (read-only default): Use account tied to owning module/entity.
   * *Allow override sending account* (multi-select roles/permissions): `technician`, `ticket.admin` (default enabled).
   * *Signature and language source* (info): From sending account profile.

7. **Rules & Parser Interop (Behavior Defaults)**
   **Fields**

   * *Global rules evaluation default* (toggle): `Continue evaluation` (On by default).
   * *Destructive actions require confirmation* (toggle): On (default).
   * *Parser scan limits* (inputs): `Max body scan length (KB)` default 256; `Per-message parser time limit (ms)` default 200.

   **Notes**: Rule ordering and parser profile order are managed on their dedicated pages. These toggles define platform defaults used by their UIs.

8. **Errors & Health**
   **Fields**

   * *Raise dashboard alert after X failures* (number): default 3.
   * *Acknowledge resolved alerts requires* (select): `emailadmin` or `superadmin`.

   **Actions**

   * `Run Health Test`: IMAP/SMTP connectivity check for all enabled accounts + rule engine dry-run; results appear in right panel widget.

---

## 3) Right Slim Panel (widgets)

* **System Health** (badges): ✓ OK, ⚠ Warning, ✕ Error — counts and last sync time.
* **Recent Errors**: last 5 IMAP/SMTP failures with account name and time; `Acknowledge` button.
* **Shortcuts**: `Open Accounts`, `Open Rules`, `Open Parser Profiles`, `Open Logs & Health`, `Open Fallback Inbox`.

Icons (suggested, lucide): `shield`, `mail`, `activity`, `triangle-alert`, `check-circle`, `x-circle`, `wrench`, `play`.

---

## 4) Components & Controls (reusable)

* `Form.Select`, `Form.Toggle`, `Form.Number`, `HelpPopover`, `ConfirmModal`, `HealthBadge`, `RightPanel.Widget`, `List.InlineErrors`.

---

## 5) Behaviors & Validation

* **Save**: Persist changes; show toast “Saved successfully”.
* **Reset to Defaults**: Confirm modal; revert to system defaults.
* **Run Health Test**: Non-destructive diagnostics; updates right panel.
* Client-side validation before submit; server-side recheck; all changes audited (who/what/when).

---

## 6) Audit & Logging

* All changes (before/after where safe; secrets masked) logged in Audit with actor and timestamp.
* Health acknowledgements logged with actor and reason (optional).

---

## 7) Permissions Matrix (summary)

* View page: `email.admin`
* Edit settings: `email.settings.manage`
* Run Health Test: `email.settings.manage`
* Acknowledge alerts: `emailadmin` or `superadmin` (configurable selector above)

---

## 8) Out of Scope (here)

* Managing accounts (go to Accounts)
* Editing rule definitions/order (go to Rules)
* Editing parser profiles/order (go to Parser Profiles)
* Per-account polling overrides (set under Accounts)

---

## 9) QA Checklist

* Poll interval applies; per-account overrides respected.
* Deletion on success confirmed with live inbox test.
* Header-vs-token precedence enforced and logged.
* Unknown routing → Global Inbox verified.
* Health test lists failing accounts with clear messages.
* Retention jobs pick up configured values.

---

## 10) Notes for Developers

* Expose config via `config('emailhub.*')` + DB-backed settings table; cache with tag `emailhub`.
* Health test dispatches a queued job per account; aggregate in a temporary table or cache for UI.
* Use policy checks (`Gate::allows('email.settings.manage')`) on write paths.
* Emit domain events for changes (e.g., `EmailConfigUpdated`).
