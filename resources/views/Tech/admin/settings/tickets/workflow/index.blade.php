# Ticket Workflows — Index View (List)

**URL:** `tech.admin.settings.ticket.workflow.index`
**Audience:** Developers & GitHub Copilot
**Date:** 2025-10-21
**Access (permissions):** `ticket.admin` **and** `ticket.workflow.manage`
**Controller:** `App\Http\Controllers\Tech\Admin\Settings\Tickets\WorkflowController@index`
**Status:** Not completed
**Difficulty:** Low
**Estimated time:** 1.5 hours

## Purpose

Provide an at-a-glance, static list of all ticket workflows with quick controls to edit, enable/disable, and set the single fallback default (either per Queue or Global). This page mirrors the style and interactions from `tech.admin.settings.ticket.rules.index` where applicable, but without rule weights or execution ordering.

## Layout (Bootstrap template regions)

* **Top header:** page title, primary actions (Create), search/filter.
* **Main content:** workflows table (static layout, live data).
* **Right slim rail:** contextual help / quick tips (non-interactive, live-updated badges okay).

## Components (no HTML, Bootstrap-based)

* **Page Title:** “Ticket Workflows”
* **Primary Button:** `Create Workflow`
* **Search Input (inline):** placeholder “Search workflows…”
* **Filter Dropdown:** `Status: All | Enabled | Disabled`
* **Table (striped, responsive):**

  * Columns:

    1. **Name** — workflow display name
    2. **Status** — Enabled / Disabled (badge)
    3. **Default** — shows one of:

       * `Global` (if this workflow is the global fallback), or
       * `<Queue name>` (if this workflow is the default for that single queue), or
       * `—` (no default binding)
         Include a small **pin/star icon** to visually mark “is default”
    4. **Updated** — relative time (tooltip with ISO timestamp)
    5. **Actions** — row actions (see below)
* **Empty State:** icon + text “No workflows yet.” with secondary link “Create your first workflow”.
* **Pagination:** standard, page size 25 (remember last selection per user in local storage).

## Row Actions

* **Edit** — navigates to `tech.admin.settings.ticket.workflow.edit` (same form as create)
* **Enable/Disable (toggle)** — immediate state change with inline success alert
* **Set as Global Default** — sets this workflow as the global fallback (confirmation modal if another workflow is currently global)
* **Set as Default for Queue** — opens modal with a **single-select** of queues (since only one default binding is allowed); saving replaces any previous default for that queue
* **Duplicate** — creates an editable copy (suffix “(copy)”)
* **Delete** — confirmation modal (“Are you sure?”); blocked if referenced by an active rule with enforced policy (show reason)

> Icons (suggestions, no colors):
>
> * Default markers: `star` (global), `pin` (queue)
> * Status: `toggle-left/right`
> * Edit: `pencil`
> * Duplicate: `copy`
> * Delete: `trash`

## Behaviors & Rules

* **Single default binding:** A workflow may be default for **either** one queue **or** global — not both; **and** not multiple queues.
* **Ticket Rules precedence:** Ticket Rules typically assign workflows; defaults here act only as fallback when no workflow is set by rules.
* **Mutual exclusivity enforcement:**

  * When setting Global Default: if workflow is already default for a queue, show blocking modal: “This workflow is already default for Queue ‘X’. Move default to Global?” with confirm.
  * When setting Default for Queue: if workflow is global default, require confirm to remove global before applying queue default.
* **Idempotent actions:** Enable/Disable and default assignments show inline confirmation toast and update the table row without a full reload.
* **Audit notes (implicit):** All changes write to audit (who/what/when), visible in workflow edit view audit tab (not on index).

## Modals

1. **Set as Default for Queue**

   * Fields: Queue (single-select, searchable)
   * Buttons: Save, Cancel
   * Validation: Queue required
2. **Confirm Global Default** (when replacing)

   * Message shows the currently global workflow; confirm to switch
3. **Delete Workflow**

   * Message: “Deleting a workflow cannot be undone.”
   * If referenced: show block with guidance “Remove references in Ticket Rules first.”

## Right Slim Rail (static tips)

* **Tip:** “Ticket Rules set most workflows automatically. Defaults here are used only if no rule assigns a workflow.”
* **Note:** “A workflow can be default for exactly one target: a **single queue** or **Global**.”
* **Link chips:** quick links to:

  * `tech.admin.settings.ticket.rules.index`
  * `tech.admin.settings.ticket.workflow.create`

## List Data & Sorting

* **Default sorting:** `Updated` desc
* **Secondary:** Name asc (when Updated equal)
* **Client-side search:** matches Name (exact or contains)
* **Status filter:** Enabled/Disabled

## Error & Empty States

* **Empty List:** show CTA to create
* **Action failure (toggle/default/duplicate/delete):** show inline alert under page header; row remains unchanged

## Reused/Shared Elements (from rules.index)

* Alerts placement (just below header)
* Table styling and action pattern
* Basic search/filter bar
* Confirmation modal style
