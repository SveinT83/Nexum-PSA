# Ticket Rules – Admin Views (Index & Create/Edit)

**Date:** 2025-10-21
**Primary URLs:**

* `tech.admin.settings.tickets.rules` (landing)
* `tech.admin.settings.tickets.rules.index`
* `tech.admin.settings.tickets.rules.create`
* `tech.admin.settings.tickets.rules.edit:{id}`
  **Access levels:**
* Read/Write: `ticket.rules.manage`
* Read-only: `ticket.admin`
  **Controller namespace:** `App\\Http\\Controllers\\Tech\\Admin\\Settings\\Tickets\\Rules\\*`
  **Livewire namespace:** `App\\Livewire\\Tech\\Admin\\Settings\\Tickets\\Rules\\*`
  **Status:** Not completed
  **Difficulty:** Medium–High
  **Estimated effort:** 9.0 hours
  **Layout template:** Top header / Main content / Right slim rail (Bootstrap).
  **Design note:** Static view configuration; dynamic content with live updates (autosave, test runs, metrics).

---

## 1) Purpose

Provide an ordered, auditable rule engine UI for **Ticket Rules** that runs on: new ticket creation, replies/updates on existing tickets, workflow/status changes, time/SLA triggers. Rules have per-rule **Continue/Stop** and **last-writer-wins** conflict semantics. The UI must support **draft vs publish**, dry‑run testing, and reorderable execution.

**Key capabilities surfaced in UI**

* Triggers: `on_create`, `on_reply`, `on_internal_note`, `on_status_change`, `on_timer`, `on_sla_warning`, `on_sla_violation`.
* Conditions: channel/status/queue/category/priority/impact/urgency/tags/custom fields; client/site/contact; related asset; email headers (from/to/cc), subject/body/attachments; assignee/team/unassigned; ticket age; business hours; SLA policy; % to breach; on hold/waiting thresholds. Operators: equals/not, contains/not, starts/ends, in/not in, regex (guarded), greater/less.
* Actions: set fields; assign/round‑robin; set/change workflow; pause/resume SLA; set status/transition; set client/site/contact/asset; send templated email; add internal/public note; timer controls; time entry; escalation notify; due date/first response override; reminders; link/merge/close duplicate; call webhook/API/enqueue job/run script (future); redact/mark sensitive; add/remove tags; move queue.

---

## 2) Reusable UI Elements (Livewire/Bootstrap)

Mark these as **Livewire** components for reuse across settings modules.

* **RulesTable** (sortable, paginated): order, name, enabled, triggers (chips), scope (badges), last edited, 24h/7d hits.
* **OrderDnD**: drag handle + persistence; emits `rulesReordered`.
* **TriggerPicker**: multi-select chips with descriptions.
* **ConditionBuilder**: Field ▸ Operator ▸ Value with AND/OR groups; regex guard hints; test chip per row.
* **ActionBuilder**: action list with per‑action editors (forms); reordering of actions; dependency warnings.
* **ScopePicker**: All vs selected queues/categories; optional client/site filters; preview count.
* **TestHarness**: input sample (ticket id or pasted email JSON) → dry‑run → match path + actions preview.
* **MatchHistory**: last N rule executions with outcome; links to tickets.
* **ChangeLog**: diff viewer for draft/published payloads; who/when.
* **BulkBar**: bulk enable/disable/delete/export JSON.
* **RuleSummary**: human‑readable “When … Then … (Continue/Stop)”.
* **GuardRails**: destructive action confirmation; regex timeouts; rate‑limit hints.

---

## 3) View: rules.index (`tech.admin.settings.tickets.rules.index`)

**Audience:** Admins managing the rule set.

**Main (center)**

* **Header bar:** Title, “Create Rule” button, Import JSON (modal), Export All, Search box.
* **RulesTable** with **OrderDnD** and row actions: Edit, Duplicate, Enable/Disable, Delete, Test.
* **BulkBar** appears when rows selected.

**Right slim rail**

* **Execution Preview (TestHarness)**: paste sample → see matched rules + action preview.
* **Rule Metrics widgets:**

  * “Hits last 24h/7d” (sparkline count)
  * “Top overrides” (priority/queue/category)
  * “Collision detector” (fields frequently overwritten by multiple rules)

