# tech.tickets.show — View Specification

**Date:** 2025-10-20
**URL:** `tech.tickets.show` (route: `/tech/tickets/{ticket}`)
**Access Levels:**

* View: `ticket.view`
* Edit (fields, replies/notes, timer): `ticket.edit`
* Hard actions (merge/split/delete attachments): `ticket.delete` or `ticket.admin`
  **Controller:** `App\Http\Controllers\Tech\Tickets\ShowController` (mirrors view path)
  **Livewire Namespace:** `App\Livewire\Tech\Tickets\Show\*`
  **Status:** Not completed
  **Difficulty:** Medium–High
  **Estimated Time:** 7.5 hours

**Layout Template (Bootstrap):** top header / main content / right slim rail (static widths; non-resizable by user)

---

## 1) Purpose

A single, focused ticket work surface for technicians that consolidates details, time tracking, communication (public replies + internal notes), and system history into one continuously updating feed, with workflow/SLA controls and quick actions in a slim right rail.

---

## 2) High-Level UX Decisions

* **Real-time-lite:** Poll conversation feed via Livewire every 30–60s (tenant-configurable). Do **not** auto-insert into the DOM while the user is typing; instead show a sticky **“New activity”** button.
* **Unified feed:** Public replies, internal notes, and system events in one chronological list, **newest at top**. Filters can hide event types but default shows all.
* **Validation modal:** Workflow validations (time, required fields, KB link, etc.) appear in a modal when attempting **Resolve/Close** (with override support if workflow allows).
* **Static layout:** Header + main column + right slim rail; users cannot reflow or resize.

**Icons (Lucide):** ticket, users, building-2, server, tags, flag, bolt, clock, hourglass, history, mail, file-plus, split, merge, copy, save, x-circle, check-circle, link, bell, printer, file-text, attachment, upload-cloud, image, eye-off, pin, user-plus.

---

## 3) Header Composition

**Left (identity & classification)**

* Ticket Key (e.g., `TD-YYYY-#####`) + **Title**
* Customer / Site / Contact chips (clickable to their views)
* Queue, Category (→ Subcategory) chips
* Priority badge (P1–P4)

**Center (process)**

* **Workflow stepper** (previous ✓, current highlighted, upcoming dimmed)

**Right (ops)**

* SLA chips: **First Response**, **Resolve** (countdown/elapsed)
* **Owner avatar** + Reassign action
* **Timer controls**: Start / Pause / Stop
* **Sync status**: tiny spinner + label “Updated n sec ago”
* If new events arrive: sticky bar under header: **“New activity — Load”**

**Header Actions**

* Save changes (if dirty), Resolve, Close, Reopen (if status=Resolved and workflow permits)

---

## 4) Main Column — Sections & Components

### 4.1 Details (Inline editable)

* Fields: Queue, Category(+Sub), Priority, Impact, Urgency, Status, Workflow (read-only name), Customer, Site, Contact, Related Asset, Tags.
* Edit mode per-field (pencil icon); autosave with inline success/failure toasts.
* Field-level policy hints (e.g., which roles can edit Status).

### 4.2 Conversation Feed (unified)

* **Ordering:** Newest at top.
* **Event types:** Public Reply, Internal Note, System Event (status changes, owner changes, workflow actions), Timer events, Attachment events.
* **Load more:** “Show older messages …” sentinel between groups.
* **Message card content:** author, role, timestamp (absolute + relative), delivery status (for email), body (rendered markdown/plaintext), attachments (preview for images/PDF where allowed).
* **Badges:** “Internal note — technicians only”; “System event”.
* **Per-item actions:** Pin (internal only), Copy link, Edit (time-limited for notes), Delete (permission-gated), Export excerpt (printsafe).
* **Security:** Inline image/file previews are permission-checked and signed URLs.

### 4.3 Composer (opens by button)

**Open button**: “Add reply / note” (sticky at top of feed).
**Mode selector:** `Public Reply` | `Internal Note`
**Editor capabilities:**

* Rich-text with paste/drag **attachments** & **inline images** (auto-optimize images, whitelist: images, PDF, TXT/LOG, ZIP; configurable).
* **Email reply fields (Public Reply only):**

  * From account (preselected by queue; overridable per permission)
  * To/CC (customer + additional contacts; directory-backed search)
  * Template picker (ticket templates; preview before insert)
  * Post-send status: dropdown (e.g., Waiting on Customer, Resolved).
* **Time & Cost:** duration input + **Cost account** selector (both required if workflow demands; available in **both** Reply and Internal Note modes).
* **Send buttons:**

  * *Send* (Public) / *Add note* (Internal)
  * *Send & set status to …* (quick action)
  * Validation summary inline for missing fields (time/required).

---

## 5) Right Slim Rail (static)

* **Timer Widget:** big Start/Pause/Stop, manual add time, total time today/overall.
* **KB Suggestions:** ranked list; open in new tab; quick “Insert link into reply” helper.
* **AI Assistant:** propose steps/checklists/scripts; add as internal note.
* **Quick Actions:** Transfer ticket, Change owner, Set queue/category/priority, Add/Remove tags.
* **Relations:** Links to Customer/Site/Contact/Asset; quick open to RMM/Remote (URL templates).

---

## 6) Advanced Actions (More menu)

