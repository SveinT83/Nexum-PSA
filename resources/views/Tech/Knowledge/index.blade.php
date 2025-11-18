@extends('layouts.default_tech')

@section('title', 'Knowledge')

@section('pageHeader')
    <h1>Knowledge</h1>
@endsection

@section('content')
Knowledge – Index List (tech.knowledge.index)

Date: 2025-10-16
URL: tech.knowledge.index → /tech/knowledge
Access level (view): knowledge.view.tech
Access level (actions):

Create: knowledge.create

Edit: knowledge.edit

Delete: knowledge.delete

Visibility/Publish (toggle): knowledge.edit

Bulk actions: require the union of permissions per selected items (deny if any row fails)
Status: Not completed
Difficulty: Medium
Estimated time: 4.5 hours

Layout frame (Bootstrap):

Top (header bar)

Main (content list & filters)

Right slim rail (narrow tools/preview/actions)

Controller (mirrors view path):
App\Http\Controllers\Tech\Knowledge\IndexController
Route name: tech.knowledge.index (GET)

Purpose

A technician-facing list of Knowledge documents with quick triage and maintenance actions. Supports fast search, rich filtering, bulk operations, inline visibility toggles, and a slim preview/editor rail. Static page chrome; dynamic content with live updates.

Livewire components (mark as Livewire)

FilterBar (Livewire) – views/livewire/tech/knowledge/FilterBar.php
Reusable across other list views (tickets/leads) with prop-based field config.

BulkToolbar (Livewire) – views/livewire/common/BulkToolbar.php
Generic bulk action bar (selection state, action menu, progress feedback).

KnowledgeTable (Livewire) – views/livewire/tech/knowledge/KnowledgeTable.php
Virtualized table/grid with sorting, row selection, inline badges, quick toggles.

PreviewPane (Livewire) – views/livewire/tech/knowledge/PreviewPane.php
Right-rail preview + minimal metadata editor.

StatsStrip (Livewire) – views/livewire/tech/knowledge/StatsStrip.php
Small status widgets (Draft/Published/Private/Needs Review).

Reuse: FilterBar, BulkToolbar, StatsStrip should be generic components.

Data columns & row anatomy (KnowledgeTable)

Title (click → opens /tech/knowledge/{id} read view; ctrl/cmd+click opens in new tab)

Visibility badge (Internal / Client-wide / Public / Role-based) – inline toggle (permission-gated)

Tags (compact chips; hover shows full list; click to filter)

Owner (user)

Linked tickets (count chip; click to filter by “has links”)

Updated (relative + tooltip with absolute)

Status badge (Draft / Published / Archived / Needs Review)

Actions (icon-only): Edit, Duplicate, Publish/Unpublish, Delete (soft), More (⋯)

Icons (suggested, no colors):

File-text (article), Eye/Eye-off (visibility), Tag, User, Link-2, Clock, Edit, Copy, Upload/Check (publish), Trash-2, More-horizontal

Filters (FilterBar)

Search (title, body excerpt, tags; debounced)

Visibility (multi-select: Internal, Client, Public, Role-based)

Status (Draft, Published, Archived, Needs Review)

Tags (multi-select, typeahead)

Owner (multi-select users)

Client scope (if visibility ≠ Internal/Public: choose client or “any”)

Updated range (date picker: preset chips Today/7d/30d/Custom)

Has links (toggle: linked tickets > 0)

Sort (Updated desc*, Title, Owner, Status)

Smart UX:

Persist last-used filters per user.

Show active filter pills with one-click remove.

Keyboard / focuses Search.

BulkToolbar (selection-aware)

Selected n items (counter)

Bulk actions (permission-aware):

Publish

Unpublish (set Draft)

Change visibility → Internal / Client-wide / Public / Role-based (opens mini-dialog for role mapping / client scope)

Add/Remove tags (multi)

Assign owner

Archive

Delete (soft delete); restore if viewing “Archived/Deleted”

Progress feedback: inline stepper (queued, processing, done; show failures per id)

Safety: destructive actions require confirm with summary and count.

Right Slim Rail (PreviewPane)

Preview tab: Title, excerpt, first content section (rendered markdown/plain), tags, visibility badges.

Meta tab: Quick edit—Visibility, Status, Tags, Owner, Client scope (permission-gated).

Linkage tab: Linked tickets list (open in new tab).

