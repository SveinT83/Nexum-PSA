# UI/UX – Livewire Component: `MessagePreview`

**URL/ID:** livewire.tech.inbox.message-preview (views/livewire/tech/inbox/messagePreview)
**Access Level:** `inbox.view` (required). Actions may require `ticket.create` and/or `inbox.manage`.
**Date:** 2025-10-16
**Status:** Not implemented
**Difficulty:** Medium
**Estimated Time:** 1.0–1.5 hours

---

## Purpose & Function

Show a **detail preview** of the currently selected inbox message in the right-side column, enabling quick triage without leaving the inbox view.

Goals:

* Render sender, subject, time, state/labels, and a safe HTML preview.
* Provide actionable controls to **link to an existing ticket**, **create a new ticket**, **label**, and **archive/mark done**.
* Update in real time when the underlying message changes (e.g., linked/relabeled).

---

## Recommended File Structure

* **Class:** `app/Http/Livewire/Tech/Inbox/MessagePreview.php`
* **View:** `resources/views/livewire/tech/inbox/messagePreview.blade.php`

---

## Data Inputs

* Source: Inbox service `/api/inbox/messages/{id}` (detail) and `/api/inbox/message/{id}/thread` (optional).
* Fields: `id`, `from_name`, `from_email`, `to[]`, `cc[]`, `subject`, `received_at`, `state`, `labels[]`, `snippet`, `html_sanitized`, `attachments[]`, `message_id`, `references[]`, `linked_ticket_id` (nullable).

Security: HTML must be **sanitized** server-side; display a safe subset only (no external scripts/images without proxying).

---

## UI & Layout (Bootstrap description)

* **Container:** rightbar card with sticky header actions.
* **Header:** From (name/email), Subject, Received time (relative), State/Labels badges.
* **Body:** sanitized HTML preview or plaintext fallback; attachments list with size and download/view buttons.
* **Footer actions:**

  * Link to existing ticket (search by ID/title)
  * Create ticket (prefill from parsed client/site if available)
  * Add/Remove label(s)
  * Mark done / Archive

Icons (suggested): `user`, `mail`, `paperclip`, `link`, `plus`, `tag`, `archive`, `check`.

---

## Interactions & UX

* **Selection:** listens for `messageSelected { id }` and loads details.
* **Threading (optional):** show a collapsible thread timeline if `references` found.
* **Optimistic actions:** when linking/labeling/archiving, update the UI immediately; confirm via API, revert on failure.
* **Keyboard:** `Enter` activates primary action; `L` label; `A` archive; `K` link to ticket; `C` create ticket.
* **Attachments:** click to download or open preview (if supported).

---

## Events (contracts)

* **Listens:**

  * `messageSelected { id }` → load detail
  * `inbox.updated { id, changes }` → merge updates if previewing same message
* **Emits:**

  * `messageUpdated { id, changes }` after successful triage action
  * `openTicket { id }` when navigating to the linked ticket

---

## RBAC & Visibility

* **View:** requires `inbox.view`.
* **Link/Create ticket:** requires `ticket.create`.
* **Archive/labels:** requires `inbox.manage` (to be defined if not present).
* Hide or disable buttons based on permissions.

---

## Live Updates & Performance

* Lazy-load body/attachments on selection to keep initial load fast.
* Cache the last N previews (e.g., 3) for quick back-and-forth navigation.
* Debounce rapid selection changes to avoid thrashing.

---

## Validation & Error Handling

* Validate selected `id` belongs to tenant and permitted accounts.
* On API failure: show non-blocking error and keep prior preview.
* If sanitized HTML empty, fallback to plaintext snippet.
* Prevent double-submit on rapid action clicks.

---

## Testing & Acceptance Criteria

* Selecting a message loads details within 300 ms (warm cache).
* Linking/creating ticket updates state and emits `messageUpdated`.
* Labels and archive actions reflect immediately; persisted on refresh.
* Sanitized HTML never executes active content; images loaded via proxy if shown.
* RBAC correctly hides unauthorized actions.

---

## Reuse & Dependencies

* **Reusable in:** `/tech/inbox` rightbar; could also appear on a ticket’s email tab.
* **Depends on:** Inbox detail API, permissions, event bus, sanitizer service.

---

## Implementation Notes (for Copilot)

* Public props: `$messageId`, `$message`, `$canCreateTicket`, `$canManageInbox`.
* Methods: `loadMessage($id)`, `linkTicket($ticketId)`, `createTicket()`, `toggleLabel($label)`, `archive()`.
* Ensure view renders gracefully with partial data while loading (skeleton states).
