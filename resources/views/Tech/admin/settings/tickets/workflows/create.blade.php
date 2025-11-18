# View: tech.admin.settings.workflow.create (also used for edit)

**Date:** 2025-10-22
**Controller:** App\Http\Controllers\Tech\Admin\Settings\WorkflowController
**Access:** superuser, tech.admin, ticket.admin
**Status:** Not completed
**Difficulty:** High
**Estimated time:** 7.5 hours

---

## Purpose

Used to **create and edit ticket workflows** that define the stages a ticket passes through. Each stage contains conditions, permissions, and transition rules that control when a ticket can move forward or close. Both create and edit share this same view.

---

## Layout Template

**Bootstrap layout:**

* **Top header:** Workflow info and actions.
* **Main section:** Stage list and editor.
* **Right slim rail:** Live visual preview of the workflow order.

---

## Components

### 1. Header

* **Workflow name:** Text input (required, unique).
* **Description:** Textarea for optional notes.
* **Default scope:** Dropdown selector:

  * Global default
  * Default per queue (multi-select)
  * None
* **Status indicator:** Saved / Saving / Failed.
* **Buttons:**

  * Save (appears only when unsaved changes exist)
  * Close / Exit (with confirmation modal if unsaved changes)

### 2. Autosave

* Triggers:

  * Name entered (creates draft)
  * New stage added
  * Stage saved
  * Field changes (debounced ~800ms)
* Shows save state in header and toast notifications.
* Protects unsaved data with beforeunload guard.

---

### 3. Stage List (left sidebar)

* Displays all stages (by name or number).
* Up/down arrows to reorder + Save order button.
* Add stage button (creates empty stage).
* Delete stage button:

  * Auto rewires all incoming transitions to the next stage.
  * Toast warning displayed after.

---

### 4. Stage Editor (main content area)

When a stage is selected, it opens an inline editor with all configurable options.

#### 4.1 Stage basics

* Stage name (required).
* Inline-only validation (no summary panel).

#### 4.2 Conditions builder

* Logical grouping: multiple AND groups combined with OR.
* Supported condition fields:

  * Queue, Priority, Assignee, Client, Site, Ticket type, Tags
  * Time since last update, Unread from client, Asset linked

#### 4.3 Permissions and actions (below divider)

* Close allowed (toggle)
* Manual transition allowed (toggle)
* Require resolution note (toggle)
* Require time entry (toggle)
* Lock edits on fields (multi-select: priority, queue, assignee, title, tags)

#### 4.4 Transition rules

* Table layout: `[Condition group] → [Target stage]`
* Fallback rule: Else → Next stage (default)
* Supports both forward and backward transitions.
* No blocking for loops; a small warning icon appears instead.

---

### 5. Preview (right rail)

* Shows a simple ordered list of all stages (Stage 1 → Stage 2 → Stage 3 ...)
* Highlights current stage in edit.
* Updates live when stages are added, renamed, or reordered.

---

## Behavior

* Workflow activates automatically on first save.
* Rules determine which workflow is used; manual activation not needed.
* Workflow type and default scope can be changed anytime.
* Closed stage:

  * Exists by default, always last, editable but not deletable.
  * Any stage with “Close allowed” can close a ticket as well.
* Stage changes take immediate effect on all active tickets.
* Tickets handle inconsistencies internally (no enforcement from editor).

---

## Validation

* Workflow name required and globally unique.
* At least one stage required to enable active state.
* Inline validation only (no global panel).

---

## Modals and Confirmations

* Global default change: confirmation modal.
* Queue default change: per-queue modal.
* Close/Exit: confirmation on unsaved changes.

---

## Buttons and Controls

* Add Stage
* Save
* Save Order
* Close / Exit

---

## Summary

This view provides a full-featured workflow editor with autosave, live updates, and flexible logic definition. It allows clear management of stages, transitions, and permissions, maintaining simplicity for the user while enabling immediate operational impact.

---

**End of documentation.**
