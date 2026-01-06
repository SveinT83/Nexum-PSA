# tech.admin.settings.email.accounts.index – View Specification

**URL / Route:** `tech.admin.settings.email.accounts.index`

**Access & Permissions:** `settings.email.accounts.view` (min). Edit button requires `settings.email.accounts.edit`.

**Creation date:** 2025-10-22

**Controller path:** `App\\Http\\Controllers\\Tech\\Admin\\Settings\\Email\\AccountsController@index`

**Status:** In progress

**Difficulty:** Low

**Estimated time:** 1.5 hours

---

## Purpose

A simple, fast index that lists all configured email accounts and their operational state. From here the user can add a new account or open an existing one to edit. No filtering/sorting. Minimal, high-signal layout.

## Layout (Bootstrap template)

* **Top section (page header)**

  * Title: “Email Accounts”
  * Primary action: **Add account** (button)
  * Context info: total count badge
* **Main section**

  * Accounts table (compact, two-line capable on narrow screens)
* **Right-side narrow panel**

  * Static help: brief legend for labels (no tooltips on icons), link to email settings overview

## Table Columns & Cells

1. **Account / Address**

   * Primary text: email address
   * Secondary (muted): provider/host summary (optional), e.g., "IMAP/SMTP"
   * Error icon (left of address if present): warning triangle (no tooltip)
2. **Status**

   * Pill showing `Active` or `Disabled`
   * Inline **Activate/Deactivate** control (small toggle button)
3. **Defaults**

   * Text labels with subtle background to indicate defaults:

     * `Default (Global)`
     * `Default (Tickets)`
     * `Default (Sales)`
     * `Default (Alerts)` (prefix with warning triangle icon)
   * Multiple labels may appear on the same row
4. **Actions**

   * **Edit** (button) → navigates to `tech.admin.settings.email.accounts.edit` for the selected ID

## Business Rules & Display Logic (read-only on index)

* Exactly **one** `Default (Global)` may exist system-wide. If present, it acts as fallback for all systems that lack an explicit default.
* Each system (**Tickets**, **Sales**, **Alerts/System**) can have **at most one** default account. One account may be default for multiple systems.
* The platform can operate with **a single** email account; in such cases it may be both global and system defaults.
* Rows with recent connection errors display a **warning triangle** icon. No hover text on the index.
* No testing actions on the index (IMAP/SMTP tests live on the edit view).
* No list sorting or filtering.

## Interactions

* **Add account** (top-right): opens create form `tech.admin.settings.email.accounts.create`.
* **Edit**: opens edit view for the account.
* **Activate/Deactivate**: immediate visual toggle in the Status column. Confirmation pattern uses standard modal from template; success/failure feedback via global alert region under header.

## Empty / Edge States

* **No accounts yet**: Show neutral placeholder with message “No email accounts configured” and a prominent **Add account** button.
* **All accounts disabled**: Still list rows with `Disabled` status; defaults section remains visible for clarity.

## Components & Reuse

* **ListHeader** (reusable): title, count badge, primary action.
* **AccountsTable** (reusable): basic table shell used across settings indexes.
* **StatusPill** (reusable): `Active` / `Disabled` indicator.
* **DefaultLabel** (reusable): text badge with background. Variants: Global/Tickets/Sales/Alerts (Alerts variant prepends triangle icon).
* **WarningIcon** (reusable): triangle, no tooltip.
* **InlineToggle** (reusable): small button used for Activate/Deactivate.
* **PageHelpRail** (reusable): right-side panel with short legend.

## Icons & Labels

* Warning triangle: indicates account error on row. No tooltip.
* Alerts default label: triangle + text: `Default (Alerts)`.

## Accessibility & UX Notes

* Table rows are keyboard-focusable; **Edit** and **Activate/Deactivate** are reachable via tab order.
* Labels include concise ARIA text (e.g., `aria-label="Default Global"`).
* Avoid color-only meaning: status pills and default labels include text.

## Telemetry & Logging (view events)

* Log UI events (non-PII): `email_accounts_index_opened`, `click_add_account`, `click_edit`, `toggle_status` (with account ID hash).

## Error Handling (surface only)

* Inline failure on toggle shows error alert in page header region and reverts control state.

## Non-Goals (this view)

* No testing actions (IMAP/SMTP).
* No per-row delete here (handled on edit or separate confirmation flow, if enabled later).
* No sorting/filtering UI.