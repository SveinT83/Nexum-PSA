# Workflow System — General Specification

**Date:** 2025-10-22  
 **Primary URLs:**

- `tech.admin.settings.tickets.workflows.index`
- `tech.admin.settings.tickets.workflows.create`
- `tech.admin.settings.tickets.workflows.edit`
- `tech.admin.settings.tickets.workflows.show` (version history / read-only)

**Access Levels (permissions):**

- View: `ticket.admin` or `ticket.workflow.manage`
- Create/Edit/Delete: `ticket.workflow.manage`
- Override controls (at runtime in ticket UI): `ticket.workflow.manage` (admins) and optional `workflow.override` (if defined later)

**Controller Namespace & Structure:**  
 `App\Http\Controllers\Tech\Admin\Settings\Tickets\Workflows\*`

- `IndexController` → `index()`
- `CreateController` → `create()`, `store()`
- `EditController` → `edit($workflowId)`, `update($workflowId)`
- `ShowController` → `show($workflowId)` (includes version history)
- `VersionController` → `publish($workflowId)`, `rollback($workflowId, $versionId)`

**Status:** Not completed  
 **Difficulty:** Medium–High  
 **Estimated Time:** 6.0 hours

**Layout (Bootstrap template):** Top header / Main content / Right slim rail.

- Header: title, breadcrumbs, global actions.
- Main: tabbed editor + tables.
- Right rail (narrow): quick tips, validation summary, audit snapshot.

---

## 1) Purpose

A configurable state-machine for tickets that standardizes lifecycle, prevents premature closure, and enables automation (reminders, escalations, auto-close). Bound to queues/categories and assignable by Ticket Rules. Versioned for safe iteration.

---

## 2) Key Concepts & Data Model (functional)

- **Workflow**: named, versioned container with status (draft/published/disabled).
- **States**: distinct phases (e.g., `new`, `in_progress`, `waiting_customer`, `resolved`, `closed`). Initial and terminal designations. Each state can declare **required fields/checklist**.
- **Transitions**: allowed moves between states with label, guards, and role restrictions.
- **Rules**: event-driven logic with **conditions**, **validators**, **actions**, **severity** (`block`, `warn`, `auto`).
- **Overrides**: privileged bypass with justification + audit.
- **Bindings**: default per **queue** and/or **category**; Ticket Rules may assign workflows (`override_mode: always | if_missing`).

> Relationships to other systems: Ticket Route Rules run before a ticket enters a workflow; SLA policies timebox targets and pauses; Audit logs every enforcement.

---

## 3) Views & UI (per route)

### 3.1 `tech.admin.settings.tickets.workflows.index`

**Goal:** Overview of all workflows and defaults.

**Main components (reusable where possible):**

