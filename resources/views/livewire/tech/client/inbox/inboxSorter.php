# UI/UX – Livewire Component: InboxSorter

URL/ID: livewire.tech.inbox.sorter (views/livewire/tech/inbox/inboxSorter)  
Access Level: inbox.view  
Date: 2025-10-16  
Status: Not implemented  
Difficulty: Low  
Estimated Time: 0.5–1.0 hour

---

## Purpose & Function

Provide sorting and quick-search controls for the Inbox view. This component sits in the page header and emits events that the InboxFeed listens to. It centralizes sort field, sort direction, and free-text search.

Goals:

- One place to change ordering and filter by text without opening the sidebar.
- Fast, debounced search that does not overload the server.
- Clear visual indication of current sort + result count.

---

## Recommended File Structure

- Class: app/Http/Livewire/Tech/Inbox/InboxSorter.php
- View: resources/views/livewire/tech/inbox/inboxSorter.blade.php

---

## Controls & Layout (Bootstrap description)

- Sort field select: received_at (default), from, subject, size, state, (optional) priority_hint.
- Direction toggle: ASC / DESC (single button that toggles).
- Search input: free-text; matches from, subject, and snippet.
- Results count (read-only): receives count updates from InboxFeed via event.
- Placement: header toolbar, left-aligned; search expands on focus.

Icons (suggested): sort-asc, sort-desc, search, hash (count).

---

## Events (contracts)

- Emits:
  - sortUpdated { field, dir } whenever field or direction changes.
  - searchUpdated { q } after debounce.
- Listens:
  - feedCountUpdated { total } to update the visible result count.

Debounce: 250–400 ms for search input.

---

## Behavior & UX

- Persist last used sort (optional) in session/local storage.
- Keyboard: / focuses search; Esc clears search and blurs.
- Accessible labels for all controls; announce result count changes to screen readers.

---

## RBAC & Visibility

- Render only for users with inbox.view.
- No special admin permissions required.

---

## Data & Integration

- InboxFeed combines sortUpdated + searchUpdated with current sidebar filters to query /api/inbox/messages.
- Avoid coupling: do not fetch data directly from this component; act purely as control surface.

---

## Validation & Error Handling

- Validate field against allowed set; default to received_at if invalid.
- Validate dir is asc|desc; default to desc.
- Sanitize q length (max 128 chars) and trim whitespace.

---

## Testing & Acceptance Criteria

- Changing sort updates the InboxFeed ordering.
- Debounced search filters results without excessive requests.
- Result count reflects the filtered set.
- Component hides without inbox.view.

---

## Reuse & Dependencies

- Reusable in: /tech/inbox header; can be embedded on dashboard mini-inbox.
- Depends on: events bus for communication with InboxFeed.

---

## Implementation Notes (for Copilot)

- Public state: $field = 'received_at', $dir = 'desc', $q = '', $total = null.
- Methods: setField($f), toggleDir(), updatedQ() (debounced emit).
- Wire keys for stable renders; keep DOM minimal in header.