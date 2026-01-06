@extends('layouts.default_tech')

@section('title', 'Tech Dashboard')

@section('pageHeader')
    <h1>Ticket Rules Settings</h1>
@endsection

@section('content')
# tech.admin.settings.ticket.rules.index

**Date:** 2025-10-21
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 3.5 hours

**Controller:**
`App\\Http\\Controllers\\Tech\\Admin\\Settings\\Tickets\\RulesController@index`

**Access:**

* superadmin
* tech.admin
* ticket.admin
* Permission required: `ticket.rules.manage`

**URL:**
`/tech/admin/settings/tickets/rules`

---

## 1. Purpose

This view provides administrators with a central interface to manage **Ticket Rules**, which control automated behavior for tickets when they are created, updated, replied to, or closed.
Each rule defines a trigger, multiple conditions, and multiple actions that execute in sequence when matched.

The goal is to automate classification, prioritization, and routing of tickets, reducing manual work while ensuring predictable behavior.

---

## 2. Layout & Structure

**Template layout:** Header / Main / Right slim sidebar
(standard admin settings structure)

### Components

* **Header bar**

  * Title: **Ticket Rules**
  * Buttons: `+ Add Rule`, `Edit`, `Duplicate`, `Delete`, `Enable/Disable`
  * Inline counters: Total rules, Active, Disabled

* **Main content (rules list)**

  * Columns:

    * Weight (sortable integer, default 10)
    * Rule name
    * Trigger (on_create / on_update / on_reply / on_close)
    * Status (Active/Disabled)
  * Sorting: ascending by **weight**, then by **id** for same weight.
  * Actions per row: Enable/Disable toggle, Edit, Duplicate, Delete.

* **Right sidebar (context panel)**

  * Metadata for selected rule: ID, Created by, Updated at, Audit summary.
  * Help widget with explanation of triggers, conditions, and actions.

---

## 3. Functional Behavior

| Feature                      | Description                                                                                                                                                                                                                              |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Weight-based order**       | Rules execute in ascending weight order (lower = higher priority). For equal weight, database ID decides order.                                                                                                                          |
| **Triggers**                 | Mandatory field per rule (on_create / on_update / on_reply / on_close). Rules only execute when their trigger matches.                                                                                                                   |
| **Continue/Stop logic**      | Default = Stop. When Continue is set, evaluation proceeds to later rules. If multiple rules set the same field, the last executed rule wins.                                                                                             |
| **Conditions**               | Supports multiple AND/OR groups. Fields include: Queue, Category, Priority, Tags, Customer, Site, Asset, Assigned tech, Subject, Body, Channel, etc. Operators: equals, not equals, contains, startsWith, endsWith, regex, in list, etc. |
| **Actions**                  | Multiple per rule allowed: set queue/category/priority/tags, assign tech, add/remove tags, change status, mark delete, close ticket, etc.                                                                                                |
| **Conflict policy**          | Last executed rule wins for overlapping fields.                                                                                                                                                                                          |
| **Auto-save**                | All changes (toggle, rename, weight change) are saved immediately. Confirmation toast shown.                                                                                                                                             |
| **Destructive confirmation** | Confirmation modal shown for delete or disabling critical rules.                                                                                                                                                                         |
| **Filtering**                | Filter by trigger or status (All / Active / Disabled).                                                                                                                                                                                   |
| **Search**                   | Simple text search on rule name.                                                                                                                                                                                                         |
| **Duplication**              | Quick duplicate action to clone rule definition.                                                                                                                                                                                         |

---

## 4. Widgets & Components

**Livewire components:**

* `rules-table` – lists all rules (sortable)
* `rule-row` – handles toggles and weight editing
* `rule-metadata` – right sidebar context data
* `confirm-modal` – for delete/disable confirmations

**UI elements:**

* Buttons: `+ Add Rule`, `Duplicate`, `Delete`
* Inputs: weight (integer), trigger (dropdown), status toggle
* Icons:

  * `Zap` – trigger
  * `CheckCircle` / `XCircle` – enable/disable
  * `ArrowUpDown` – sorting
  * `Play` / `PauseCircle` – Continue / Stop indicators

**UX details:**

* Auto-save confirmation toast: *"Changes saved successfully"*
* Tooltip over disabled rules: *"This rule will not execute"*
* Color coding:

  * Green = Active
  * Gray = Disabled
  * Red = Destructive action

---

## 5. Smart UX & Behavior

* Auto-refresh list after edit or delete.
* Inline validation for weight and trigger fields.
* Confirmation before deleting or disabling rules.
* Optional quick duplicate shortcut.
* Sticky header with count summary.

---

## 6. Related Views

| View                                         | Purpose                                    |
| -------------------------------------------- | ------------------------------------------ |
| `tech.admin.settings.ticket.rules.create`    | Add new rule                               |
| `tech.admin.settings.ticket.rules.edit`      | Edit existing rule                         |
| `tech.admin.settings.ticket.index`           | Parent ticket settings overview            |
| `tech.admin.settings.ticket.workflows.index` | Workflow management (executes after rules) |

---

## 7. Integration & Flow

* Runs **after email parsing** and **before workflow logic**.
* Controlled by rule engine in backend (same evaluation logic used for incoming emails, RMM events, and manual updates).
* All configuration changes are recorded in the audit log.

---

## 8. Design Notes

* Static dashboard; users cannot customize layout.
* Dynamic content with real-time updates.
* Bootstrap-based UI components.
* Icons: Lucide.
* Follow standard PSA admin layout: header / main / right sidebar.

---
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