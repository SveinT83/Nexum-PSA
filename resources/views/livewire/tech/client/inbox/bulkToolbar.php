# UI/UX – Blade Component: `BulkToolbar` (Inbox)

**URL/ID:** blade.tech.inbox.bulk-toolbar (views/components/inbox/bulkToolbar)
**Access Level:** `inbox.view` (render); actions require `inbox.manage` and/or `ticket.create` depending on operation
**Date:** 2025-10-16
**Status:** Not implemented
**Difficulty:** Low–Medium
**Estimated Time:** 0.5–1.0 hour

---

## Purpose & Function

Provide **bulk triage controls** in the Inbox header for operating on multiple selected messages at once. Keeps layout light (Blade-only) while delegating data changes to sibling Livewire components (e.g., `InboxFeed`, `MessagePreview`).

Goals:

* Efficiently handle repetitive actions on many messages.
* Keep selection model clear and predictable.
* Respect RBAC for sensitive operations.

---

## Placement & Relationships

* **Placement:** Inbox page header toolbar (top area).
* **Works with:** `InboxFeed` (selection state), `InboxFilters` (scope), `InboxSorter` (ordering).
* **Selection source:** `InboxFeed` exposes selected IDs via event/state; `BulkToolbar` renders buttons and triggers actions.

---

## Controls & Layout (Bootstrap description)

* **Select all (current page):** checkbox + dropdown caret for scope options.
* **Bulk actions (buttons or dropdown):**

  * **Acknowledge / Mark done** (changes state to `linked` or `done` depending on policy).
  * **Assign label(s)** (opens small popover list with multi-select).
  * **Archive** (move to archived state).
  * **Create tickets** (optional; batch create with constraints – usually disabled unless policy permits).
* **Counter chip:** shows count of selected items.
* **Clear selection** action.

Icons (suggested): `check` (ack), `tag` (labels), `archive`, `trash-2` (if delete is allowed), `select-all`.

> Keep the toolbar compact; prefer a single dropdown for less-used actions.

---

## Events (contracts)

* **Listens:**

  * `selectionChanged { ids: [] }` from `InboxFeed` (updates count & enable/disable).
* **Emits (requests):**

  * `bulk:acknowledge { ids: [] }`
  * `bulk:label { ids: [], labels: [] }`
  * `bulk:archive { ids: [] }`
  * `bulk:createTickets { ids: [] }` (optional)
* **Emits (selection control):**

  * `selection:selectAll` (current page)
  * `selection:clear`

> Livewire siblings perform the actual mutations; `BulkToolbar` only orchestrates UI intent.

---

## Behavior & UX

* **Enable/disable:** buttons disabled when no selection, or when user lacks permission for that action.
* **Confirmation:** destructive actions (archive) show small confirm popover.
* **Label picker:** searchable multi-select; remembers last used labels.
* **Feedback:** show non-blocking toast on success; revert and notify on failure.
* **Keyboard:** `Shift+A` acknowledge, `Shift+L` label, `Shift+R` archive; `Ctrl/Cmd+A` selects all on page.

---

## RBAC & Visibility

* Render if user has `inbox.view`.
* **Action gates:**

  * Acknowledge/Archive → `inbox.manage`
  * Create tickets → `ticket.create`
  * Labels → `inbox.manage` (or label-specific policy)
* Hide actions not permitted by current user’s role/tenant scope.

---

## Integration & Data Flow

* The Inbox page maintains selection state in `InboxFeed` (source of truth).
* `BulkToolbar` subscribes to `selectionChanged`; on action, emits `bulk:*` events with the selected IDs.
* `InboxFeed` (or a dedicated Livewire `InboxBulkActions`) consumes `bulk:*`, performs API calls, updates list, and emits `feedCountUpdated`/`inbox.updated`.

---

## Validation & Error Handling

* Validate that `ids` are visible and belong to permitted accounts.
* Handle partial failures (some IDs succeed, some fail) with per-item feedback.
* Prevent double-submission by disabling buttons during processing.

---

## Testing & Acceptance Criteria

* Selection count updates correctly as rows are selected/deselected.
* Bulk actions emit expected events with accurate IDs.
* Actions are gated correctly by RBAC.
* UI provides clear success/failure feedback without blocking.
* “Select all on page” respects current sort/filter scope.

---

## Reuse & Dependencies

* **Reusable in:** other list views that support bulk operations (tickets, leads).
* **Depends on:** `InboxFeed` for selection state; optional `InboxBulkActions` consumer component.

---

## Implementation Notes (for Copilot)

* Blade-only component hosting buttons and layout; no heavy logic.
* Rely on Livewire events for state and mutations.
* Add responsive wrapping for small screens; keep actions in a dropdown on xs/sm.
* Keep aria-labels and tooltip texts descriptive for accessibility.
