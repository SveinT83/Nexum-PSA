# tech.tasks.index — View Specification (Tasks List)

**Creation date:** 2025-10-29
**URL:** `tech.tasks.index` → `/tech/tasks`
**Access:** read `task.view`; manage `task.manage`; assign `task.assign`; bulk `task.bulk`; export `task.export`
**Controller:** `App\Http\Controllers\Tech\Tasks\IndexController@index`
**Livewire/Components:** `App\Livewire\Tech\Tasks\Index\*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 5.0 hours

---

## Purpose

Fast, predictable list of tasks across all sources (tickets, orders, templates, groups), optimized for triage and daily execution. Supports dependency awareness (parent/child), powerful filtering, and quick actions—desktop and mobile.

---

## Default View & Filters

* **Default list:** *My open tasks* (assignee = current user) and status in **Open, In Progress, Blocked**; excludes **Done** and **Canceled**. Saved as a built-in view, non-deletable.
* **Quick filter pills:** `My tasks`, `All tasks`, `Due today`, `Overdue`, `No due date`, `Blocked`, `Unassigned`, `High priority`.
* **Core filters:** text search, status (multi), assignee (single/multi), queue, category, priority, due date range, created range, has parent/child, blocked-by-parent, ticket (id), client, site, template group, has attachments, updated by me.
* **Sort options:** Due date, Priority, Status, Updated, Created, Title, Assignee. (Asc/Desc; multi-sort with modifier key.)
* **View mode:** Table (default). Optional *Tree lens* toggle that visually nests rows **without** removing the Parent column.

---

## Columns (desktop)

1. **Title** (click → `tech.tasks.show:{id}`)
2. **Status** (badge) — Open / In Progress / Blocked / Done / Canceled
3. **Due date** (relative + exact on hover)
4. **Ticket** (chip link; shows ticket id/title)
5. **Parent** (link)
6. **Blocked by parent?** (icon/badge; tooltip explains dependency)
7. **Priority** (badge)
8. **Queue / Category**
9. **Assignee** (avatar + name)

**Mobile/compact:** Title, Status, Due (line 1) • Ticket chip, Assignee avatar (line 2). Overflow reveals the rest.

**Icons (suggested):**

* Status: circle, play, pause/ban, check, x
* Blocked: link-off / lock
* Priority: chevrons / exclamation
* Ticket: ticket
* Parent: arrow-merge / tree

---

## Actions

* **Row quick actions (hover or swipe):** Change status, Assign/reassign, Set due date, Open in ticket, Copy link, More (⋯).
* **Bulk actions** (with permission `task.bulk`): Change status, Assign, Set due date, Set priority, Add/remove queue/category, Export CSV.
* **Keyboard shortcuts:** `A` assign, `S` status, `D` due date, `F` focus search, `Enter` open selected.

---

## Dependency Awareness

* Each row computes `blocked = (has_parent && parent.status != Done)`.
* If **blocked**, render a clear badge and disable *Start/Progress* quick action (tooltip: *“Finish parent first”*).
* *Tree lens* (toggle) shows parent with children indented beneath **when both pass current filters**. Parent/child relationships remain visible via the **Parent** column regardless of lens.

---

## Layout (Bootstrap)

* **Top header:** page title, search, saved views dropdown, quick filter pills, *Tree lens* toggle.
* **Main content:** responsive table with infinite scroll or pagination; sticky header; column chooser.
* **Right slim rail:** contextual inspector for the selected row (readonly preview + quick edits), and filter builder (advanced).

**Reusable components:**

* `ListHeader` (search + saved views + pills)
* `TaskTable` (virtualized rows, sticky columns)
* `RowQuickActions`
* `InspectorPanel`
* `FilterBuilder`
* `SavedViewsDropdown`

---

## Saved Views

* Built-ins: **My open tasks**, **Overdue**, **Due today**, **Unassigned**, **All**.
* Users can create/edit/delete personal saved views (filters + sorts + columns). Permissioned sharing to team/role optional.

---

## Permissions & Visibility

* Users only see tasks permitted by `task.view` scope (e.g., by queue/team).
* Quick actions respect `task.manage` and `task.assign`.
* Ticket links require `ticket.view`.

---

## Performance & Realtime

* Virtualized table (10k+ rows).
* Realtime updates via `tasks.*` broadcast: create, update, reassign, status change.
* Optimistic UI for quick actions with rollback on failure.
* Debounced search; server-side filter/sort.

---

## Empty & Edge States

* **No results:** friendly message + button to clear filters.
* **Blocked tasks only:** hint explaining dependency rules + link to parent.
* **Permissions missing:** show limited metadata and a lock icon.

---

## Integration Notes

* Tickets are the canonical source; orders/sales tasks also enter via their ticket.
* Template groups: when filtering by a template group, show tasks instantiated from that group; group id available in columns/filters.
* SLA profiles (future): planned badges when a task inherits SLA from its ticket/queue.

---

## QA Checklist (dev-facing)

* Default view enforces: assignee=current user AND status∈{Open, In Progress, Blocked}.
* Parent/child rendering works in both **Table** and **Tree lens** modes.
* Blocked-by-parent badge condition accurate; actions disabled accordingly.
* All links route to `tech.tasks.show:{id}` or `tech.tickets.show:{id}` as appropriate.
* Realtime broadcast updates selected rows without flicker.
