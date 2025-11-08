# UI/UX – Livewire Component: InboxFeed

URL/ID: livewire.tech.inbox.feed (views/livewire/tech/inbox/inboxFeed)  
Access Level: inbox.view (required); triage actions may require ticket.create / inbox.manage  
Date: 2025-10-16  
Status: Not implemented  
Difficulty: Medium  
Estimated Time: 1.5–2.0 hours

---

## Purpose & Function

Render a live-updating, scrollable list of inbox messages for triage. New emails must appear without page refresh, ordered according to current sort and filters from sibling components.

Goals:

- Smooth real-time insertion of new items (top or proper position by sort).
- Infinite scroll / windowed rendering for performance.
- Keyboard-first triage navigation with quick preview selection.

---

## Recommended File Structure

- Class: app/Http/Livewire/Tech/Inbox/InboxFeed.php
- View: resources/views/livewire/tech/inbox/inboxFeed.blade.php

---

## Data Inputs

- Primary source: Inbox service or API: /api/inbox/messages (paged).
- Fields per item: id, from_name, from_email, subject, snippet, received_at, state (new|untriaged|awaiting-link|linked|archived), labels\[\], account, has_attachments.
- Parameters: page, page_size, sort_field, sort_dir, filters (accounts, state, labels, date range, has_attachments, client/site).

All results must respect tenant and mailbox scoping (RBAC).

---

## UI & Layout (Bootstrap description)

- Container: card.shell body hosting a virtualized list (windowed).
- Row layout: left: sender + subject + snippet; right: time badge + state/labels.
- Selection: highlight selected row; keep selection when new items arrive.
- Empty state: informative message + link to settings if no accounts.

Icons (suggested): inbox, paperclip (attachment), tag, clock.

---

## Interactions & UX

- Select row: updates MessagePreview via event.
- Infinite scroll: load next page when near bottom; show loading indicator.
- New mail arrival: insert item at correct position; animate subtle highlight.
- Keyboard: Up/Down navigate, Enter open preview, J/K alt navigation, / focus search.

---

## Events (contracts)

- Listens:
  - filtersUpdated(payload) → reload from page 1.
  - sortUpdated(payload) → reorder/reload.
  - inbox.received(payload) → insert/update item.
  - inbox.updated(payload) → update state/labels for one item.
- Emits:
  - messageSelected { id } when a row is focused/clicked.

Payload guidelines:

- payload contains id, minimal fields to re-render, and received_at for sorting.

---

## Live Updates & Performance

- Transport: WebSockets (Laravel Echo) with fallback polling (15–30s).
- Windowing: render only visible rows (+ buffer) to avoid DOM bloat.
- Batching: coalesce rapid events (50–200 ms) to single DOM update.
- Staggered fetch: randomize initial delay across clients.

---

## RBAC & Visibility

- Render only for users with inbox.view.
- Hide/disable actions the user is not permitted to perform (ticket.create, inbox.manage).

---

## Validation & Error Handling

- On fetch error: retain current list, show non-blocking banner, retry with backoff.
- On invalid payload: ignore and log quietly.
- Ensure deduplication on event replay (idempotent insert/update).

---

## Testing & Acceptance Criteria

- New emails appear in < 2s via WebSocket; within one poll cycle on fallback.
- Infinite scroll loads additional pages without duplicates or gaps.
- Sorting and filtering remain consistent between feed and preview.
- Selection persists during updates and page loads.

---

## Reuse & Dependencies

- Reusable in: /tech/inbox main area; compact version can appear on dashboard.
- Depends on: Inbox API/service, Echo channel, shared sort & filters state.

---

## Implementation Notes (for Copilot)

- Public props/state: $items, $page, $hasMore, $sort, $filters, $selectedId.
- Methods: loadPage(), applyFilters($f), applySort($s), insertOrUpdate($payload).
- Use a stable key (wire:key="inbox-{{$id}}").
- Consider a lightweight virtualization approach (e.g., windowed slice calculation) to keep DOM under \~150 nodes.