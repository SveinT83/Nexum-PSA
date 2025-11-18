# Knowledge – Edit View (tech.knowledge.edit)

**Date:** 2025-10-16
**URL:** `tech.knowledge.edit` → `/tech/knowledge/{id}/edit`
**Access level (view):** `knowledge.edit`
**Action permissions:**

* Save changes: `knowledge.edit`
* Revert to draft: `knowledge.edit`
* Publish: `knowledge.edit`
* Archive: `knowledge.delete`
* Delete: `knowledge.delete`
  **Controller:** `App\\Http\\Controllers\\Tech\\Knowledge\\EditController`
  **Status:** Not completed
  **Difficulty:** Medium
  **Estimated time:** 4.5 hours

**Layout (Bootstrap):**
Top header / Main editor / Right slim rail (meta + actions)

---

## Purpose

Allow technicians and editors to update existing Knowledge articles safely while maintaining full versioning, validation, and audit tracking. The editor uses the same Markdown+ component as the create view, with added revision controls and publishing workflow options.

---

## Livewire components (mark as Livewire)

* **EditorCanvas** – same component as in create view, pre‑populated with current Markdown.
* **MetaPanel** – shows current metadata (category, tags, visibility, owner, review info). Editable with inline validation.
* **RevisionHistory** – new component: lists previous versions, with “View Diff” and “Restore” actions.
* **AttachmentTray** – identical to create view.
* **ActionBar** – contextual actions (Save, Publish, Archive, Restore version, etc.).

---

## Fields & Model binding

All fields from the create view apply. On load, populated from the selected article:

* Title
* Body_markdown
* Category (multi‑select)
* Tags (typeahead)
* Visibility (Internal/Client/Public)
* Client scope (if applicable)
* Owner (editable if permitted)
* Status (Draft/Published/Archived/Needs Review)
* Review interval + next_review_at

Additionally:

* Version number & last updated by
* Audit reference link (to audit log)

---

## Versioning & Revision controls (RevisionHistory)

* Show chronological list of versions with timestamp, editor, and change note.
* Actions:

  * **View Diff** → opens side‑by‑side Markdown diff.
  * **Restore** → creates a new draft version based on the selected version.
* Each save automatically increments version number and stores previous state.

---

## Header (Top)

* Breadcrumb: Knowledge → [Title]
* Title (editable inline)
* Actions: Save, Publish, Revert to Draft, Archive, Delete (dropdown)
* Status badge (Draft, Published, Archived, Needs Review)

---

## Main (EditorCanvas)

* Same Markdown editor and toolbar as create view.
* Pre‑loads article Markdown; autosaves on interval.
* Conflict check: warns if another user saved a newer version (with option to merge or reload).

---

## Right slim rail (MetaPanel)

**Tabs:** Meta | Revisions | Preview | Activity

**Meta**

* Category, Tags, Visibility, Client scope, Owner, Review interval, Status.

**Revisions**

* Lists past versions with diff/restore actions.

**Preview**

* Rendered HTML snapshot.

**Activity**

* Recent audit entries for this article (edit, publish, archive, restore).

---

## Actions (ActionBar)

* **Save changes** – Updates current version and retains status.
* **Publish** – Sets status Published; runs validation.
* **Revert to Draft** – Clones current version as Draft.
* **Archive** – Moves article to Archived state.
* **Delete** – Soft delete; confirm required.
* **Restore version** – Creates new draft from selected revision.

All actions trigger audit events and Livewire notifications.

---

## Validation rules (on Save/Publish)

* Title and Body required.
* At least one Category.
* Visibility valid; if Client-wide → Client scope required.
* Owner required.
* Attachments resolved.

---

## Keyboard shortcuts

* `Ctrl/Cmd + S` → Save changes
* `Ctrl/Cmd + Enter` → Publish
* `Ctrl/Cmd + Z` → Undo last edit (local)
* `Ctrl/Cmd + Shift + Z` → Redo
* `/` → Slash menu in Markdown

---

## Icons (no colors)

Lucide: `file-text`, `save`, `edit-3`, `archive`, `trash-2`, `git-branch`, `history`, `diff`, `rotate-ccw`, `check`, `alert-circle`.

---

## Notifications & UX feedback

* Toasts: Saved, Published, Archived, Deleted, Version Restored.
* Autosave state indicator.
* Diff viewer in modal (two‑column Markdown → HTML render comparison).
* Disabled publish button if validations fail.

---

## Error & edge cases

* Editing Archived article: opens read‑only with option “Revert to Draft”.
* Concurrent editing: lock + prompt to reload.
* Lost connection: queue autosave locally.
* Unauthorized: disable fields and show permission banner.

---

## Audit & Logging

* `knowledge.updated` (fields changed)
* `knowledge.published` (id, status)
* `knowledge.reverted_to_draft`
* `knowledge.archived`
* `knowledge.deleted`
* `knowledge.version.restored`

---

## QA Acceptance

* Editor preloads data correctly.
* Saving preserves Markdown fidelity.
* Version history appears and allows diff/restore.
* Publish transitions status and triggers audit.
* Archive and Delete update state accordingly.
* Validation and permission enforcement match create view.
