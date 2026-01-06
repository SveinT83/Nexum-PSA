# tech.documentations.categories.form — Livewire Component (Shared Create/Edit Form)

**URL / usage context**

* Used by: `tech.documentations.categories.create`
* Used by: `tech.documentations.categories.edit:{categoryId}`
* Rendered as: **Livewire component** from Blade page-frame

**Access & permissions**

* Module access: `documents.view.internal`
* Manage categories: `template.admin`
* Optional guard: only technician/system scope can toggle `is_global_enabled`

**Creation date:** 2025-11-02
**Controller / path:** `App\Livewire\Tech\Documentations\Categories\Form`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 3.0 hours (1.0h Livewire class + 1.5h Blade view + 0.5h wiring in create/edit views)

---

## Purpose

A single, reusable form for **creating** and **editing** Documentation Categories inside the tech area. The goal is to avoid duplicate logic in `create.blade.php` and `edit.blade.php` and to centralize validation, template binding, and guard logic in one place. Both wrapper views only provide the page frame (title, breadcrumbs, right panel); the actual fields, validation and save logic live in this component.

This form enforces rules we agreed on:

1. A category **can be created without template** but **templates can be linked later**.
2. A category **cannot be deleted** (and should not show destructive actions) if it has documents bound to it.
3. We must be able to **pick a template** (one or more, depending on future support) when editing.
4. We keep structure **simple** (no Livewire in wrapper pages) — only the form itself is Livewire.

---

## How it is used from Blade

### 1) Create page

* Route: `tech.documentations.categories.create`
* Blade: `resources/views/tech/documentations/categories/create.blade.php`
* Blade only renders layout and calls the component:

  * `@livewire('tech.documentations.categories.form', ['mode' => 'create'])`
* Blade is responsible for: page title, breadcrumbs, right-side help panel

### 2) Edit page

* Route: `tech.documentations.categories.edit:{categoryId}`
* Blade: `resources/views/tech/documentations/categories/edit.blade.php`
* Blade only renders layout and calls the component:

  * `@livewire('tech.documentations.categories.form', ['mode' => 'edit', 'categoryId' => $category->id])`
* Blade is responsible for loading the model and passing `id` (or the component can resolve it internally from the route param)

> Both wrapper views **must** keep the standard layout: Top-bar → Main (form) → Right panel. This is to stay consistent with PSA’s static dashboard layout.

---

## Component class structure

**Namespace**

```php
namespace App\Livewire\Tech\Documentations\Categories;
```

**Class name**

```php
class Form extends Component
```

**Public properties (initial)**

* `$mode` (string) — `create` | `edit`; decides which actions to run and what title to show.
* `$categoryId` (int|null) — required in edit mode.
* `$name` (string) — category display name.
* `$key` (string) — machine key / slug, auto-generated from name, editable only by admin.
* `$description` (string|null) — optional description shown in lists.
* `$template_id` (int|null) — selected template for this category (for now: single template; later we can switch to array).
* `$is_active` (bool) — whether this category can be used when creating docs.
* `$is_global_enabled` (bool) — whether this category is available outside client scope (internal/global). Only system/technician with proper perm can change.
* `$has_documents` (bool) — computed from DB to disable delete and show warning.
* `$templates` (Collection) — select options, loaded from template table.

**Computed / derived**

* `getTitleProperty()` — returns “Create category” or “Edit category”.
* `getCanToggleGlobalProperty()` — returns true only for users with `template.admin` or system scope.

**Mount logic**

1. Accept `$mode` and optional `$categoryId`.
2. Load templates for select.
3. If `$mode === 'edit'`, load category from repository/model and hydrate form fields.
4. Compute `$has_documents` using relation count.

**Validation rules**

* `name`: required, string, max:190
* `key`: required, string, alpha_dash, max:190, unique per tenant (ignore current id on edit)
* `description`: nullable, string
* `template_id`: nullable, exists:documentation_templates,id
* `is_active`: boolean
* `is_global_enabled`: boolean (validated only if user can toggle)

> Validation must live in the component to keep create/edit wrappers dumb.

---

## UI layout (Bootstrap)

**Top section (within main column)**

* Heading (from component): `{{ $this->title }}`
* Subtext: “Categories organize internal and client visible documentation. Keep naming consistent.”
* Breadcrumbs are **not** part of the component; they belong to the Blade wrapper.

**Main section (left / wide)**

1. **Category basics** (card)

   * Name (text input, required)
   * Key / Slug (text input, readonly or editable by admin; auto-filled from Name on create)
   * Description (textarea, optional)
2. **Template binding** (card)

   * Select: “Default template” (dropdown from `$templates`)
   * Inline note: “Category can be created without template. You can link one later.”
3. **Activation & scope** (card)

   * Toggle/switch: “Active”
   * Toggle/switch: “Available globally / internal” (disabled if user lacks permission)
   * If `$has_documents === true`: show alert: “Documents exist in this category. Deletion and certain changes are restricted.”
4. **Audit-ish info** (small text)

   * “All changes are logged.” (this aligns with global audit rules)

**Right side panel (narrow)** — provided by the Blade wrapper, but the component can expose recommended content:

* Short help: “Use categories to group docs per module/client.”
* Reminder about **no category change on docs** (your earlier rule).
* Link/button: “View templates” (route to template index)

---

## Actions

### save()

* Validates input
* If create:

  * Create new DocumentationCategory model with provided fields
  * Generate key if empty
* If edit:

  * Update existing model
  * If `$has_documents` and user tries to deactivate globally → allow, but show warning toast (business rule may be tightened later)
* Write audit log: who, what, before/after
* Emit event: `documentation-category.saved`
* Optionally redirect back to index or stay on page (configurable via param)

### delete() (future / optional)

* Only if **no documents** attached (`$has_documents === false`)
* Only for `template.admin`
* Soft delete or mark inactive (to be aligned with global delete policy)
* If there **are** documents: show error message and do nothing

---

## Business rules captured

1. **One source of truth**: create/edit views **must not** duplicate fields — they call this component.
2. **Internal vs client scope**: controlled by `is_global_enabled`; only certain roles can change it.
3. **No category change on existing docs**: the form will show an info alert when editing and `$has_documents === true`.
4. **Template choice is optional**: we don’t block save if no template is picked.
5. **Logging**: every save/update must be written to audit (who/what/when).

---

## Notes for developers / GitHub Copilot

* Always scaffold the Blade page-frame first, then drop in `@livewire(...)`.
* Keep component name stable: `tech.documentations.categories.form` → maps to `App\Livewire\Tech\Documentations\Categories\Form`.
* Reuse PSA shared form components (labels, inputs, switches) to keep UI consistent.
* Don’t inline heavy logic in Blade; keep it in PHP class.
* When we later add multi-template support, extend only the **Template binding** card.

---

## Done when

* Both routes (`create`, `edit`) render the same Livewire form.
* Name/key/description/template/scope can be set.
* Global toggle is role-guarded.
* Editing existing category with docs shows restriction alert.
* Audit entries are created on save.
* Layout matches top/main/right pattern (via wrapper).
