# Knowledge – Show View (tech.knowledge.show)

**Date:** 2025-10-16
**URL:** `tech.knowledge.show` → `/tech/knowledge/{id}`
**Access level (view):** `knowledge.view.tech` (minimum)
**Additional access:**

* Edit button visible for users with `knowledge.edit`
* Archive/Delete actions require `knowledge.delete`
  **Controller:** `App\\Http\\Controllers\\Tech\\Knowledge\\ShowController`
  **Status:** Not completed
  **Difficulty:** Low
  **Estimated time:** 3.0 hours

**Layout (Bootstrap):**
Top header / Main content / Right rail (metadata & related info)

---

## Purpose

Provide a clean reading experience for Knowledge articles with context information, audit visibility, and related items. This is the primary page for technicians to view published or draft content and for customers (when visibility allows) to read accessible articles.

---

## Structure

* **Header** – Title, visibility/status chips, action buttons (Edit, Archive, Delete)
* **Main content** – Rendered HTML (from Markdown), attachments list, in‑article anchors
* **Right rail** – Metadata, related tickets/articles, revision info

---

## Livewire components (mark as Livewire)

* **ArticleViewer** – renders HTML from Markdown, handles heading anchors, copy links, and collapsible sections.
* **MetaSidebar** – displays category, tags, visibility, client scope, owner, review info.
* **RelatedContent** – lists linked tickets and related KB articles (shared tags/categories).
* **RevisionInfo** – shows last updated, version number, and audit snippet.
* **ActionButtons** – contextual buttons (Edit, Archive, Delete, Back to list).

---

## Data displayed

* **Title** (H1)
* **Excerpt** (optional)
* **Body_html** (server-rendered Markdown)
* **Attachments** (with download/open links)
* **Categories & Tags** (chips; clickable to filter or search)
* **Visibility** (Internal / Client‑wide / Public)
* **Owner** (linked user)
* **Last updated** (relative + tooltip absolute)
* **Version number** (if >1)
* **Review date** (if scheduled)

---

## Header section

* **Breadcrumbs:** Knowledge → [Category] → [Title]
* **Title:** editable inline if user has `knowledge.edit`
* **Visibility badge:** Internal | Client | Public (with icon)
* **Status badge:** Draft / Published / Archived / Needs Review
* **Action buttons:**

  * **Edit** (→ `tech.knowledge.edit`)
  * **Archive** (confirm modal)
  * **Delete** (soft delete with confirmation)
  * **Back to list** (breadcrumb link)

---

## Main content (ArticleViewer)

* Rendered from Markdown → sanitized HTML.
* Auto‑generated Table of Contents (TOC) based on headings (sticky on scroll).
* Code blocks with syntax highlighting.
* Collapsible callouts / alerts (styled boxes).
* Anchored links for each heading.
* Attachment list below content (file name, size, download).
* Optional related image gallery.

---

## Right rail (MetaSidebar)

**Tabs:** Details | Related | Activity

**Details tab:**

* Owner (with avatar)
* Category
* Tags
* Visibility
* Client scope
* Review interval + next review date

**Related tab:**

* Related KB (shared tags/categories; click → open in new tab)
* Related tickets (linked manually or via integration)

**Activity tab:**

* Version info (v#)
* Last updated timestamp
* Created by / Created at
* Recent audit entries (compact)

---

## Actions (ActionButtons)

* **Edit** – opens edit view.
* **Archive** – confirmation modal, sets status=Archived.
* **Delete** – soft delete with undo option (toast for 10s).
* **Back** – returns to index view.

All actions emit toasts and trigger Livewire refresh events for lists.

---

## Search & Navigation helpers

* Back to list via breadcrumb.
* Tag click → applies tag filter to index.
* Category click → opens filtered index by category.
* Keyboard shortcut: `Esc` → back to list.

---

## Icons (no colors)

Lucide: `book-open`, `edit`, `archive`, `trash-2`, `eye`, `tag`, `folder-tree`, `clock`, `user`, `link-2`, `chevron-left`, `file-text`.

---

## Error & edge cases

* Article not found → 404 with “Return to Knowledge Base”.
* Permission denied → show read‑only mode.
* Archived article → banner “Archived; may be outdated”.
* Needs Review → banner with link to Review Queue.

---

## Audit & Logging

* View events logged (`knowledge.viewed`)
* Edit, Archive, Delete trigger corresponding audit entries.
* Audit panel shows last 5 changes with timestamp and actor.

---

## QA Acceptance

* Article displays correctly with Markdown rendering and attachments.
* Metadata (category, tags, owner) visible and accurate.
* Visibility chips match article settings.
* Edit and Archive buttons respect permissions.
* Related content lists populate dynamically.
* Audit and version info visible in sidebar.
* Archived/Needs Review banners appear correctly.
