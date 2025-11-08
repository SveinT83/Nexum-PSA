# tech.documentations.docs.show — View Specification

**URL:** `tech.documentations.docs.show:{docId}`
**Access & permissions:** view depends on scope → `documents.view.internal` / `documents.view.client` / `documents.view.site`
**Creation date:** 2025-11-03
**Controller path:** `App\Http\Controllers\Tech\Documentations\Docs\ShowController@show`
**View path:** `resources/views/tech/documentations/docs/show.blade.php`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 1.5 hours

---

## Purpose

Fast reading and inline editing of a **single documentation record** using the **snapshotted template schema**. Category is **read-only**. Page provides a dedicated **Edit** entry point for metadata/bindings (`docs.edit`).

---

## Key Rules & Behaviors

* **Snapshot-driven render:** fields and sections come from `template_snapshot_json`; values from `data_json`.
* **Category locked:** show as read-only label+icon; never editable here.
* **Inline edit (content only):** allowed when policy grants edit on this document (e.g., `documents.manage.site`). Inline edit changes `data_json` only.
* **Meta editing via Edit page:** a visible **Edit** button that links to `tech.documentations.docs.edit:{docId}` appears **only** for users with `documents.admin`.
* **Scope display:** show current scope chip (Internal / Client:{client} / Site:{site}).
* **Audit:** right panel shows compact history; full audit link optional.

---

## Layout (Bootstrap)

**Top section**

* Title = document title
* Badges: Category (locked), Scope chip
* Actions:

  * **Edit** (primary) → only visible with `documents.admin`; routes to `docs.edit:{docId}`
  * **Copy link** (secondary)
  * **Print / Export** (optional placeholder)

**Main section**

* **Content panel** (snapshot renderer)

  * Sections/fieldsets in snapshot order
  * Fields: text, select, list, masked secrets, dependent fields
  * Inline edit toggles (if policy allows)
  * Sticky save bar appears when there are unsaved changes: **Save**, **Cancel**
* **Activity panel**

  * Last updated at/by
  * Recent changes (diff-lite for critical fields)

**Right-side panel** (narrow)

* Audit (compact list)
* Related docs (same category + scope)
* Metadata (read-only): Template name/version, Created at, ID/slug

Icons (lucide): `file-text`, `lock`, `pencil-line`, `history`, `link`, `printer`.

---

## Components & Widgets

* `doc-content-renderer` (driven by `template_snapshot_json`)
* `doc-inline-editor` (policied; updates `data_json`)
* `doc-scope-chip`
* `doc-audit-list` (compact)
* `doc-related-list`
* `sticky-save-bar`

---

## Buttons & Actions

* **Edit** (visible only with `documents.admin`) → open `tech.documentations.docs.edit:{docId}`
* **Save** (inline content) → persist `data_json`
* **Cancel** (inline content) → discard unsaved changes
* **Copy link** → copies canonical route
* **Print / Export** (optional future)

Disabled/visibility rules:

* Edit button hidden when user lacks `documents.admin`.
* Inline Save/Cancel only visible in edit mode and when user has edit policy.

---

## Validation & Errors (inline)

* Field-level validation from snapshot schema (required, min/max, regex)
* Save blocked until all required fields valid
* Show inline errors under fields + toast summary

---

## Data & Persistence

* Read:

  * `template_snapshot_json` (schema)
  * `data_json` (values)
  * `category_key`, `scope_type`, `client_id`, `site_id`
* Inline Save:

  1. Validate fields per snapshot
  2. Persist `data_json` changes
  3. Append audit entry (content update)

---

## Policies & Permissions

* View: `documents.view.*` by scope
* Inline edit (content): policy check (e.g., `documents.manage.site` + scope visibility)
* Edit button visibility: **requires `documents.admin`**
* Audit entries readable to same audience as document (or stricter by policy)

---

## Test Scenarios (happy path)

1. Viewer without edit rights: sees content, category chip, scope; **no Edit button** (no `documents.admin`).
2. Editor with manage rights (but not admin): can inline edit and save content; **no Edit button**.
3. Admin with `documents.admin`: sees **Edit** button; clicking opens `docs.edit`; returning shows updated metadata; content unchanged unless inline saved.
