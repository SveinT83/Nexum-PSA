# Updates — Documentations.md & Views, Routes & Permissions.md (Categories Create/Edit + Template binding)

## A) Documentations.md — Additions & Revisions

### 1) New/updated view entries

* **URL:**

  * `tech.documentations.categories.create`
  * `tech.documentations.categories.edit:{categoryId}`
* **Access & permissions:**

  * View module: `documents.view.internal`
  * Create/Edit categories: **`documents.categories.manage`** *(new)* or `template.admin`
* **Controller path:** `App\Http\Controllers\Tech\Documentations\Categories\FormController`
* **Views:**

  * `resources/views/tech/documentations/categories/create.blade.php`
  * `resources/views/tech/documentations/categories/edit.blade.php`
* **Status:** In progress
* **Difficulty:** Medium
* **Estimated time:** 3.5 hours (form 1.5h, template selector 1h, validation/audit 1h)
* **Created:** 2025-11-03

### 2) Category data model (clarified)

* `category_key` (string, unique) — **read‑only in Edit** once documents exist
* `name` (string)
* `icon_hint` (string; lucide key)
* `is_global_enabled` (bool)
* `allows_client_scope` (bool)
* `allows_site_scope` (bool)
* `is_client_visible` (bool)
* `primary_template_id` (FK → documentation_templates.id)
* `snapshot_on_create` (bool, default true)
* `sort_order` (int)
* `description` (text)

### 3) Behavior notes (new)

* **Primary template selection in the same form** (right column). If none exists → show empty‑state + link to create template.
* **Changing primary template does not affect existing documents** (they keep their embedded snapshot). Affects **new** documents only.
* When `is_client_visible = true`, at least one of `allows_client_scope` or `allows_site_scope` must be enabled.
* `is_global_enabled = true` requires technician/system scope.

### 4) Validation (concise)

* `name` required, max 150
* `category_key` required, alpha_dash, max 100, unique per tenant
* `primary_template_id` recommended when category is visible to clients
* Cross‑field: if `is_client_visible` = true → require client or site scope enabled

### 5) Layout (Bootstrap; create/edit)

* **Top**: Title, Back to categories, and on Edit: "View documents in this category"
* **Main (two columns)**

  * **Left:** Basics (name, key, icon, description) + Scope & visibility (checkboxes)
  * **Right:** Primary template selector + `Snapshot on create` toggle; Edit‑only Audit panel (created/updated/by, ID)
* **Sticky footer:** Save / Cancel + validation messages

### 6) Reusable components

* `doc-category-form` (Blade/Livewire)
* `template-selector` (filters by `category_key` and global templates on first entry)

---

## B) Views, Routes & Permissions.md — Diffs

### 1) Permissions — add

* **`documents.categories.manage`** — Create/Edit/Delete categories (system scope). Optionally map to `template.admin` if you want fewer granular keys.

### 2) Routes — add under Technician App → Documentations

```php
// Tech → Documentations → Categories
Route::prefix('tech/documentations/categories')->name('tech.documentations.categories.')->group(function () {
    Route::view('/create', 'tech.documentations.categories.create')
        ->middleware('permission:documents.categories.manage|template.admin')
        ->name('create');

    Route::view('/{category}/edit', 'tech.documentations.categories.edit')
        ->middleware('permission:documents.categories.manage|template.admin')
        ->name('edit');
});
```

### 3) Views — register

```
resources/views/tech/documentations/categories/
  create.blade.php
  edit.blade.php
```

### 4) Notes

* Keep client‑portal users away from category management (no routes under /client for this).
* Category cards in `tech.documentations.index` can expose a small "Edit" action for users with `documents.categories.manage`.

---

## C) Page metadata blocks (for both Create & Edit)

* **URL:** as above
* **Access:** `documents.view.internal` + `documents.categories.manage` *(or `template.admin`)*
* **Creation date:** 2025-11-03
* **Controller path:** `App\Http\Controllers\Tech\Documentations\Categories\FormController`
* **Status:** In progress
* **Difficulty:** Medium
* **Estimated time:** 3.5 hours (each page references the same shared form)

---

## D) Open Items

* Decide if `category_key` becomes immutable once any document exists (recommended: immutable).
* Decide whether multiple default templates are needed; current spec: single **primary** + alternative choices during doc‑create.
