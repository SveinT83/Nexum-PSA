# tech.documentations.docs* — General View Specification

**Creation date:** 2025-10-30
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 4.5 hours (create 1.5h, show 1.5h, edit 1.5h)

---

## 1. Purpose

`tech.documentations.docs*` covers the **single-document views** inside the documentation module. It complements the category-level pages by handling creation, display and administrative editing of **one** documentation record that was created from a category template.

This spec is meant to be reused when we later write per-view docs for:

* `resources/views/tech/documentations/docs/create.blade.php`
* `resources/views/tech/documentations/docs/show.blade.php`
* `resources/views/tech/documentations/docs/edit.blade.php`

Goals:

* Keep document creation predictable and scope-aware (internal / client / site).
* Snapshot the template at creation time so later template changes do **not** affect existing docs.
* Separate **content editing** (inline, fast) from **structural/meta editing** (access, scope, visibility, bindings).
* Maintain a dedicated `/edit` route for navigation, policy checks and audit.

---

## 2. URLs & Access

### URLs

* `tech.documentations.docs.create`
* `tech.documentations.docs.show:{docId}`
* `tech.documentations.docs.edit:{docId}`

### Access & permissions

* Read document (internal/client/site):

  * `documents.view.internal` **or**
  * `documents.view.client` **or**
  * `documents.view.site`
* Create document: `documents.manage.site` (technicians & internal staff will typically have this)
* Edit document (meta/scope/visibility): `documents.manage.site` or higher (`documents.admin`)
* Inline edit on show: same as edit, but can be restricted in policy to document owner / technicians.

### Controller paths

* Create: `App\\Http\\Controllers\\Tech\\Documentations\\Docs\\CreateController@create`
* Show: `App\\Http\\Controllers\\Tech\\Documentations\\Docs\\ShowController@show`
* Edit: `App\\Http\\Controllers\\Tech\\Documentations\\Docs\\EditController@edit`

Matches Laravel view folder:

* `resources/views/tech/documentations/docs/create.blade.php`
* `resources/views/tech/documentations/docs/show.blade.php`
* `resources/views/tech/documentations/docs/edit.blade.php`

---

## 3. Scope behaviour (very important)

* Scope is decided **outside** the doc views, in the top scope selector on `tech.documentations.index`.
* **No client selected** → scope = **internal** → new docs created here become internal docs.
* **Client selected** → scope = **client** → user must have picked a client; otherwise, doc-create should block and tell user to select client first.
* **Client + site selected** → scope = **site** → doc will be bound to that site.
* A doc **cannot** be in “client scope” without a selected client. If no client is selected, we are by definition in internal scope.

---

## 4. Template snapshot model

* On **create**, the user selects a **template** that was previously built using the Livewire Template Builder (`tech.documentations.templates.edit`).
* The system snapshots the template’s **compiled JSON layout** (`template_schema_json`) into the document record (`template_snapshot_json`).
* Field values are stored separately (`data_json`).
* If the category/template is later updated in the admin/template views, **existing docs are NOT touched**. Only newly created docs in that category get the updated template.
* Templates originate from Livewire-based builder files. Always use the template’s **exported JSON schema** when embedding snapshots — never the raw Livewire component.

---

## 5. Category is locked after create

* When a document is created, the chosen **category** is stored (e.g. `category_key`).
* Category is **not** editable later from `docs.edit`.
* Templates are always bound to their category through the Template Builder. Therefore, when a document is created, its category and template are locked together as part of the snapshot.
* If user needs the doc under another category → they must create a new doc in the correct category and optionally delete/archive the old one.
* `docs.edit` must display the category as **read-only** (label + icon).

---

## 6. Layout (Bootstrap pattern)

All three views follow the same 3-part layout used in tdPSA tech views:

1. **Top section (static)**

   * Left: Page title (New documentation / Documentation / Edit documentation)
   * Center: Scope indicator (Internal / Client: {client} / Site: {site})
   * Right (narrow): Action buttons (Save, Cancel, Open in view, Audit)

2. **Main section**

   * Primary content: snapshot-rendered fields (for show), or the create form (for create), or meta form (for edit)
   * All forms should stay within standard PSA form components

3. **Right-side panel (narrow)**

   * Recent docs (same category, same scope)
   * Audit / history (last changes)
   * Global suggestions when in client scope (reuse from index-level docs)

