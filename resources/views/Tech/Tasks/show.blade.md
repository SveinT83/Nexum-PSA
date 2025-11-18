# tech.tasks.show — View Specification (Task Workbench)

**Creation date:** 2025-10-29
**URL:** `tech.tasks.show:{taskId}`
**Access:** `task.view` (read), `task.manage` (update), `ticket.reply` (public reply), `ticket.internal.note` (internal notes)
**Controller:** `App\Http\Controllers\Tech\Tasks\ShowController@show`
**Livewire/Components:** `App\Livewire\Tech\Tasks\Show\*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 5.0 hours

---

## Purpose

Et fokusert arbeidsbord for teknikere. Gir full kontekst fra tilknyttet ticket, samtidig som det er raskt å føre tid, oppdatere status og kommunisere med kunde eller internt.

---

## Layout (Bootstrap)

* **Top section**: Tittel, identifikatorer og primærhandlinger.
* **Main section**: Venstre kolonne med oppgavedetaljer og handlinger; midtkolonne med kommunikasjonstråd;
* **Right slim rail**: Relasjoner (parent/child/siblings), hurtiglenker, meta og logg.

> Reusable widgets: `TaskHeaderBar`, `Timebox`, `StatusStepper`, `ThreadPanel`, `RelatedTasksTree`, `QuickActions`, `AuditTrail`.

---

## Header / Summary (Top)

* **Breadcrumb:** Ticket → Task
* **Title:** Task name + status chip
* **Badges:** Priority, Due date (if any), Queue (from ticket), SLA profile (future)
* **Identifiers:** Task ID, Ticket ID
* **Primary buttons:** `Start timer` / `Stop timer`, `Add manual time`, `Mark status`
* **Secondary:** `Jump to ticket`, `Copy deep-link`, `More (...)`

**Icons (suggested):** stopwatch, play, square, edit, check-circle, alert, link, arrow-up-right.

---

## Task Details (Main → left)

* **Fields (read/write where permitted):**

  * Status (with rules and blockers)
  * Assignee (default: current tech if created from self-context)
  * Estimated time (minutes)
  * Effective time (read-only sum of tracked time)
  * Parent requirement flag: "Parent must be completed before start"
  * Notes (task-level, internal)

* **Status model:** `Todo`, `In progress`, `Blocked`, `Waiting`, `Done`, `Canceled`.

  * **Rules:**

    * Cannot transition to `Done` if parent is required and not completed.
    * Warn if no time tracked; allow fallback to estimate (confirm dialog).
    * Log every transition with actor and timestamp.

* **Time tracking:**

  * Start/stop timer (one active per user cross-app).
  * Manual add/edit entries (duration, date, note, billable flag future-ready).
  * Auto-fill suggestion: when closing without time → prompt to use estimate.

* **Quick actions:** `Convert to subtask`, `Make parent`, `Duplicate`, `Move to another ticket` (permission-gated).

---

## Communication (Main → center)

* **ThreadPanel** (shared component with ticket view):

  * **Default filter:** **All messages** (full ticket context).
  * **Toggle:** `Filter: Task only | All messages` (sticky per user).

    * *Task only* → filter on `task_id` in message meta/headers.
    * *All messages* → show entire thread; visually tag messages linked to this task (badge: `Task #{id}`).
  * **Compose modes:** `Public reply (email)` and `Internal note`.
  * **Threading:** Preserve Message-ID/References or ticket token for proper email chaining.
  * **From account:** Auto-select the ticket’s mailbox; allow override when permitted.
  * **Attachments:** Upload/drag-drop with virus scan placeholder; inherit to ticket thread.
  * **Send options:** `Send`, `Send & change status` (choose next state), `Add internal note`.

**Icons:** mail, inbox, message-square, shield (internal), paperclip, send, funnel for filter.

---

## Related Tasks (Right rail)

* **RelatedTasksTree**

  * Show **Parent → Child** hierarchy.
  * Siblings on same level with status chips and quick-jump.
  * Indicators: lock (blocked by parent), clock (due), play (active timer on any task).
  * Actions: `Add subtask`, `Reparent`, `Open in new tab`.

---

## Meta & Logs (Right rail)

* **Properties:** Created by, created at, last updated, labels/tags (future), template origin (if any).
* **AuditTrail:** All writes (status, time, notes, replies, reparent) with actor. Exportable.

---

## Permissions & Guards

* Mirror ticket permissions; minimum `task.view`.
* `ticket.reply` required for public replies; `ticket.internal.note` for internal notes.
* `task.manage` for status/time/relations edits.
* Respect queue/tenant isolation and mailbox ownership.

---

## Events & Integrations

* Emit events: `task.status.changed`, `task.time.logged`, `task.reparented`, `task.email.sent`, `task.note.added`.
* Rule engine hooks (future): on status change or note/reply.
* RMM/Alert merge-ready (future): link alerts to task.

---

## Performance & UX

* Live updates (websocket) for thread and timers.
* Keyboard shortcuts: `T` start/stop timer, `N` note, `R` reply, `S` change status.
* Optimistic UI for timer and status; reconcile on server ack.

---

## Empty/Edge States

* **No emails yet:** Encourage first reply; show template quick-picks.
* **Blocked by parent:** Show reason with one-click navigate to parent.
* **Timer running on another task:** Offer to swap timer.

---

## Telemetry

* Capture time-to-first-action, average dwell time, reply latency, reopen rate.

---

## QA Checklist

* Status transitions respect blockers.
* Email replies thread correctly with the ticket.
* Toggle persists and filters correctly.
* Timer cannot create overlapping entries.
* Audit logs contain all state changes.
