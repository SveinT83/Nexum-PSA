# tech.documentations.form — Shared Form Partial

**File:** `resources/views/tech/documentations/_form.blade.php`
**Used by:** `tech.documentations.create`, `tech.documentations.edit:{docId}`
**Access:** View: `documents.view.tech`; Submit (create/edit): `documents.manage` (or `documents.edit` when editing)
**Creation date:** 2025-11-03
**Controller / Path:**

* Create route controller: `App\Http\Controllers\Tech\Documentations\CreateController@create`
* Edit route controller: `App\Http\Controllers\Tech\Documentations\EditController@edit`
* Partial include path: `tech/documentations/_form`

**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 5.0 hours (10×30 min)

---

## Purpose

Single source of truth for **creating and editing documents**. The form enforces required fields (no drafts) and supports **scope** (Internal/Client/Site), **category selection**, **template binding (copy-on-create)**, and **predictable validation**.

---

## Data Contract (expects)

* `mode` (`create` | `edit`) — controls labels, actions, and required template behavior.
* `document` (nullable on create) — Eloquent model for edit mode.
* `categories` — list for selector (scoped to current scope).
* `templates` — list filtered by selected category & scope.
* `scope` — `internal` | `client` | `site` (defaults to `internal`).

---

## Layout (Bootstrap regions)

**Top section**

* Heading: `Create Document` / `Edit Document` (based on `mode`).
* Subtext with current **Scope** and selected **Category**.

**Main section**

* **Field group: Core**

  * `Title` (required)
  * `Category` (required)
  * `Scope` selector (read-only if enforced by parent view)
  * `Template` (required on create if category has default template; optional otherwise)
  * `Description` (optional, short summary)
* **Field group: Template data**

  * When a `Template` is selected (mode=create): render template-driven fields.
  * On save, **copy template structure and store with data** (decoupled from future template changes).
* **Field group: Metadata**

  * `Tags` (free text / chips)
  * `Owner` (user select; defaults to current user)
  * `Visibility flags` (e.g., “Technician-only notes”)
* **Attachments widget** (optional)

**Right-side panel (narrow)**

* **Category info** (name, count, last updated)
* **Template preview** (structure summary)
* **Validation status** (live list of missing required fields)

---

## Behaviors & Rules

* **Required fields enforced** before submit; no draft state.
* **Template copy-on-create:**

  * On create, the selected template’s schema is **duplicated into the document** and populated with form data.
  * On edit, the stored schema is used; changing the template is not allowed (to avoid corruption). Optionally expose a `Replace template` action via migration tool in future.
* **Category-default template:** If a category defines a default template and this is the only available, preselect it and lock the dropdown.
* **Scope-aware lists:** Category and template dropdowns reflect current `scope`.
* **Autosave warning:** Prompt on navigate-away if there are unsaved changes.
* **Keyboard:** `Ctrl/Cmd+S` submits; `Esc` cancels (confirm modal if dirty).

---

## Actions (Buttons)

* **Primary:** `Save` (mode-aware label: `Create` / `Save changes`)
* **Secondary:** `Cancel` → back to index or previous
* **Edit mode only:**

  * `Duplicate` → creates a new doc from current document’s stored schema + data
  * `Delete` (if permission `documents.delete` and no hard constraints)
  * `Preview` (read-only view in new tab: `tech.documentations.show:{docId}`)

---

## Validation (server-side; mirror client-side)

* Title: required, max length (config-driven)
* Category: required, must exist and be accessible in current scope
* Template: required if category enforces default or `mode=create` with template-bound category
* Template fields: honors field-level rules (required, regex, min/max, dependencies)

---

## Reusable Components / Widgets

* `components.form.text-input` (Title)
* `components.form.select` (Category, Template, Owner)
* `components.form.scope-badge` (read-only indicator)
* `components.form.tags`
* `components.form.attachments`
* `components.alert.inline-errors`
* `components.panel.preview`

---

## Events & Logging

* Log create/update/delete with actor, scope, category, template id, and diff summary.
* Emit event `document.saved` for real-time UI updates.

---

## Permissions & Guards

* View: `documents.view.tech`
* Create: `documents.manage`
* Edit/Delete: `documents.edit`, `documents.delete`
* Scope guard: user must have access to selected client/site when scope != internal.

---

## Notes

* Parent views (`create`/`edit`) are responsible for populating `categories`, `templates`, and `scope`.
* Keep rendering fast; defer heavy template preview into the right panel.
* Future: convert to Livewire if reactive field dependencies become extensive.
