# tech.tasks.modal-create — Reusable Task Creation Modal (View + Livewire)

**Creation date:** 2025-10-29
**URL:** *(invoked inline as modal; no direct route)*
**Access & permissions:** `task.create` (and `ticket.view` on owning ticket)
**Controller path:** `App\Livewire\Tech\Tasks\ModalCreate`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 5.5 hours (modal view 2.5h + component 3.0h)

---

## Purpose

A universal **Bootstrap modal** to create **one task** from anywhere (Ticket, Sales/Order ticket, Task Group context). The modal always requires a **Ticket** context and can optionally apply defaults from a **Task Template Group**.

---

## Design & Layout (Bootstrap)

**Regions**

* **Header**: Title "Create Task", optional context chip “Linked to Ticket #<id>”. Close (×).
* **Body**: Task form (fields below) + inline validation.
* **Footer**: `Cancel` (dismiss), `Save Task` (primary, submits via Livewire/AJAX).

**Icons (suggested, lucide-react names)**

* Title → `list-plus`
* Description → `align-left`
* Assigned to → `user`
* Due date → `calendar`
* Priority → `flag`
* Parent task → `git-branch`
* Template group → `layers`

**Widgets**

* Technician dropdown (searchable)
* Date picker (tenant timezone aware)
* Priority select (Low, Normal, High, Urgent)
* Parent Task select (scoped to current ticket)
* Optional Template picker (when `templateGroupId` provided: show summary + "Apply template" action)

---

## File Structure

```
resources/views/components/tasks/
  ├─ modal-create.blade.php   ← the modal view (invoked from any page)
  └─ form.blade.php           ← optional: the inner form partial reused by modal
```

---

## Invocation (Examples)

* From Ticket view action bar: `+ Task` → opens modal with `ticketId` prefilled.
* From Sales/Order (order is a ticket): same as above.
* From Task list on a Ticket: `Add subtask` → opens with `parentTaskId`.
* From a Template Group browser on a Ticket: `Apply template → Pick item → Create task` (prefills fields).

Invocation is performed via a Livewire event or helper, e.g., `Livewire.emit('task.modal.open', { ticketId, templateGroupId, parentTaskId })`.

---

## Validation Rules (UI hints)

* **Title**: required, ≤ 255 chars
* **Due date**: optional, must be in the future (tenant timezone)
* **Assignee**: optional, must be a valid technician
* **Parent task**: optional, must belong to the same `ticketId`
* **Ticket**: required, user must hold `ticket.view` for that ticket

Inline validation shows field-level messages and disables `Save Task` until required checks pass.

---

## Behavior & Events

* **Open**: load technicians, permissible parent tasks, and (optional) template defaults.
* **Save success**: close modal, toast "Task created successfully.", emit `task.created` with payload `{ taskId, ticketId }` so callers can refresh lists.
* **Save failure**: keep modal open, display inline errors.
* **Accessibility**: focus first invalid field; Esc closes if not submitting.

---

## Security & Logging

* Permission gate on `task.create` plus policy check on `ticketId` (`ticket.view`).
* All writes audited in `task_action_log` with actor, ticket, and payload summary.

---

## Reusable Components

* **TechnicianSelect** (shared) — async search, caches recent results.
* **DateTimeInput** (shared) — respects tenant timezone & business hours.
* **PriorityBadge/Select** (shared) — consistent labels and values.
* **ContextChip** — compact ticket reference in header.

---

# Livewire Component — `App\Livewire\Tech\Tasks\ModalCreate`

**Creation date:** 2025-10-29
**Access & permissions:** `task.create` (+ `ticket.view` on target ticket)
**View:** `components/tasks/modal-create.blade.php`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 3.0 hours

## Inputs (public props)

* `ticketId` **(required)** — owning ticket (all tasks belong to a ticket)
* `templateGroupId` *(optional)* — to prefill defaults and/or expose quick-copy list
* `parentTaskId` *(optional)* — to create a dependent task under an existing task

> If both `templateGroupId` and `parentTaskId` exist, **template defaults apply first**, then `parentTaskId` is set.

## Component State (form)

* `title`
* `description`
* `assigneeId`
* `dueAt` (ISO local string)
* `priority` (enum: `low|normal|high|urgent`, default `normal`)
* `parentTaskId`

## Validation (server)

* `title`: required|string|max:255
* `assigneeId`: nullable|exists:users,id|technician
* `dueAt`: nullable|date|after:now(tenant)
* `priority`: in:low,normal,high,urgent
* `ticketId`: required|exists:tickets,id|policy:ticket.view
* `parentTaskId`: nullable|exists:tasks,id|sameTicket

## Lifecycle & Methods

* `mount($ticketId, $templateGroupId = null, $parentTaskId = null)`
* `open($ticketId, $templateGroupId = null, $parentTaskId = null)` — for event-driven open
* `prefillFromTemplate()` — load defaults/quick fields when `templateGroupId` present
* `save()` — validate → create task → emit `task.created` → close
* `close()` — reset state and dispatch browser event to hide modal

## Events

* **Listens**: `task.modal.open`
* **Emits**: `task.created` with `{ taskId, ticketId }`

## Data Loading

* Technicians list: paged query with role/permission filter
* Parent tasks: tasks for `ticketId` (exclude closed if policy requires)
* Template defaults: minimal fields (title prefix, default priority, default assignee) — no bulk cloning here

## Error Handling

* Validation errors mapped to fields
* Policy/permission errors → toast + close or keep open depending on severity

## Performance

* Debounced technician search
* Lazy-load parent tasks on dropdown open
* Minimal template payloads

---

## Smart UX Suggestions

* Remember last selected assignee per ticket (local storage) to speed repetitive entry.
* Keyboard shortcuts: `Ctrl+Enter` to save, `Esc` to close.
* Autofocus Title on open; select all text if value exists.

---

## QA Checklist

* [ ] Cannot save without Title
* [ ] Rejects past Due date
* [ ] Parent task limited to same ticket
* [ ] Emits `task.created` and caller list refreshes without page reload
* [ ] No leakage of template data when none provided

---

## Future Extensions (modular)

* SLA hints from ticket (pre-set due date window)
* Recurring tasks (hidden for now)
* Attachments field (toggle via settings)
* Multi-language labels (strings via translation files)
