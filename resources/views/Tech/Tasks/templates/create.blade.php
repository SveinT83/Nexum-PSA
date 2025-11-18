# tech.tasks.template.create — Template Creation View

**Creation date:** 2025-10-29
**URL:** `tech.tasks.template.create`
**Access & permissions:** `task.templates.view`, `task.templates.create`, `task.templates.edit`, `task.templates.delete`
**Controller path:** `App\Http\Controllers\Tech\Tasks\Templates\CreateController@create`
**Livewire/Components:** `App\Livewire\Tech\Tasks\Templates\Create\*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 5.0 hours (view 2.0h, component 2.0h, validations + logging 1.0h)

---

## Purpose

Create and maintain **Task Templates** that can later be **applied to a Ticket** to spawn a structured set of tasks with **parent/child hierarchy**. Templates are optional, queue-aware helpers to speed up repeatable work.

---

## Scope & Rules

* **Template fields:** `name` (required), `description` (optional, textarea), `queues[]` (0..n).
* **Queue scope:** Multiple queues allowed. **Empty = Global** (available on any ticket).
* **Content:** list of **tasks** with **hierarchy** (parent/child/siblings). **No per-task operational fields** (priority, estimates, assignees, due dates, billable, visibility) are stored in the template; these are set on the real tasks after copying into a ticket.
* **Workflow override:** Rules/Workflows may **force-apply** a template regardless of queue tags.
* **Lifecycle:** `Draft → Published → Archived`. Only **Published** appear in "Add template to ticket" pickers.
* **Copy semantics:** When applied to a ticket, create tasks 1:1 preserving hierarchy and titles.
* **Audit:** All create/update/delete and hierarchy changes are logged.

---

## Layout (Bootstrap)

Use standard app shell with **Top header**, **Main content**, and **Right slim rail**.

### Top Header

* **Title:** "Create Template" / "Edit Template"
* **Status pill:** Draft / Published / Archived
* **Primary actions:** [Save], [Publish], [Archive], [Delete] (Delete only when not used or with confirm modal)
* **Secondary:** [Preview spawn] (simulates task tree), [Duplicate]

### Main Content

**Card: Template Meta**

* **Name** (text input) — required
* **Description** (textarea) — optional
* **Queues** (multi-select) — help text: empty = global

**Card: Tasks & Hierarchy**

* **Toolbar (reusable):**

  * [Add task] (opens **Task-Stub Modal**)
  * [Add parent above] / [Add child] / [Add sibling] (contextual)
  * [Expand all]/[Collapse all]
  * [Auto-number] (1, 1.1, 1.2…)
  * [Validate tree]
* **Tree Grid (reusable component):**

  * Columns: `#` (auto numbering), `Task title`, `Notes (optional)`, `Children count`
  * Row affordances: drag handle (drag & drop to reorder / reparent), context menu (Add child, Duplicate node, Remove), visibility of parent chain
  * Keyboard: ↑↓ navigate, **Tab/Shift+Tab** indent/outdent, **Enter** new sibling
* **Rules:**

  * Task title required
  * Max nesting depth configurable (default 4)
  * Prevent cycles and empty parents

**Card: Summary & Quality Checks**

* **Computed stats:** total tasks, max depth, leaf vs. parent count
* **Optional notes:** intended outcome, expected **total time (estimate)** at template level (display-only metadata)
* **Warnings:** very deep nesting, duplicate names, empty template

### Right Slim Rail

* **Help**: short guide: “Templates are queue-aware but not queue-bound. Operations like priority/assignee are set after applying to a ticket.”
* **Quick actions**: [Publish] / [Archive] / [Duplicate]
* **History (log extract)**: last 5 changes
* **Links**: "Manage Templates" index, "Rules & Workflows" (admin)

---

## Interactions & Modals

* **Task-Stub Modal (reusable)** — minimal, used only inside templates:

  * Fields: `Task title` (required), `Notes` (optional).
  * Buttons: [Add], [Add & Add Another], [Cancel].
  * Rationale: operational fields (priority, due dates, assignees, billable, etc.) are **not captured here**.
* **Delete Template Modal** — confirm irreversible action; block if template is attached to active rules (offer “Detach from rules first”).
* **Publish/Archive Confirm** — toggle lifecycle with message.
* **Preview Spawn Drawer** — shows the resulting task list as it would appear on a ticket (read-only tree).

---

## Reusable Components & Widgets

* **Tree Grid** (`ui.tree-grid`) — drag/drop + indent/outdent + numbering.
* **Queues Multi-select** (`ui.multi-select.queue`) — used elsewhere (filters, rules).
* **Status Pill** (`ui.status-pill`) — Draft/Published/Archived.
* **Audit Trail Mini** (`ui.audit.inline`) — compact change list.
* **Confirm Modal** (`ui.modal.confirm`).
* **Task-Stub Modal** (`ui.modal.task-stub`).

> Mark these components as reusable for Tickets, Rules, and Knowledge modules where hierarchy editing is needed.

---

## Smart UX Suggestions

* **Autosave** every 3s or on blur (with toast feedback).
* **Dirty-guard** when navigating away with unsaved changes.
* **Paste-to-tree:** paste a bulleted list to auto-generate nodes and hierarchy.
* **Template linting:** hint when too many top-level nodes or uneven branches.
* **Queue Hints:** when selecting queues, show count of tickets per queue (last 30 days) to guide relevance.
* **Search-in-tree** with incremental highlight.
* **Undo/Redo** stack for tree edits.

---

## Permissions & Visibility

* **Create/Edit/Delete:** `task.templates.create` / `task.templates.edit` / `task.templates.delete`
* **Publish/Archive:** included in `task.templates.edit` or separate `task.templates.publish` if needed.
* **View on ticket:** requires `ticket.view` AND template is **Published**.
* **Rule access:** rule editors with `ticket.rules.manage` can reference any template (Drafts not selectable by default; allow override toggle).

---

## Validation & Errors

* Name required, unique per organization.
* Template must contain ≥1 task before **Publish**.
* Queue values must exist in system.
* Prevent archiving if referenced by active rules (offer quick jump to detach).
* API/Forms return structured errors; show inline + top summary.

---

## Logging

* Log on: create, rename, description edit, queue changes, add/remove task, reorder/reparent, lifecycle changes, publish/archive, delete.
* Include: actor, timestamp, old→new values, reason (optional note).

---

## Real-time & Concurrency

* Live updates via websockets: lock nodes being edited, show collaborator cursors on the tree.
* Conflict resolution: last-write wins on independent nodes; prompt merge on the same node.

---

## Integration Touchpoints

* **Ticket View:** "Add Template" picker filters by ticket queue; **Global** templates always listed.
* **Rules Engine:** action "Apply Template" with optional conditions (queue-independent).

---

## Test Checklist (QA)

* Create Draft with empty queues (global).
* Add tasks via modal, indent/outdent, drag/reparent.
* Validate cannot publish with 0 tasks.
* Publish; confirm it appears in ticket picker.
* Archive; confirm hidden from picker but available in admin.
* Duplicate template preserves tree and queues.
* Apply template to ticket → tasks and hierarchy created correctly.
