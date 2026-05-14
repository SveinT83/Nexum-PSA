# UI/UX – Livewire Component: InboxFilters

URL/ID: livewire.tech.inbox.filters (views/livewire/tech/inbox/inboxFilters)  
Access Level: inbox.view  
Date: 2025-10-16  
Status: Not implemented  
Difficulty: Low–Medium  
Estimated Time: 0.5–1.0 hour

---

## Purpose & Function

Provide the left sidebar filtering controls for the Tech Inbox. Consolidates account selection, state toggles, labels/tags, date range, attachments, and optional client/site scoping. Emits a single normalized filter payload consumed by InboxFeed.

Goals:

- Make triage efficient by narrowing the message set.
- Keep payloads minimal and consistent across components.
- Remember the last-used filters per user/session.

---

## Recommended File Structure

- Class: app/Http/Livewire/Tech/Inbox/InboxFilters.php
- View: resources/views/livewire/tech/inbox/inboxFilters.blade.php

---

## Controls & Layout (Bootstrap description)

- Accounts (multi-select): list of permitted mailboxes/queues (RBAC-scoped).
- State (checkbox group): new, untriaged, awaiting-link, linked, archived (presence depends on permissions).
- Labels/Tags (multi-select): autocomplete; show most-used.
- Date range: presets (Today, 24h, 7d) + custom (from/to).
- Attachments: switch has_attachments on/off.
- Client/Site (optional): pickers if parser found entities.
- Reset/Apply: buttons pinned at bottom; Apply emits immediately; Reset clears to defaults.

Icons (suggested): filter, tag, calendar, paperclip, users.

---

## Events (contracts)

- Emits: filtersUpdated with payload:

```json
{
  "accounts": ["support@...","sales@..."],
  "state": ["new","untriaged"],
  "labels": ["urgent","billing"],
  "date": {"from": "2025-10-16T00:00:00Z", "to": "2025-10-16T23:59:59Z"},
  "has_attachments": true,
  "client_id": null,
  "site_id": null,
  "q": null
}
```

- Listens (optional): filtersReset to revert to defaults; searchUpdated to include text query (q) into payload without changing UI selections.

---

## Behavior & UX

- Apply emits immediately; also emit on change for simple toggles (state) to reduce clicks.
- Collapsible sections for cleanliness (Accounts, State, Labels, Date).
- Show active filter count chip in the sidebar header.
- Persist last-used filters in session/local storage for the user.
- Keyboard: Tab order respects logical grouping; Enter applies; Esc clears a focused control.

---

## RBAC & Visibility

- Render for users with inbox.view.
- Account list must be permission-scoped (only mailboxes user can see).
- Hide states/actions the user is not allowed to access (e.g., archived).

---

## Data & Integration

- Source lists: Accounts (/api/inbox/accounts), Labels (/api/inbox/labels), Client/Site (optional /api/clients, /api/sites).
- InboxFeed combines filtersUpdated with sortUpdated and searchUpdated to query /api/inbox/messages.

---

## Validation & Error Handling

- Validate account ids against allowed list; ignore invalid entries.
- Clamp date ranges (max span, e.g., 90 days).
- If sources fail to load, show placeholders and allow minimal filtering (state + date).

---

## Testing & Acceptance Criteria

- Emitted payload matches UI selections exactly.
- InboxFeed reloads with correct subset when filters change.
- Persisted filters restore on page reload.
- RBAC prevents access to unauthorized accounts/states.

---

## Reuse & Dependencies

- Reusable in: /tech/inbox sidebar; could be embedded in dashboard mini-inbox.
- Depends on: accounts/labels APIs, auth context, event bus to InboxFeed.

---

## Implementation Notes (for Copilot)

- Public state: $accounts = \[\], $state = \['new','untriaged'\], $labels = \[\], $date = null, $hasAttachments = null, $clientId = null, $siteId = null, $q = null.
- Methods: apply(), resetFilters(), syncSearch($q).
- Use lightweight computed property to count active filters for header chip.
- Keep emitted payload keys stable to simplify downstream consumers.