* **Merge Tickets** (select target, preview merged feed; source redirects)
* **Link / Relate** (parent/child/related)
* **Split** (select messages → new ticket with copied context)
* **Duplicate** (clone fields/attachments; no conversation)
* **Export PDF** (full conversation + details)
* **Print View** (clean layout)
* **Subscribe / Unsubscribe** (watch notifications)
* **Pin Note** (pin/unpin internal note to top)
* **Watchers** (add/remove internal participants, default CC behaviour)

---

## 7) Real-Time & Refresh Policy

* **Polling:** Livewire interval default 60s (tenant setting).
* **Manual refresh banner:** appear when new items are available; click loads and keeps scroll context.
* **Spinner + timestamp:** in header at all times during polling.
* **Settings path:** `tech.admin.settings.tickets` → **Refresh interval** (seconds).
* **Per-user persistence:** remember composer mode, last applied feed filter, and collapsed cards in localStorage (scoped by user+ticket id).

---

## 8) Time Tracking Policy

* **Default:** manual control.
* **Admin setting:** `admin.settings.ticket.timer`

  * Auto-start/auto-pause rules
  * Rounding: to nearest minute / 15 / 30 / 60 minutes
  * Require time & cost account on **Replies** and **Internal Notes** (toggle; integrates with workflow validators).
* Timer events appear in feed as system events; edits are audited.

---

## 9) Workflow & Validation

* **Resolve/Close modal:** lists unmet requirements (e.g., time logged, cost account set, resolution code, KB link for workaround, ringelogget, etc.).
* **Override:** allowed if user has proper role and workflow permits; requires justification text; audit recorded.
* **Reopen:** available on Resolved (if workflow allows). On Closed, only via admin/workflow rule or explicit permission.

---

## 10) Email & Threading Integration

* Outbound account auto-selected by queue or original inbound account; overridable by permission.
* Replies thread by headers first, then subject token as fallback.
* Delivery/bounce indicators shown on message cards.
* Language policy: templates + optional translation pipeline (logged as internal note when used).

---

## 11) Permissions & Policies

* **Route Guard:** `ticket.view` required.
* **Inline field edits:** `ticket.edit`.
* **Composer send / note:** `ticket.edit`.
* **Advanced actions:** require `ticket.delete` or `ticket.admin` depending on risk.
* **Attachment delete:** `ticket.delete`.
* **Owner change / Transfer:** `ticket.edit` (policy may demand justification).

---

## 12) Settings (Admin)

* `tickets.refresh_interval_seconds` (int)
* `tickets.composer.attachment_whitelist` (array)
* `tickets.composer.image_max_width_px` (int)
* `tickets.timer.auto_start`, `tickets.timer.auto_pause` (bool)
* `tickets.timer.rounding` (enum: 1|15|30|60)
* `tickets.timer.require_time_on_reply`, `tickets.timer.require_time_on_note` (bool)
* `tickets.workflow.validation_modal_enabled` (bool)
* `tickets.outbound.allow_account_override` (bool)

---

## 13) Livewire Components (reusable)

* `TicketHeaderBar` — header rendering + polling indicator + SLA chips
* `TicketDetailsInline` — inline editable fields + policies
* `TicketFeed` — unified feed with type badges and lazy load
* `TicketComposer` — reply/note editor with attachments, templates, time/cost
* `TimerWidget` — start/stop, manual add, totals
* `KbSuggestions` — list + quick insert
* `AiAssistantPanel` — generate suggestions, add as note
* `QuickActionsPanel` — owner/transfer/priority/tags
* `RelationsPanel` — client/site/contact/asset links
* `ValidationModal` — resolve/close checklist + override flow
* `AdvancedActionsMenu` — merge/split/duplicate/export/print/subscribe/pin/watchers

> Mark these as **Livewire** components where real-time or intra-view updates matter. Keep API boundaries clean for re-use across modules.

---

## 14) Events & Audit

* **Domain events:** `TicketReplied`, `TicketNoted`, `TicketMerged`, `TicketSplit`, `TicketDuplicated`, `TicketExported`, `TicketPinned`, `TicketWatcherAdded`, `TimerStarted/Paused/Stopped`, `TicketResolved`, `TicketClosed`, `TicketReopened`.
* **Audit:** who/what/when; before/after for field edits; override reasons captured.
* **Telemetry:** feed load time, composer send latency, poll hit/miss counts.

---

## 15) Error States & Edge Cases

* Attachment upload failures (size/type/virus): inline error chips.
* Lost connectivity during compose: auto-save draft locally; warn on navigation.
* Permission downgrade mid-session: re-check on send; show denial toast and keep draft.
* Conflicting edits: surface last-writer-wins banner with diff preview for long text fields (notes).

---

## 16) Routing & URLs

* **Primary:** `dashboard.view.tech` → Tickets index → `tech.tickets.show`
* Cross-links from Inbox, RMM alert lists, Client/Site views, SLA widgets.
* **Deep links:** `#note-{id}` anchors; query flags for auto-open composer mode (e.g., `?compose=reply`).

---

## 17) QA Checklist (for implementation)

* Polling respects tenant setting; “New activity” banner appears without scroll jump.
* Resolve/Close modal blocks until validators pass or valid override.
* Time & cost account required behaviour matches settings/workflow.
* Attachments drag/paste works; image optimization applied.
* Right rail widgets update live (timer totals, KB refresh on title change).
* All actions write complete audit entries.

---

## 18) Notes for Reuse

* Feed, Composer, Timer, and ValidationModal are reusable patterns for Leads or future modules.
* Keep iconography consistent; avoid color-coding in spec (theme neutral).
* All labels must be template/localization-ready.
