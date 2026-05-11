# UI/UX – Livewire Component: InboxSummary

URL/ID: livewire.dashboard.inbox-summary  
Access Level: dashboard.view.tech, inbox.view  
Date: 2025-10-15  
Status: Not implemented  
Difficulty: Low  
Estimated Time: 1.0 hour

---

## Purpose & Function

Provide a compact, always-visible snapshot of Global Inbox status so a technician can quickly gauge triage workload without opening the inbox. Focus on new, untriaged, and awaiting-link message counts, with a fast path to the Inbox view.

Goals:

- Surface actionable counts (what needs attention now).
- One-click navigation to /tech/inbox with relevant filters pre-applied.
- Minimal footprint suitable for sidebar/rightbar.

---

## Recommended File Structure

- Class: app/Http/Livewire/Dashboard/InboxSummary.php
- View: resources/views/livewire/dashboard/inbox-summary.blade.php

---

## Data Inputs

- Source: Inbox service or API /api/inbox/stats.
- Fields:
  - new_count – messages recently received, not yet viewed
  - untriaged_count – messages categorized as needs-triage
  - awaiting_link_count – messages that require linking to an existing ticket
  - (optional) last_received_at – timestamp of newest message

All queries must respect tenant/user scoping and mail account permissions.

---

## UI & Layout (Bootstrap description)

- Container: compact stat.card with three inline metric items.
- Items: label + number; optional mini-trend arrow.
- Icon: inbox.
- Placement: left sidebar or rightbar on dashboard.view.tech.

> No HTML included here; rely on shared components like stat.card and grid.auto.

---

## Interactions & UX

- Primary action: clicking the card opens /tech/inbox.
- Deep links: clicking a specific metric opens /tech/inbox?filter=new / ?filter=untriaged / ?filter=awaiting-link.
- Notifications (optional): when new_count increases, show a subtle toast; respect TechnicianStatus (mute when Busy/Offline).
- Accessibility: each metric is keyboard focusable with ARIA labels and opens the correct filtered view.

---

## RBAC & Visibility

- Render only if the user has both dashboard.view.tech and inbox.view.
- If backend denies counts for certain inboxes, omit those metrics rather than showing 0.

---

## Live Updates & Performance

- Refresh cadence: 30–60s polling; optionally subscribe to inbox.received events for push.
- Staggering: randomize initial poll offset to avoid bursts.
- Caching: cache counts for 30s to reduce load; ensure UI still feels live.

---

## Validation & Error Handling

- If stats endpoint fails: display neutral placeholder (e.g., “—”) and retry after interval.
- Do not block rendering of other dashboard widgets.
- Log anomalies (negative counts, inconsistent totals) silently.

---

## Testing & Acceptance Criteria

- Counts match inbox table within a single refresh interval.
- Deep links apply the correct filters.
- Component hides when inbox.view is missing.
- Notification logic respects Technician presence (if integrated).

---

## Reuse & Dependencies

- Reusable in: rightbar of dashboard, header of /tech/inbox.
- Depends on: inbox stats endpoint/service, auth context, optional events bus, TechnicianStatus for notification muting.

---

## Implementation Notes (for Copilot)

- Public props: $new = 0, $untriaged = 0, $awaitingLink = 0, $lastReceivedAt.
- Methods: refresh(), open(filter).
- Events: listen for inbox:received to increment counts.
- Guard rendering with Gates for inbox.view.