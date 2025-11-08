# tech.ticket.index — Functional Specification

**Date:** 2025-10-20
**URL / View Key:** tech.ticket.index
**Route Path:** /tech/tickets
**Permission Required:** ticket.view
**Access Levels:** Technician, Ticket Admin, SuperAdmin
**Controller:** App\Http\Controllers\Tech\TicketsController@index
**Controller (folder map):** app/Http/Controllers/Tech/TicketsController.php
**Status:** Not completed
**Difficulty:** Medium
**Estimated Time:** 5.0 hours

---

## Purpose

A technician-facing ticket list that prioritizes speed, clarity, and focus. The page offers sidebar filtering with persistent (browser-local) memory, two-line list rows with unread indicators, and reliable refresh mechanics. No bulk operations: work one ticket at a time.

---

## Design & Layout (Bootstrap frame)

* **Top header (page toolbar):** Search, sort, manual refresh, item count, and “open in new tab” helper text.
* **Main position:** Virtualized/lazy-loaded ticket list with internal scrolling.
* **Right slim rail:** Live read-only widgets (SLA Monitor, Personal Workload, Refresh Status).

> The dashboard shell is static; content within the list and widgets updates live.

---

## Left Sidebar (Filters & Persistence)

**Elements (checkboxes and inputs):**

* **Ownership**

  * My tickets (default: ON)
  * All tickets
* **Status** (multi-select checkboxes)

  * New, In Progress, Waiting on Customer, On Hold, Resolved, Closed (default: Closed OFF)
* **Priority**

  * P1, P2, P3, P4
* **Customer**

  * Searchable select (customer; optional customer number field shown in results)

**Behavior**

* Any change triggers immediate requery and refresh of the list.
* Filters are **remembered per user in the browser** (e.g., `localStorage`), restored on revisit and page refresh. Not persisted across login/logout.
* Manual **Refresh** button in the header.
* **Auto-refresh** full list reload on interval (default 5 minutes; configurable later in Tickets Settings).

---

## Header Controls

* **Search**: Full‑text across *Title* and *Description*.
* **Sort**: Clickable column headers (primary sort only; toggle asc/desc). Default sort is *Unread first* then *Newest updated*.
* **Refresh**: Manual trigger; shows spinner during reload and updates the timestamp indicator.
* **Updated indicator**: “Updated X minutes ago” (auto-updated after each refresh).
* **Open-in-new-tab tip**: Small hint text and an icon on each row (see Open Behavior).
* **Total counter**: Displays total items for current filter (“Showing N tickets”).

---

## Ticket List (Main)

**Row Density:** Two lines per ticket (title + meta line).
**Scroll Model:** Internal scroll container; **lazy load** more as user scrolls.

**Columns (configurable later in Tickets Settings)**

1. **Ticket ID**
2. **Title** (single-line with truncation)
3. **Customer No.**
4. **Customer**
5. **Contact** (requester)
6. **Queue**
7. **Category**
8. **Priority** (P1–P4) – small colored badge only for the badge (no row coloring)
9. **Status** – small colored tag + text
10. **Last Updated** – relative time (e.g., “12m ago”)
11. **Unread** – blue dot badge (customer reply not yet marked as read)

**Per-row actions:** None. (Open the ticket to act.)

**Open Behavior**

* Default click: open in same view (`tech.ticket.show`).
* Dedicated **new-tab icon button** on the row: opens in a new tab.
* Ctrl/Cmd + click: opens in a new tab.
* Returning from a ticket preserves filters, sort, scroll position, and search.

---

## Unread Indicator Policy

* Visual: **Blue dot** when a customer reply exists and message is not marked as read.
* Behavior: Controlled by Tickets Settings later

  * **Automatic**: mark as read on ticket open
  * **Manual**: requires explicit “Mark as read” action inside the ticket view

---

## Widgets (Right Slim Rail)

* **SLA Monitor**: Counts and small list of tickets at risk/breached within current filters.
* **Personal Workload**: Mine vs team counts by status (read-only snapshot).
* **Refresh Status**: Last updated timestamp + next refresh countdown.

> Widgets are read-only here; interaction happens inside ticket details or settings.

---

## Live Update & Refresh

* **Auto-refresh**: Whole list reload on interval (default 5 minutes). Interval configurable in Tickets Settings.
* **Manual refresh**: Button in header; shows spinner during load.
* **Indicator**: “Updated X minutes ago.” No partial (diff) updates; full reload only.

---

## Settings Hooks (to document under Tickets Settings)

* **Columns**: Enable/disable per column for `tech.ticket.index`.
* **Auto-refresh interval**: Allowed range and default (e.g., 1–15 minutes; default 5).
* **Unread policy**: Automatic vs manual mark-as-read on open.
* **Default sidebar state**: e.g., My tickets ON, Closed OFF.

---

## Suggested Components (Livewire-friendly)

* **ticketsList** (reusable): data table with lazy load + sortable headers + unread/SLA flags.
* **personalWorkload** (existing livewire in tickets area): right-rail snapshot.
* **slaMonitor** (existing livewire in tickets area): right-rail snapshot.
* **refreshStatus**: lightweight widget for last/next refresh.

> Prefer Bootstrap components for layout. Keep modals/popovers minimal; no row-level quick actions.

---

## Icons (no colors specified)

* **Priority badge**: small level/flag icon with P1–P4 text.
* **Status tag**: small tag icon with status text.
* **Unread**: solid dot icon.
* **Open in new tab**: external-link / new-tab icon.
* **Refresh**: rotate/refresh icon with spinner state.

---

## Non-functional

* **Performance**: Virtualized list or efficient pagination to keep row rendering smooth.
* **Accessibility**: Keyboard navigation for list rows; readable status labels.
* **Audit**: None on this view; actions are read-only here. Audit is on ticket detail/actions.

---

## Notes & Exclusions

* No bulk selection or bulk actions by design.
* “Closed” tickets are hidden by default; opt-in via sidebar checkbox.
* Customer filters should debounce input to avoid chatty queries.
* This spec avoids HTML; it enumerates components, behaviors, and interactions only.
