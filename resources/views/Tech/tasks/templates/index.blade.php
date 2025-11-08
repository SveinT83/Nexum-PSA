# tech.tasks.template.index — View Specification (Task Templates List)

**Creation date:** 2025-10-29
**URL:** `tech.tasks.template.index`
**Access & permissions:** `template.ticket.manage`
**Controller path:** `App\Http\Controllers\Tech\Tasks\Template\IndexController@index`
**Livewire/Components:** `App\Livewire\Tech\Tasks\Templates\Index\*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 3.0 hours

---

## Purpose

List **task templates** (templates only, not nested tasks) with fast search/filter and predictable row actions. Clicking a row opens the template details (`tech.tasks.template.show`).

---

## Design & Layout (Bootstrap)

**Top section**

* Page title: **Task Templates**
* Search input (debounced)
* Filters (compact): Queue, Category/Tags, Owner, Updated (date range)
* Metrics widgets (small): Total Templates, Total Tasks (sum), Last Updated
* Primary button: **Create Template** (if permission)

**Main section** — Table (virtualized for performance)

* Row click → `tech.tasks.template.show:{templateId}`
* Sticky header with sortable columns

**Right-side panel (narrow)**

* "Selected Template" quick facts (on row focus): Name, Queues (badges), Tasks (#), Last updated, Owner
* Keyboard hints (↑/↓ to move, Enter to open)

---

## Columns (Table)

1. **Template Name** — text, left aligned; icon: `file-stack`
2. **Queues** — badges of queue names; tooltip shows full list
3. **Tasks (#)** — integer count from template definition
4. **Tags** — small badges (optional)
5. **Owner** — display name (technician)
6. **Last Updated** — datetime, relative + tooltip absolute
7. **Actions** — **Delete** (trash icon) only

Sorting: Name, Tasks (#), Last Updated, Owner
Default sort: Last Updated (desc)

---

## Actions & Behavior

* **Row click:** Navigate to `tech.tasks.template.show:{id}`
* **Delete (per row):** Opens confirm modal: *“Delete template ‘{name}’? This cannot be undone.”*

  * Secondary checkbox: *Also delete child tasks?* (unchecked by default; disabled if not supported yet)
  * Requires `template.ticket.manage`
  * All deletes are logged (Audit)
* **Create Template:** Opens `tech.tasks.template.create`
* **Keyboard:** ↑/↓ to move selection, Enter to open, Del to delete (with confirm)

---

## Filters & Query Model

* **Queue** (multi-select) — includes only templates tagged to selected queues
* **Tags** (multi-select)
* **Owner** (user picker)
* **Updated range** (date picker)
* **Search** — matches Name and Tags (prefix + contains)

State stored in URL query for sharable views.

---

## Empty States & Loading

* Empty result: *“No templates match your filters.”* with **Clear filters** link
* No data yet: *“You haven’t created any task templates.”* with **Create Template** CTA
* Skeleton rows while loading

---

## Components & Widgets

* **SearchBox** (reusable)
* **BadgeList** for Queues/Tags
* **TableToolbar** with Filters
* **MetricsMiniCards** (Total Templates, Total Tasks, Last Updated)
* **ConfirmModal** (Delete)

---

## Logging & Audit

* Log delete actions with template id, name, actor, timestamp, and reason (if provided)
* View loads include telemetry for list performance (internal metric)

---

## Permissions & Policies

* View requires `template.ticket.manage`
* Delete requires `template.ticket.manage`
* Create button visible with `template.ticket.manage`

---

## Events & Realtime

* Broadcast on create/update/delete to refresh table in-place
* Optimistic UI on delete with rollback on error

---

## Error States

* Delete blocked: show toast with reason (e.g., template locked by policy)
* Network/server errors: inline row error + retry option

---

## Notes for Reuse

* Table, filters, and confirm modal are reusable patterns across admin lists
* Keep column set minimal; detailed fields live in `tech.tasks.template.show`
