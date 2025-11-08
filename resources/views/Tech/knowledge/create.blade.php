# Knowledge – Create View (tech.knowledge.create)

**Date:** 2025-10-16
**URL:** `tech.knowledge.create` → `/tech/knowledge/create`
**Access level (view):** `knowledge.create`
**Action permissions:**

* Save Draft: `knowledge.create`
* Publish: `knowledge.edit`
* Visibility change (Internal/Client-wide/Public): `knowledge.edit`
* Delete Draft: `knowledge.delete`
  **Controller:** `App\\Http\\Controllers\\Tech\\Knowledge\\CreateController`
  **Status:** Not completed
  **Difficulty:** Medium
  **Estimated time:** 4.5 hours

**Layout (Bootstrap):**
Top header / Main editor canvas / Right slim rail (metadata & actions)

---

## Purpose

Provide a focused authoring surface to create a Knowledge article using **Markdown-only** with guardrails for metadata, visibility, and review policy. Optimized for speed (keyboard-first) and safe publishing (validation + audit).

---

## Livewire components (mark as Livewire)

* **EditorCanvas** *(Livewire)* – `views/livewire/tech/knowledge/EditorCanvas.php`
  Markdown textarea with toolbar, drag‑drop image upload, link helpers, autosave.
* **MetaPanel** *(Livewire)* – `views/livewire/tech/knowledge/MetaPanel.php`
  Right rail: Title, Category, Tags, Visibility, Client scope, Owner, Status, Review interval.
* **AttachmentTray** *(Livewire)* – `views/livewire/tech/knowledge/AttachmentTray.php`
  Manages images/files, insert-at-cursor, rename, delete.
* **PreviewPane** *(Livewire)* – `views/livewire/tech/knowledge/PreviewPane.php`
  Rendered HTML preview (server-rendered from Markdown), diff toggle vs last autosave.
* **ActionBar** *(Livewire)* – `views/livewire/tech/knowledge/ActionBar.php`
  Save Draft, Publish, Discard, Validate, and progress/toast feedback.

> Reuse: PreviewPane naming aligns with the index spec; keep behavior consistent.

---

## Fields & Model mapping

* **Title** *(required)* → `article.title`
* **Body (Markdown)** *(required)* → `article.body_markdown`
  (Server caches `article.body_html`.)
* **Category** *(required; single-level)* → `article.categories[]` (1..n)
* **Tags** *(optional; free text; dedup)* → `article.tags[]`
* **Visibility** *(required)* → `article.visibility` ∈ {Internal (default), Client-wide, Public (if enabled)}
* **Client scope** *(required if Client-wide)* → `article.client_scope`
* **Owner** *(default=current user; editable if permissioned)* → `article.owner_id`
* **Status** *(computed)* → Draft|Published (set by action)
* **Review interval** *(default from settings)* → `article.review_policy.review_interval_days`

---

## Validation rules (before Publish)

* Title present (min length 3)
* Body_markdown present (min content length)
* ≥ 1 Category selected
* Visibility set; if Client-wide → Client scope must be set
* Owner set
* Attachment references must resolve (no broken image/file ids)

**Save Draft** allows incomplete metadata but runs soft validation and flags missing items in MetaPanel.

---

## Header (Top)

* **Back to list** (breadcrumb) → `/tech/knowledge`
* **Page title:** “New Article”
* **Actions (primary):** Save Draft, Publish (dropdown: Publish now / Publish & schedule review), Discard Draft (confirm)
* **Utilities:** Preview toggle, Validate, Shortcuts helper

---

## Main (EditorCanvas)

* Markdown toolbar: H1–H3, bold/italic, code block, link, list, table, callout, checkbox list
* Drag‑drop image/file to upload (auto insert reference at cursor)
* Slash menu: `/table`, `/callout`, `/hint`, `/code`
* Inline link helper: search existing articles to deep-link
* Autosave: every 5s of inactivity and on blur; show status chip (Saved • Saving • Error)
* Conflict detection: if remote version changed, offer merge/diff

---

## Right slim rail (MetaPanel)

**Tabs:** Meta | Attachments | Preview | Activity

**Meta**

* Title (mirrors header)
* Category (multi-select, single level)
* Tags (typeahead, create-on-enter)
* Visibility selector (Internal • Client-wide • Public*)
* Client scope (visible when Client-wide)
* Owner (default self)
* Review interval (days); next review date calculated
* Status chip (Draft/Published)

**Attachments**

* List uploaded assets; insert, rename, delete, copy link

**Preview**

* Server-rendered HTML snapshot (updates on debounce)

**Activity**

* Autosave checkpoints, created_by, timestamps (audit snippets)

* Public only appears if enabled by settings.

---

## Actions (ActionBar)

* **Save Draft**

  * Creates/updates Draft; remains on page
* **Publish**

  * Runs full validation → sets status Published → writes audit → redirects to `tech.knowledge.show`
* **Publish & schedule review**

  * Same as Publish + sets `next_review_at` = today + interval
* **Validate**

  * Runs validations, highlights missing fields in MetaPanel
* **Discard Draft**

  * Soft delete (or hard delete if empty), confirm dialog

All mutations emit toasts and Livewire events; failures show inline error summaries.

---

## Keyboard shortcuts

* `Ctrl/Cmd + S` → Save Draft
* `Ctrl/Cmd + Enter` → Validate then Publish (if valid)
* `/` in editor → Slash menu
* `Ctrl/Cmd + K` → Insert link dialog
* `Esc` → Close dialogs/menus

---

## Icons (no colors)

Lucide: `file-text`, `save`, `upload`, `link-2`, `eye`, `eye-off`, `user`, `tag`, `folder-tree`, `clock`, `history`, `alert-circle`, `check`.

---

## Notifications & UX feedback

* Toasts on Save/Publish/Discard
* Inline badges for Save state (Saved/Saving/Error)
* Validation callouts in MetaPanel
* PWA badge optional on successful Publish

---

## Error & edge cases

* Upload failure: show retry; keep placeholder until resolved
* Broken references: validator lists missing assets/anchors
* Lost connection: switch to offline mode; queue autosaves locally; prompt to retry sync
* Permission change mid-edit: disable Publish with tooltip

---

## Audit & Logging

* `knowledge.draft.saved` (id, fields changed)
* `knowledge.published` (id, visibility, categories, tags)
* `knowledge.discarded` (id)
* `knowledge.metadata.changed` (diff)

---

## Settings hooks consumed

* Default visibility (Internal)
* Default review interval (e.g., 180 days)
* Public exposure toggle (hides Public option when off)

---

## Routes & events (non-code outline)

* GET `/tech/knowledge/create` → view
* POST action: Save Draft (Livewire action)
* POST action: Publish (Livewire action)
* Upload endpoint: images/files (returns asset id + URL)
* Events emitted: `kb:articleSaved`, `kb:articlePublished`, `kb:assetsChanged`

---

## QA Acceptance

* Draft can be saved with minimal fields (Title+Body)
* Publish blocked until all validations pass
* Visibility rules enforced; Client scope required when Client-wide
* Images upload and insert at cursor; references resolve
* Autosave works; recover from refresh without data loss
* Audit entries recorded for Save/Publish/Discard