- Table (reusable): columns → Name, Active Version, Scope Default (🌍 Global / 🧭 Queue name), Status (Active/Disabled/Draft), Last Updated, Used By (#queues/#categories).
- Row actions → Edit, Duplicate, Set Default (global/queue), Disable/Enable, Version History.
- Filters → Status, Bound Queue, Category, Updated by, Has Draft.
- Bulk actions → Enable/Disable selected.

**Smart UX:**

- Inline pill badges for defaults (tooltip lists queues/categories).
- "Unused" badge when no bindings exist.

**Icons (suggested):** `workflow`, `flag`, `history`, `copy`, `toggle-left/right`, `globe`, `route`.

**Right rail widgets:**

- Quick Help (when to use workflows vs rules).
- Recent changes (mini-audit)

**Livewire candidates:**

- `WorkflowsTable` (sorting, filters, bulk actions, lazy pagination).

---

### 3.2 `tech.admin.settings.tickets.workflows.create`

**Goal:** Create a new workflow draft quickly.

**Sections (tabs in main area):**

1. **Overview** — Name, Description, Version label (auto), Status (Draft only).
2. **States** — list editor with add/remove; mark Initial and Terminal; per-state checklist (required fields).
3. **Transitions** — visual matrix or list: from → to, label, allowed roles, guard expression (builder).
4. **Rules** — event-based builder with conditions and actions.
5. **Bindings** — default queue(s)/category(ies); precedence notes.
6. **Validation** — live validator report, missing initial/terminal, orphan transitions, circular paths.
7. **Audit** — auto-filled (read-only) once saved.

**Buttons:** Save Draft, Validate, Publish (disabled until valid), Cancel.

**Livewire components:**

- `StateEditor` (sortable list; checklists with required fields).
- `TransitionEditor` (grid/list with guards and role chips).
- `RuleBuilder` (conditions/actions; continue/stop semantics).
- `BindingPicker` (queues/categories with search).
- `ValidationPanel` (sticky summary with deep-link to offending config).

**Smart UX ideas:**

- Keyboard shortcuts (add state, connect states).
- "Suggest defaults" wizard (seed common 5-state flow).
- Conflict highlights (e.g., no path to `closed`).

**Right rail widgets:**

- Tips: recommended patterns; checklist examples.
- Draft status chip + last autosave timestamp.

---

### 3.3 `tech.admin.settings.tickets.workflows.edit`

**Goal:** Modify an existing workflow with version safety.

**Header:** Workflow name + active version selector; badges for `Published/Draft/Disabled`.

**Tabs:** Overview, States, Transitions, Rules, Bindings, Validation, Versions, Audit.

**Versioning rules:**

- Editing a **published** workflow creates a new **draft** version.
- **Publish** creates Version N+1; new tickets use latest.
- Existing tickets remain on their bound version until migrated (manual **Recalculate Workflow** action available in ticket UI).

**Buttons:** Save Draft, Publish, Duplicate, Disable/Enable, Rollback to Version X.

**Livewire:** same components as Create; add `VersionTimeline` and `DiffViewer`.

**Right rail:**

- "Impact preview" (where bound; affected queues/categories).
- Validation summary.

---

### 3.4 `tech.admin.settings.tickets.workflows.show`

**Goal:** Read-only details & version history.

**Content:** Collapse/expand panels for States, Transitions, Rules, Bindings.  
 **Widgets:** VersionTimeline, Audit feed (read-only).  
 **Actions:** Duplicate as Draft.

---

## 4) Rules & Execution (functional)

**Events (examples):**

- Transition attempt: `on_attempt_close`, `on_before_assign`, `on_status_change(from→to)`
- Ticket lifecycle: `on_ticket_created`, `on_first_response`, `scheduler.daily`

**Conditions:** field presence, SLA state, tags, time-in-state, queue/category, contract flags.

**Validators:** enforce required fields, time logging presence (or approved fallback), resolution notes, attachment presence, KB link requirement, call log recorded.

**Actions:** send customer email, add internal note, tag, reassign, change priority, set SLA policy, trigger reminder/auto-close, pause SLA on `waiting_customer`.

**Severity:**

- `block` (hard stop; show reason).
- `warn` (confirm to proceed; policy-defined).
- `auto` (silent automation).

**Overrides:** optional; gated by permission, requires reason; all overrides audited.

---

## 5) Binding & Precedence

- Ticket may get a workflow by: Ticket Rule → Category default → Queue default → Global default.
- Ticket Rule can set `override_mode: always | if_missing`.
- Changing workflow mid-ticket starts fresh in the new workflow; system attempts state mapping; all changes are audited.

**Runtime controls in Ticket UI (read-only here):**

- Workflow panel shows current step and available transitions; disabled transitions show reason tooltips.

---

## 6) Audit & Observability

- All admin changes: create/update/reorder/publish/disable/rollback with who/what/when.
- Runtime: every blocked attempt, warning confirmation, override, and auto-action is logged to ticket audit.
- Metrics (for reports later): time-in-state, number of blocks per category, auto-close counts.

**Right rail widget suggestion (Index/Edit):** Recent Workflow Changes (last 10), with links.

---

## 7) Livewire & Reusable Elements

- **Reusable list/table** component for index views.
- **RuleBuilder** (shared with Ticket Rules to reduce duplication, with scoped operators).
- **StateDiagram** (optional, lightweight; or stick to matrix list for MVP).
- **ValidationPanel** (shared across create/edit).
- **VersionTimeline** and **DiffViewer** (generic components useful in other settings).

---

## 8) Widgets (suggested)

- **Checklist Preview** (for current state) — shows required fields/toggles.
- **Reminder Planner** — configure reminder cadence per state (e.g., Waiting on Customer).
- **Auto-Close Planner** — define auto-close time + grace flows.
- **Bindings Map** — visualize which queues/categories use the workflow.

---

## 9) Buttons & Actions (summary)

- Index: New Workflow, Enable/Disable, Set as Default (global/queue), Duplicate, Version History.
- Create/Edit: Save Draft, Validate, Publish, Duplicate, Disable/Enable, Rollback (edit), Cancel.

---

## 10) Validation Rules (MVP)

- Exactly one Initial state.
- ≥1 Terminal state.
- No unreachable states.
- No orphan transitions.
- At least one path from Initial → Terminal.
- All required checklists reference existing fields.
- Guards compile (where applicable).
- Bindings resolve to known queues/categories.

---

## 11) Notifications & Automation (policy hints)

- Define notification behaviors per state (technician/customer reminders).
- Respect tenant business hours for SLA; pause on `waiting_customer` (configurable).
- Auto-close windows and reminder cadence configurable; all auto-actions appear in ticket history.

---

## 12) Security & Permissions

- Access to views: `ticket.admin` or `ticket.workflow.manage`.
- Publishing/disabling/rollback restricted to `ticket.workflow.manage`.
- Runtime override (if enabled): dedicated permission key (e.g., `workflow.override`) for transparency.

---

## 13) PWA & Performance

- Static layout shell; dynamic content with live updates (autosave indicators, validation status).
- Debounced autosave in editors; optimistic UI with rollback on failure.
- Lazy-load large lists (bindings, version history).

---

## 14) Internationalization (future-ready)

- All labels/strings via template keys; English first.
- Workflow names/descriptions are tenant-authored and stored as plain text with optional locale variants.

---

## 15) Non-Goals (for this doc)

- No HTML or code examples.
- No SLA reporter specs (covered under reporting).
- No per-tenant theming; follow global Bootstrap styles.

---

## 16) Acceptance Criteria (MVP)

- Admin can create a workflow with states, transitions, rules, and bindings.
- Validation prevents publishing invalid graphs.
- Versioning works (draft → publish → rollback).
- Ticket UI respects transitions/validators and logs blocks/overrides.
- Audit trail exists for admin and runtime events.