# tech.inbox* — Inbox Module (General, Interim)

**Creation date:** 2025-11-03
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 5.0 hours (core 3.0h, alerts 1.0h, polish 1.0h)

---

## Purpose

A fast, predictable Inbox for unmapped/triage email before ticket/lead creation. Optimized for keyboard-driven triage and minimal context switching. Real-time hints via background alerts.

---

## Scope (MVP)

* Read inbound messages that failed confident classification (Global Email Hub).
* Perform triage: create ticket/lead, link to existing ticket, label, archive/delete.
* See parsed hints (client/site/asset candidates).
* Real-time toast/indicator when new items arrive or a rule changes relevance.

Out of scope (for now): bulk reply, campaign tools, provider-specific features.

---

## URLs & Access

* **Index (list):** `tech/inbox` → **permission:** `inbox.view`
* **Show (read):** `tech/inbox/show:{messageId}` → **permissions:** `inbox.view` (+ action-specific gates)
* **Rule shortcut:** opens Settings → Email → Rules with prefilled draft → **permissions:** `email.rules.manage`

Controller paths (matching folder structure):

* `App\Http\Controllers\Tech\Inbox\IndexController@index`
* `App\Http\Controllers\Tech\Inbox\ShowController@show`

---

## Layout & Design (Bootstrap)

Use standard shell with three zones; **no HTML in this doc, no colors.**

* **Top:** title, sort controls, search, results count, bulk actions (RBAC).
* **Main:** list (index) or full message (show).
* **Right panel (narrow):** preview (index) or triage panel (show) with quick actions.

**Icons (suggested):** `inbox`, `filter`, `sort`, `search`, `link`, `plus`, `trash`, `archive`, `tag`, `shield-alert`, `zap`.

**Reusable components/widgets:**

* `ListToolbar` (sort/search/bulk)
* `MessageList` (virtualized rows)
* `PreviewPane`
* `TriagePanel`
* `AttachmentList`
* `Toast/AlertBell` (real-time)

---

## Livewire vs Blade (policy)

* **Blade where possible.**
* **Livewire only where necessary** for real-time and stateful interactions:

  * `InboxFeedLW` (list + infinite scroll + soft real-time updates)
  * `PreviewPaneLW` (index right-panel, loads body/attachments on demand)
  * `TriageActionsLW` (create/link/label without full page reload)
  * `AlertBellLW` (subscribes to background agent events)

Everything else (headers, static filters, show page chrome) in Blade.

---

## Real-time & Background Alerts

* **Background agent:** subscribes to `inbox.received`, `inbox.updated`, `rules.changed` (queued worker). Emits internal events (Broadcast/Redis).
* **UI behavior:**

  * Badge counter in top bar increments.
  * Non-blocking toast with “View” action.
  * Soft-refresh of list (optimistic insert respecting active sort/filter).
* **Transport:** Laravel Echo/WebSockets; fallback poll (15–30s).

---

## Index View — Spec

**URL:** `tech/inbox`
**Access:** `inbox.view`
**Controller:** `Tech\Inbox\IndexController@index`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 2.0 hours

### Purpose

Triage list with keyboard navigation and right-side preview.

### Sorting (Top)

* Fields: `received_at` (default), `from`, `subject`, `size`, `state`, optional `priority_hint`.
* ASC/DESC toggle. Secondary tie-breaker: `received_at` desc.

### Filtering (Left sidebar or collapsible bar)

* Accounts (multi-select)
* State: `new`, `untriaged`, `awaiting-link`, `linked`, `archived`
* Labels/Tags (multi)
* Date range (presets + custom)
* Attachment: has/has-not
* Client/Site hint (if parser suggested)

### List (Main)

* Row fields: from, subject, snippet, received_at (relative), state badge, labels, account.
* Infinite scroll; highlight on newly arrived rows.
* Keyboard: Up/Down select, Enter open preview, `/` focus search, `C` create ticket, `K` link, `A` archive.

### Preview (Right panel)

* Header: from, subject, date, labels.
* Body: sanitized HTML preview (lazy load), attachments list.
* Mini-thread: show related via `message_id/references` (collapse/expand).
* Quick actions: Create Ticket/Lead, Link, Label, Archive/Delete.

---

## Show View — Spec

**URL:** `tech/inbox/show:{messageId}`
**Access:** `inbox.view` (+ gated actions)
**Controller:** `Tech\Inbox\ShowController@show`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 1.5 hours

### Purpose

Full read experience with complete body, headers (toggle), and richer triage.

### Main

* Full HTML-rendered body with inline images.
* Attachments block (download/open in new tab).
* Thread timeline (same conversation, compact rendering).

### Right panel (Triage)

* **Create Ticket** (prefill client/site if known; choose queue/category/priority)
* **Create Lead**
* **Link to existing Ticket** (ID/title search modal)
* **Labels/Tags** (typeahead)
* **Create Rule** (opens rule editor with prefilled condition: from/domain/subject)
* **Archive/Delete** (RBAC; confirm destructive)
* **Audit snippet** (who triaged, when)

---

## Actions & RBAC

* View message: `inbox.view`
* Create ticket: `ticket.create`
* Create lead: `lead.create`
* Link to existing ticket: `ticket.edit`
* Label/Archive: `inbox.manage` (or reuse `email.admin` if preferred)
* Create rule: `email.rules.manage`
* Delete (hard): `email.admin`

> Enforce visibility by hiding unauthorized buttons.

---

## Data & Threading (read-only in Inbox)

* Primary keys: `id`, `account_id`, `message_id`, `received_at`, `state`, `labels[]`.
* Threading signals: `Message-ID`, `In-Reply-To`, `References`; fallback subject token `[#1234]`.
* Parser hints: `client_candidate`, `site_candidate`, `asset_candidate`.

> Replies matching existing items should normally bypass Inbox; items here are *unmapped/ambiguous*.

---

## Performance & Safety

* Virtualize long lists; page size 50; prefetch next page.
* Lazy-load message bodies/attachments.
* Sanitize HTML aggressively; block remote loads unless proxied; show placeholders.
* Optimistic UI for triage; rollback on failure.

---

## Telemetry & Audit

* Emit metrics: `inbox.new_count`, `triage.action_count`, `time_to_triage`.
* Audit log on: create/link/archive/delete/label/rule-create (who/what/when).

---

## Open Items (confirm later)

* Whether “Archive” maps to soft-delete with retention (30–90 days) or a separate bin.
* Whether technicians can reply directly from Inbox (pre-ticket) — default: **disabled**.
* Label taxonomy shared with Tickets, or Inbox-only tags.

---

## Implementation Checklist (MVP)

1. Routes + gates (Blade skeleton)
2. List + preview (Livewire where needed)
3. Show page (Blade)
4. Triage actions (Livewire)
5. Background agent + broadcasts
6. Sanitizer + attachment handling
7. RBAC + audit
8. Metrics & toasts
