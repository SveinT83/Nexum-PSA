# Livewire.tech.documentations.Templates.Form — Shared Metadata Form (Create/Edit)

**URL / usage context:**

* Used by: `tech.documentations.templates.create`
* Used by: `tech.documentations.templates.edit:{templateId}`

**Access & permissions:**

* Require: `documentations.admin`

**Creation date:** 2025-11-03

**Controller / path:**

* Livewire: `App\Livewire\Tech\Documentations\Templates\Form`
* Views using it:

  * Create: `resources/views/tech/documentations/templates/create.blade.php`
  * Edit: `resources/views/tech/documentations/templates/edit.blade.php`

**Status:** In progress

**Difficulty:** Medium

**Estimated time:** 2.5 hours

---

## Purpose

A single Livewire form that manages **template metadata only** (not fields). It powers both Create and Edit pages. The actual **field builder lives in Edit view** (page-level), not in this component.

---

## Scope & Responsibilities

**This component DOES:**

* Render and validate metadata inputs.
* Create new Template records.
* Update existing Template metadata.
* Show a contextual info/flash message after first save (coming from Create):

  * *“Template saved — you can now define fields.”*
* Expose a **Build fields** action button (enabled only when the template exists) that links to `tech.documentations.templates.edit:{id}`.
* In Edit context, display a right-side panel with read-only facts (audit summary, usage counters).

**This component DOES NOT:**

* Manage template fields. (Handled in `edit.blade.php` page’s field builder.)
* Clone, import/export, or version templates. (Future extensions.)

---

## Design & Layout (Bootstrap)

**Regions**

* **Top section (page frame):** title and breadcrumbs come from the hosting Blade views.
* **Main section (card):** the metadata form.
* **Right-side panel:**

  * Create: present but intentionally empty (layout parity).
  * Edit: populated with metadata facts (see below).

**Buttons (primary actions):**

* **Save** (inside form footer)
* **Build fields** (toolbar + sticky footer)

  * State rules:

    * New (unsaved): **disabled**, tooltip “Save template first”.
    * After first save / existing: **enabled** → navigate to edit route.

**Icons (suggested lucide):**

* Save → `save`
* Build fields → `boxes`
* Status badges → `check-circle` (Published), `pencil` (Draft), `archive` (Archived)
* Audit → `history`

---

## Form Fields & Defaults

* **Name** *(required)*
* **Description** *(optional, multiline)*
* **Category** *(optional)* — preselect when `?category_id=` is present; user may change.
* **Visibility / scope** *(enum)*: `internal_only` *(default)* | `client_read` | `client_form` | `hidden_system`
* **Status** *(enum)*: `draft` *(default)* | `published` | `archived`
* **Active** *(boolean)*: default **true**

Validation:

* Name: required, 3–120 chars, unique per tenant (case-insensitive) while not counting self on edit.
* Status vs Active: allow any combo (kept for future rules).

---

## Component API (props & events)

**Props** (from host view):

* `templateId` *(optional)* — when present, component loads existing record; when absent, component enters create mode.
* `category_id` *(optional, via query)* — used to preselect Category on first render; ignored on subsequent edits if user changes it.

**Emitted events**

* `template.saved` (payload: `{ id }`) — fired on successful create/update.

**URL behavior**

* After save, remain on current page. Host view displays success toast; the **Build fields** button becomes enabled.

---

## Right-Side Panel (Edit only)

Show compact, read-only facts:

* Status badge + Active toggle state (read-only mirror)
* Last edited: timestamp + user
* Created: timestamp + user
* Usage: number of documents using this template (if available)
* Quick link: **Audit log** (opens drawer in Edit page)

---

## Livewire Internals

**State**

* `$isEdit` (bool), `$template` (model), `$form = [name, description, category_id, visibility, status, active]`

**Lifecycle**

* `mount($templateId = null, $category_id = null)`

  * Gate: `documentations.admin` (abort 403 if missing)
  * If `$templateId`: load model → hydrate `$form`
  * Else: init defaults; if `$category_id` present, set preselect
* `save()`

  * Validate → create/update model → emit `template.saved` and flash success → enable Build fields button

**Authorization**

* All mutations/checks enforce `documentations.admin`.

**Telemetry & audit (hooks)**

* On save: write audit entry *“template.metadata.updated”* with diff (old/new) where feasible.

---

## Hosting Views Contract

* **Create view** must:

  * Provide breadcrumbs and page title “New Template”
  * Render this component without `templateId`
  * Show **empty** right-side panel
  * Show toast after first save (the component also emits event)
* **Edit view** must:

  * Provide header with template name + status chips
  * Render this component with `templateId`
  * Provide an audit drawer and the separate **Field Builder** section below

---

## Reusable components / widgets

* Breadcrumbs (standard)
* Page header (standard)
* Right-column container (tech layout)
* Toast/alert utility
* Audit drawer trigger (Edit page)

---

## Edge Cases & UX Rules

* If user changes Category after preselect, persist new choice (no lock).
* If a concurrent update happens, surface conflict message and reload fresh model.
* If Status = Archived, allow metadata edits but keep **Build fields** enabled (admins may still inspect/edit structure in the builder as policy allows later).

---

## Non-functional

* Predictable: <150 ms validate/save path (excluding network/DB) with debounced input.
* Traceable: all saves tagged with actor and request-id for logs.
* Real-time ready: broadcast `template.saved` for other tabs to refresh (future).

---

## Test Checklist (Dev)

* Create with `?category_id=` preselect → Save → Button enabled → No redirect.
* Edit existing → metadata changes persist; right panel shows audit facts.
* Permissions enforced (403 without `documentations.admin`).
* Name uniqueness rule excludes current record.
* Build fields button disabled before first save; tooltip visible.