Activity tab: Recent edits (audit summary).

Behavior:

Opens when a row is selected (single).

Collapsible.

Live updates when item changes in table.

Header bar (Top)

Page title: “Knowledge”

Primary actions:

Create Article (button) → route tech.knowledge.create (requires knowledge.create)

Import (optional; CSV/Markdown zip—future-friendly stub)

Utility:

Saved Views dropdown (store filter/sort presets per user)

Refresh (manual trigger; auto-refresh is on by default)

Widgets (StatsStrip, at top of Main)

Draft (count)

Published (count)

Internal (count)

Public (count)

Needs Review (count)

Clicking a widget applies its corresponding filter.

Buttons & actions (table row, quick)

Edit – opens /tech/knowledge/{id}/edit (modal or page; page preferred)

Duplicate – clones to Draft, same visibility set to Internal by default

Publish/Unpublish – toggle status

Delete – soft delete; moves to Archived/Deleted scope

More (⋯) – Move visibility…, Assign owner…, Manage roles (if Role-based), Copy link

Permissions & visibility

View list: knowledge.view.tech

Inline actions:

Publish/Unpublish/Visibility/Metadata: knowledge.edit

Delete/Restore: knowledge.delete

Role-based visibility editor requires knowledge.edit and (if client-scoped) implicit access to selected client scope (policy).

Row-level policy: if user lacks edit/delete on a specific article (e.g., ownership rules), show actions disabled with tooltip.

Live behavior & performance

Auto-refresh: Live updates (new/edited articles appear without page reload).

Virtualized list: Efficient rendering for large sets.

Optimistic UI: For toggles (publish/visibility) with rollback on error.

Audit hooks: Every action writes a short audit entry (who/what/when; old→new for visibility/status).

Keyboard shortcuts:

/ focus search

p publish/unpublish (single selection)

e edit (single)

←/→ navigate rows (single)

esc deselect / close rail

Empty & edge states

No results: Friendly empty state with “Clear filters” + “Create Article” (if allowed).

No permission for actions: Show why (tooltip) and hide bulk actions entirely if none are permitted.

Failed loads: Inline retry and minimal error text.

Notifications (PWA-friendly)

Toasts for successful bulk ops and quick toggles.

Badge increment when new articles are published (optional).

Reusable elements (suggested components)

<x-list/filter-bar> – used across Tickets/Leads/Knowledge

<x-list/bulk-toolbar> – shared bulk actions framework

<x-list/stats-strip> – mini KPI pods

<x/list/table> – base table with selection & sort hooks

<x/form/visibility-picker> – Internal/Client/Public/Role-based + client/role selectors

Telemetry & QA

Capture metrics: search usage, filter adoption, publish latency, failure rates per bulk op.

Add QA ids on interactive elements (data-qa="kb-publish-toggle", etc.).

Icons (no colors)

Use Lucide (or equivalent): file-text, eye, eye-off, tag, user, link-2, clock, edit, copy, upload, check, trash-2, more-horizontal, search, filter, refresh-ccw.

Routes (summary)

GET /tech/knowledge → list (this page)

GET /tech/knowledge/create → requires knowledge.create

GET /tech/knowledge/{id} → view

GET /tech/knowledge/{id}/edit → requires knowledge.edit

AJAX/Livewire actions: publish/unpublish, visibility change, tag assign, owner assign, delete/restore, duplicate.

Audit events (examples)

knowledge.publish / knowledge.unpublish

knowledge.visibility_changed (old→new)

knowledge.tags_updated (added[], removed[])

knowledge.owner_assigned (old→new)

knowledge.deleted / knowledge.restored

knowledge.duplicated (source_id → new_id)

Security & policies

Enforce per-row policy checks server-side (do not rely on UI state).

Soft delete with retention; restore path visible to admins.

All changes recorded in audit trail with actor and timestamp.

Nice-to-haves (smart UX)

Inline quick edit of Title (Draft only).

Broken links checker batch action (report to right rail).

“Promoted from ticket” badge with link back.
@endsection

@section('sidebar')
    <h3>Left Sidebar</h3>
    <ul>
        <li><a href="#">System Status</a></li>
        <li><a href="#">Task Management</a></li>
        <li><a href="#">Reports</a></li>
    </ul>
@endsection

@section('rightbar')
    <h3>Right Sidebar</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection