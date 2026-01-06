# tech.documentations.templates.create — View Specification

**URL:** `tech.documentations.templates.create`
**Access / Permissions:** `documents.view.internal` (module) + `documents.templates.create` (action)
**Creation date:** 2025-11-02
**Controller / path:** `App\Http\Controllers\Tech\Documentations\Templates\CreateController`
**Status:** Not started
**Difficulty:** Medium
**Estimated time:** 2.0 hours

---

## Purpose

Create view for adding a new **Documentation Template** entity. The view is only responsible for page layout, permissions, and rendering the shared Livewire form. All business logic (create vs. edit, prefill by category, redirect to edit) lives inside the Livewire component.

## Rendering

* Render shared Livewire component: `App\\Livewire\\Tech\\Documentations\\Templates\\Form`
* Pass optional `category_id` from querystring to Livewire to preselect category, but do **not** lock the field
* On successful create, Livewire redirects to: `tech.documentations.templates.edit:{templateId}`

## Layout (Bootstrap)

**Top section (page frame):**

* Page title: "New Template"
* Breadcrumbs: `Tech` → `Documentations` → `Templates` → `Create`
* Secondary action (right): Button "Back to templates" → route `tech.documentations.templates.index`

**Main section:**

* Card / panel hosting the Livewire form
* Form fields (high level):

  * Template name (required)
  * Description (optional, multiline)
  * Category (optional, prefillable from `?category_id=`)
  * Visibility / scope (enum):

    * Internal only
    * Client visible (read)
    * Client form (customer can fill)
    * Hidden / system
  * Status (enum): Draft / Published / Archived (Draft as default for create)
  * Active toggle (mirrors Draft/Published but kept for future rules)
* Save action is handled **inside** Livewire, not in the blade view

**Right-side panel (narrow):**

* For create: **keep empty** (reserved for audit, usage, linked docs on edit)
* Still render the column to preserve consistent layout with other tech views

## Notes for developers

* Keep view thin — no business logic here
* Always check action permission `documents.templates.create` before rendering Livewire
* Reuse existing tech layout partials (header, toolbar, right column)
* Do **not** auto-create fields here; fields are managed in **edit** mode only (same Livewire component, different state)

## Reusable components / widgets

* Page header (standard tech header)
* Breadcrumb widget
* Right-column container (empty on create)
* Livewire form host for templates

## Smart UX suggestions

* If `?category_id=` is present, show small hint text at top of form: "Category pre-selected from context. You can change it."
* After Livewire redirects to edit, display a success toast: "Template created. You can now define fields."

## Security / logging

* Log view access and create action in audit log (later ticket)
* Require authenticated tech user

## Future extensions (not in this view)

* Show where template is used (docs count)
* Versioning / publish history
* Template cloning
