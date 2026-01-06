# tech.documentations.categories.edit — View Specification

**URL:** `tech.documentations.categories.edit:{categoryId}`
**Access level & permissions:**

* Requires: `documents.view.internal`
* And: `template.admin` (to edit existing categories)
* Optional guard: technician/system scope to toggle `is_global_enabled`

**Creation date:** 2025-11-01
**Controller / folder structure:**

* Controller (Laravel): `App\Http\Controllers\Tech\Documentations\Categories\FormController@edit`
* View stub (Blade): `resources/views/tech/documentations/categories/edit.blade.php`
* Livewire component (shared): `App\Livewire\Tech\Documentations\Categories\Form`

**Status:** In progress
**Difficulty:** Low
**Estimated time:** 1.0 hour (layout 0.5h, wiring Livewire 0.5h)

---

## 1. Purpose

This view provides the **edit wrapper** for documentation categories. It is **not** a full form on its own. Instead it loads the shared Livewire form component `tech.documentations.categories.form` in **edit mode** and adds page-level elements like title, breadcrumbs, sticky footer, and action buttons. This keeps create and edit fully in sync and prevents configuration drift between the two modes.

---

## 2. How it uses the Livewire form

* The Blade view only mounts the component:

  * `<livewire:tech.documentations.categories.form mode="edit" :categoryId="$categoryId" />`
* `mode="edit"` makes the component:

  * load the existing category by `$categoryId`
  * lock the `category_key` field (read-only + badge)
  * show the audit card in the right column
  * filter templates to the current category key
  * show the warning: "Changing primary template only affects new documents."
* Parent view (`edit.blade.php`) is responsible for:

  * page title: "Edit documentation category"
  * breadcrumbs: `Documentation → Categories → {Category name} → Edit`
  * sticky footer with **Save** and **Cancel** buttons
  * passing success/errors from controller to Livewire (flash)

---

## 3. Layout (Bootstrap)

**Top section (shared layout):**

* Reuse shared page header component (title + breadcrumbs + right-aligned actions)
* Action: "Back to categories" → route `tech.documentations.categories.index`

**Main section (2 columns):**

* **Left (wide):** rendered by Livewire form (basics + scope/visibility)
* **Right (narrow):** rendered by Livewire form (template panel + audit)

**Sticky footer (shared component):**

* Reuse shared sticky footer used by `create`
* Primary: **Save changes** → triggers Livewire `save`
* Secondary: **Cancel** → route back to index/detail
* Error slot for Livewire validation

## 4. Behavior

* On load: fetch category by ID; if not found → 404 or redirect with error
* On save: Livewire validates with same rules as create (but allows same key)
* On success: parent may redirect back to categories list or stay on edit
* Deletion is **not** handled here; deletion is separate and must check for linked documents

---

## 5. Reusable components / widgets

* **Breadcrumb component** (shared): to keep tech module consistent
* **Sticky footer** (shared): same as create view
* **Audit card**: rendered by Livewire only in edit mode

---

## 6. Notes for developers

* Do **not** duplicate fields here; keep them in `App\Livewire\Tech\Documentations\Categories\Form`
* Keep route name consistent with create: `tech.documentations.categories.edit`
* Remember guard for `is_global_enabled` must be enforced in Livewire, not only in Blade
* This view must be registered in routes under `/tech/documentations/categories/{category}/edit`
