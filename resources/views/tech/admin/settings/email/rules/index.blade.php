# tech.admin.settings.email.rules.index

**Date:** 2025-10-23
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 3.0 hours

**Controller:**
`App\\Http\\Controllers\\Tech\\Admin\\Settings\\Email\\RulesController@index`

**Access:**

* superadmin
* tech.admin
* emailadmin (if defined)
* Permission required: `email.rules.manage`

**URL:**
`/tech/admin/settings/email/rules`

---

## 1. Purpose

Provide a central interface to manage **Global Email Rules** that run **before any module rules** (e.g., Tickets). Each rule has a **trigger** (inbound email ingest), multiple **conditions**, and multiple **actions**. Rules execute in a deterministic order to route, enrich, or delete messages—reducing manual triage and ensuring predictable behavior.

> Scope notes: Rules can apply to **all accounts** or a **subset of IMAP accounts**. Deletion is always explicit and audited.

---

## 2. Layout & Structure

**Template layout:** Header / Main / Right slim sidebar (Bootstrap)

### Components

* **Header bar**

  * Title: **Global Email Rules**
  * Buttons: `+ Add Rule`, `Edit`, `Duplicate`, `Delete`, `Enable/Disable`, `Test…`
  * Inline counters: Total, Active, Disabled

* **Main content (rules list)**

  * Columns:

    * **Weight** (sortable integer, default **10**)
    * **Rule name**
    * **Trigger** (fixed: `on_inbound`)
    * **Scope** (All accounts / Account list)
    * **Flow** (Continue / Stop)
    * **Status** (Active/Disabled)
  * Sorting: ascending by **weight**, then by **id** for same weight.
  * Row actions: Enable/Disable toggle, Edit, Duplicate, Delete.

* **Right sidebar (context panel)**

  * Selected rule metadata: ID, Created by, Updated at, Scope, Last hit (timestamp), Hit count (last 7/30d), Audit summary.
  * Help widget: order of execution, Continue/Stop semantics, destructive rule warnings.

---

## 3. Functional Behavior

| Feature                      | Description                                                                                                                                                                                                                                                 |                                                                                                                                           |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| **Weight-based order**       | Rules execute in ascending **weight** (lower = higher priority). For equal weight, database **ID** decides order.                                                                                                                                           |                                                                                                                                           |
| **Trigger**                  | Single trigger: **on_inbound** (IMAP ingest). Rules only execute at inbound stage (pre-module).                                                                                                                                                             |                                                                                                                                           |
| **Continue/Stop**            | Default = **Continue** (recommended for enrichment). If **Stop**, global chain ends and the selected module starts with a fresh pipeline.                                                                                                                   |                                                                                                                                           |
| **Conditions**               | Multiple AND/OR groups. Fields include: From, To, Cc, Subject, Message-ID, Account, Sender domain, Body contains/regex (lightweight), Presence of ticket token. Operators: equals, not equals, contains, startsWith, endsWith, regex, in list, not in list. |                                                                                                                                           |
| **Actions**                  | `route.module = Tickets                                                                                                                                                                                                                                     | Leads`, `route.to_global_inbox`, `set.client`, `set.tags`, `set.priority (hint)`, `mark.delete`(destructive),`flow.stop`/`flow.continue`. |
| **Conflict policy**          | For overlapping metadata, **last executed rule wins**. Deletion overrides all (with audit).                                                                                                                                                                 |                                                                                                                                           |
| **Auto-save**                | Toggles, rename, weight edits persist immediately with toast confirmation.                                                                                                                                                                                  |                                                                                                                                           |
| **Destructive confirmation** | `mark.delete` rules require confirmation on create/update. Deleting a rule prompts a confirm modal.                                                                                                                                                         |                                                                                                                                           |
| **Filtering**                | Filter by status (All / Active / Disabled) and scope (All accounts / specific account).                                                                                                                                                                     |                                                                                                                                           |
| **Search**                   | Text search on rule name.                                                                                                                                                                                                                                   |                                                                                                                                           |
| **Duplication**              | Clone rule definition including conditions/actions/scope.                                                                                                                                                                                                   |                                                                                                                                           |
| **Test harness**             | `Test…` opens modal to paste raw headers/body and preview: matched rules, actions, and final destination. Non‑destructive.                                                                                                                                  |                                                                                                                                           |

---

## 4. Widgets & Components

**Livewire components (suggested):**

* `email-rules.table` – list & sort with inline weight editor
* `email-rules.row` – status toggle, quick actions
* `email-rules.meta` – right sidebar context (hits, audit)
* `email-rules.test-modal` – paste sample email → preview evaluation
* `confirm-modal` – destructive confirmations

**UI elements:**

* Inputs: weight (int), status toggle, scope selector (All / multi-select Accounts)
* Icons (Lucide):

  * `Mails` – email rules section
  * `Zap` – trigger
  * `ArrowUpDown` – sorting
  * `Play` / `PauseCircle` – Continue / Stop
  * `CheckCircle` / `XCircle` – enable/disable
  * `FlaskRound` (or `Beaker`) – Test harness

**UX details:**

* Toast: *"Changes saved successfully"*
* Tooltip over disabled rows: *"This rule will not execute"*
* Badges: Green = Active, Gray = Disabled, Red = Destructive

---

## 5. Smart UX & Behavior

* Auto-refresh after edit/delete.
* Inline validation for weight and required fields (name, scope, trigger).
* Confirmation before disabling rules that contain `mark.delete` action.
* Sticky header with counters and quick filters.
* Bulk enable/disable (optional, later).

---

## 6. Related Views

| View                                      | Purpose                                         |
| ----------------------------------------- | ----------------------------------------------- |
| `tech.admin.settings.email.rules.create`  | Add new email rule                              |
| `tech.admin.settings.email.rules.edit`    | Edit existing email rule                        |
| `tech.admin.settings.email.index`         | Parent email settings overview                  |
| `tech.admin.settings.tickets.rules.index` | Ticket rules (run **after** global email rules) |

---

## 7. Integration & Flow

* Runs **after parsing/normalization** and **before module selection**.
* If a rule **Stops**, module pipeline (e.g., Ticket Route Rules) starts fresh.
* All changes (create/update/reorder/enable/disable) are **audited**.

---

## 8. Design Notes

* Static dashboard; dynamic list content.
* Bootstrap components; responsive, fast.
* Right rail used for telemetry (last hit, hits by 7/30 days) and audit snippets.
* Real-time updates where possible.