**Footer/Sticky**

* Save order (if not autosaving), Recalculate metrics (async). Toast feedback.

**Behaviors**

* Reorder emits server update; optimistic UI.
* Deleting a rule archives to versions table; can be restored.

---

## 4) View: rules.create (`tech.admin.settings.tickets.rules.create`)

> Serves also as **Edit** with loaded data (`rules.edit:{id}`)

**Main (tabbed editor)**

* **Basics card:** Name (required), Description, Enabled toggle, Continue/Stop radio.
* **Triggers tab (TriggerPicker)**
* **Conditions tab (ConditionBuilder)** with AND/OR groups.
* **Actions tab (ActionBuilder)** with sorted actions; inline validation.
* **Scope tab (ScopePicker)**
* **Test tab (TestHarness)** – dry‑run against sample; shows decision path and final state.

**Right slim rail**

* **GuardRails**: flags for destructive actions (merge/close/delete), require typed confirm to publish.
* **Version & Draft**: Autosave status; Publish button; Published revision info.
* **Where this hits**: preview counts by queue/category (based on last 7d samples).

**Sticky header actions**

* Save Draft, Publish, Test, Duplicate, Cancel.

**Validation rules**

* At least one Trigger and one Action.
* Regex length/timeout guard.
* Workflow/Status actions must pass workflow pre‑check.

---

## 5) Evaluation & Conflict Model (engine-visible in UI)

* Ordered execution top→bottom.
* Per rule: **Continue** (default) or **Stop**.
* Conflicts: **Last writer wins** (later matches overwrite prior field sets).
* Side‑effect dedupe: engine prevents duplicate notifications/time entries on a single event.

**Index summaries** should display Continue/Stop and field targets to help admins reason about ordering.

---

## 6) Scoping & Safety

* **ScopePicker** limits a rule to queues/categories and optionally client/site.
* Destructive actions (merge/close/duplicate‑close) require confirmation and are highlighted in summaries.
* Publish applies immediately to subsequent events; existing in‑flight events are unaffected.

---

## 7) Widgets (suggested)

* **Rule Hit Heatmap** (by hour/day)
* **Top Fields Overridden**
* **Collision Detector**
* **What‑If Simulator** (batch dry‑run on last 100 events)

---

## 8) Components to use (Bootstrap; no HTML code)

* Cards, Tabs, Tables (sortable), Badges/Chips, Modals, Offcanvas (right rail), Dropdowns, Tooltips, Toasts, Progress, Input groups, Accordions.

**Icons (suggested, no colors):** list, sliders-horizontal, beaker, grip-vertical, toggle-left/right, alert-triangle, save, plus, upload/download, history, git-commit, play-circle.

---

## 9) Routing & Controllers (mirror structure)

* **Routes (names):**

  * `tech.admin.settings.tickets.rules`
  * `tech.admin.settings.tickets.rules.index`
  * `tech.admin.settings.tickets.rules.create`
  * `tech.admin.settings.tickets.rules.edit`
* **Controllers:**

  * `RulesIndexController`
  * `RulesCreateController` (also handles edit state)
  * `RulesTestController` (dry‑run endpoint)
  * `RulesOrderController` (persist DnD)
  * `RulesImportExportController`

---

## 10) Observability & Audit (surface in UI)

* Per-event hit log (rule id/name, matched, actions applied, stop/continue).
* Rule ChangeLog (create/update/enable/disable/order change; who/when).
* Metrics: matches, blocks, duration.
* Links from ticket history to the rule execution record.

---

## 11) Non-functional

* Autosave drafts; Publish creates a new immutable version.
* Guard heavy operations (regex) with timeouts.
* Pagination/lazy loading on tables and histories.
* All write actions audited.

---

## 12) Notes for Developers & Copilot

* Centralize field/operator dictionaries to keep ConditionBuilder consistent across modules.
* Keep action handlers modular; validate workflow transitions before commit.
* Ensure deterministic order and clear summaries to reduce rule collisions.
