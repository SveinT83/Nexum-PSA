# tech.admin.settings.ticket.rules.create

**Date:** 2025-10-21
**Primary URL:** `tech.admin.settings.ticket.rules.create`
**Access level:** `superuser`, `tech.admin`, `ticket.admin`
**Controller:** `App\\Http\\Controllers\\Tech\\Admin\\Settings\\Ticket\\RulesController@create`
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 4.0 hours

---

## Purpose

This view allows administrators to create or edit **Ticket Rules**. Each rule defines a trigger, one or more conditions, and one or more actions that the system performs automatically on tickets.

The page uses the same form for both **create** and **edit** modes. The header title remains constant ("Create Ticket Rule"), while data determines whether a new or existing rule is being handled.

---

## Layout

* **Header:** Static section with breadcrumbs, title, and two buttons:

  * **Back to list** (left side)
  * **Save** (right side, appears when valid and unsaved changes exist)
* **Main scrollable content:** Three main sections stacked vertically:

  1. **Trigger**
  2. **Conditions**
  3. **Actions**
     Each separated by a horizontal line (`<hr>`).
* **Right sidebar (metadata):** Contains fields for rule name, status, weight, stop/continue behavior, and ID.

All content scrolls in one continuous column. The header and sidebar behavior are controlled by the global template.

---

## Components

### Header

* Title: **Create Ticket Rule** (static)
* Buttons:

  * **Back to list** → returns to `tech.admin.settings.ticket.rules.index`
  * **Save** → active only when trigger and at least one action exist

### Global layout

* Uses the shared admin template with alert boxes for validation messages displayed **below the header**.
* Page supports manual save only. No autosave.
* Scroll position is preserved after save.

---

## Section 1: Trigger

* Section heading: **1. Trigger**
* Button: **+ Add** → opens modal to choose one trigger (rules support one trigger only).
* Modal positioned center-screen, sized dynamically to fit content.
* Once saved, displays a **neutral box** summarizing trigger type and options.
* Box includes **Edit** and **Delete** buttons.

### Trigger options

* on_ticket_created
* on_ticket_updated
* on_status_changed
* on_assignment_changed
* on_priority_changed
* on_tag_added / on_tag_removed
* on_comment_added
* on_email_received
* on_sla_threshold
* on_timer_started / on_timer_stopped

---

## Section 2: Conditions

* Section heading: **2. Conditions**
* Button: **+ Add** → opens modal to define logical rules.
* Each condition appears as a **box** with summary text and Edit/Delete buttons.
* Supports `AND`, `OR`, and `IF` blocks (flat structure, no nested groups).
* Drag-and-drop available for reordering.
* Multiple conditions allowed; all must be true for rule to match.
* Conditions can use negation (`not equals`, `not contains`).

### Condition fields (v1)

* Queue, Category, Status, Priority
* Assigned (user/team)
* Client, Site, Requester (email/domain)
* Tags
* Title/Description text match
* Source (email/web/api)
* Has attachments (boolean)
* Ticket age (minutes/hours/days)
* Time in status (minutes/hours/days)
* SLA state
* Custom field (key/operator/value)

### Condition modal

* Field selector → operator → value input.
* Live preview of built condition text.
* Buttons: **Save** (commit + close) / **Cancel** (discard + close).

---

## Section 3: Actions

* Section heading: **3. Actions**
* Button: **+ Add** → opens modal for selecting and configuring actions.
* Actions displayed as boxes with neutral styling, edit/delete buttons, and optional note below the title.
* Drag-and-drop determines execution order (top-to-bottom).

### Example actions

* Set queue / category / status / priority
* Assign to user or team
* Add/remove tags
* Add internal note (from template)
* Send email (choose type/template)
* Change SLA profile
* Set due date
* Set workflow on ticket (choose workflow and start step)
* Trigger webhook (URL + payload template)
* Start/stop timer

All actions execute sequentially in listed order.

---

## Right Sidebar (Metadata)

* **Name** (text, required, unique)
* **Weight** (integer, default 10)
* **Status** (Enabled/Disabled; default disabled)
* **Stop behavior** (dropdown with: Stop, Stop scope, Continue)
* **Description** (multiline note)
* **Rule ID** (read-only text)

Save is disabled until a trigger and at least one action exist.

---

## Validation & Errors

* Validation errors display in an alert box under the header.
* Inline errors are not used.
* Save button appears only when form is valid and unsaved changes exist.
* Delete operations require confirmation modals.
* No confirmation for Cancel; it navigates back immediately.

---

## Interaction & UX

* Manual Save only (no autosave).
* Deleted elements are immediately removed from view.
* New boxes added appear at the bottom of their section.
* Conditions and Actions editable via modal only; drag used for order.
* All sections always visible (no collapsible sections).
* Scroll position retained after saving.
* Unsaved indicator shown by visible Save button (no extra text).
* Creation date and update timestamps are not shown on this view.
* Header title remains fixed; does not change with rule name.

---

## Notes & Behaviors

* The form supports both creation and editing under the same route name (`create`).
* Default: rule starts as disabled until manually activated in the list.
* All boxes (trigger/conditions/actions) are neutral-styled; no icons or colors.
* Comments/notes visible under box titles.
* Modals show loading spinner while saving or loading.
* ID displayed as plain text.
* No nested groups, no drafts, no autosave.
* Breadcrumbs show full path context; no extra module header needed.

---

## Icons & Visuals

Minimalistic: no icons or color codes in rule boxes.
Breadcrumbs and alert template provide sufficient context.

---

## Permissions

* Accessible only to `superuser`, `tech.admin`, `ticket.admin`.
* Data sources (queues, users, etc.) respect role visibility.

---

## Summary

This page defines the full UI behavior for building and managing ticket rules. It is static in layout but dynamic in content, offering modals for creating triggers, conditions, and actions, with simple visual order management and manual save control.