Icons: use lucide (file-text, folder-cog, network, wifi, building, server-stack) based on category.

---

## 7. View: `tech.documentations.docs.create`

**URL:** `tech.documentations.docs.create`
**Access:** `documents.manage.site`
**Controller:** `...Docs\\CreateController@create`
**Status:** Not started
**Difficulty:** Low–Medium
**Estimated time:** 1.5 hours

**Purpose:** Create a new documentation record under the **current scope** and in a **chosen category**, and embed the current template as a snapshot.

**Required fields:**

* Title (text)
* Category (dropdown, required) — filtered to categories available in current scope
* Template (dropdown) — lists all templates defined in the Livewire Template Builder for the selected category; each entry references its compiled schema version
* Scope (read-only here; comes from index selector)
* If scope=client: Client must already be selected → otherwise show blocking message
* If scope=site: Show both client and site selected
* Optional: “Hide from client”

**Actions:**

* Save & open (redirect to show)
* Save & new
* Cancel

On save:

1. Create doc
2. Snapshot selected template’s compiled schema into `template_snapshot_json`
3. Save form values into `data_json`
4. Redirect to `tech.documentations.docs.show:{id}`

---

## 8. View: `tech.documentations.docs.show:{docId}`

**URL:** `tech.documentations.docs.show:{docId}`
**Access:** `documents.view.internal|client|site` depending on scope
**Controller:** `...Docs\\ShowController@show`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 1.5 hours

**Purpose:** Fast reading of documentation + inline editing of template-based fields.

**Main features:**

* Render fields in the order defined in `template_snapshot_json`
* Inline edit per section/fieldset (if user has edit rights)
* Sticky footer when there are unsaved changes: Save / Cancel
* Read-only display of: Category (locked), Scope, Client/Site, Visibility
* Button/link to: “Edit meta” → `tech.documentations.docs.edit:{docId}`

**Right panel:**

* Related / recent docs (same category + scope)
* Audit (compact list of last N changes)

**Notes:**

* Inline edit should **not** allow changing category
* Inline edit should **not** allow switching scope
* Inline edit should focus on the content that was snapshotted at creation

---

## 9. View: `tech.documentations.docs.edit:{docId}`

**URL:** `tech.documentations.docs.edit:{docId}`
**Access:** `documents.manage.site` or `documents.admin`
**Controller:** `...Docs\\EditController@edit`
**Status:** Not started
**Difficulty:** Medium
**Estimated time:** 1.5 hours

**Purpose:** Administrative editing of the document that cannot (or should not) be done inline in show.

**Editable fields here:**

* Title
* Scope (if user has privilege): Internal → Client → Site (tighten rules for Internal)
* Client (when scope = client or site)
* Site (when scope = site)
* Visibility: Hide from client (checkbox)
* Administrative status (active/archived) — optional, but reserve slot

**Read-only fields here:**

* Category name + icon (with note: "Category is locked after creation")
* Created at / last updated by
* Template source (name/version) — for debugging

**Actions:**

* Save
* Cancel / Back to document
* View history/audit

**Why edit exists even with inline-edit:**

* We need a stable route for navigation from lists and audit logs
* We separate content from meta so policies stay simple
* We match the rest of the tdPSA UI (tickets, sales, storage do the same)

---

## 10. Reusable components & naming

* `doc-scope-selector` — shared with index view
* `doc-template-form` — used in create + rendered version in show
* `doc-meta-form` — used in edit
* `doc-audit-list` — shared list for right panel
* `doc-related-list` — shared list for right panel

Mark these clearly in code/docs so GitHub Copilot can reuse them.

---

## 11. Notes for developers

* Do **not** hardcode categories in the views; pull from DB.
* Always embed the Livewire Template Builder’s compiled schema on create; never reference live template components.
* Do not allow category changes in edit; show read-only instead.
* All writes must go through audit logging (what, who, when, old → new).
* Keep views Bootstrap-based (top, main, right panel) and avoid color definitions.
* Keep latency low: show-view should be able to render from snapshot only (no extra queries for template definitions).

---

## 12. Status summary

* `docs.create` → Not started
* `docs.show` → In progress (inline edit behaviour defined)
* `docs.edit` → Not started (fields & read-only rules defined)

When the individual view docs are wanted, reuse this spec as header and just add field lists + actions.
