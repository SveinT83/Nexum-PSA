# tech.documentations.categories.create — Blade view that renders shared Livewire form

**URL:** `tech.documentations.categories.create`
**Access & permissions:**

* Module access: `documents.view.internal`
* Create/edit category: `template.admin`
* Optional guard in component: only technicians/system can enable global/template flags

**Creation date:** 2025-11-01
**Controller / path:** `App\Http\Controllers\Tech\Documentations\CategoriesController@create`
**View:** `resources/views/tech/documentations/categories/create.blade.php`
**Livewire used:** `App\Livewire\Tech\Documentations\Categories\Form` (same as edit)
**Status:** In progress
**Difficulty:** Low
**Estimated time:** 1.0 hour

---

## 1. Purpose

This view is the **create-entry point** for documentation categories in the documentation module. It does **not** implement its own form — instead it **mounts the shared Livewire component** that is also used by `tech.documentations.categories.edit:{categoryId}`. This guarantees that all logic for validation, template-binding and global toggles lives in **one** place.

Goal:

* Let technicians create a new documentation category quickly.
* Reuse exactly the same fields, validation rules and UI layout as edit.
* Keep routing, permissions and audit consistent with the rest of the `tech.documentations.*` views.

---

## 2. Rendered as

* **Rendered as:** Blade view that **embeds** a Livewire component
* **Blade file:** `resources/views/tech/documentations/categories/create.blade.php`
* **Embedded component:** `<livewire:tech.documentations.categories.form :category="null" />`
* **Edit parity:** `tech.documentations.categories.edit` uses **the same Livewire component** but passes an existing category model/ID.

This means: the *layout, header, breadcrumbs, right panel* can live in Blade, mens *feltene og valideringen* ligger i Livewire.

---

## 3. Expected routing

* **Route (create):** returns Blade view

  ```php
  Route::get('/tech/documentations/categories/create', [CategoriesController::class, 'create'])
      ->middleware(['auth', 'permission:template.admin'])
      ->name('tech.documentations.categories.create');
  ```
* **Route (edit):** returns its own Blade view (already documented) which embeds *the same* Livewire form but with data

  ```php
  Route::get('/tech/documentations/categories/{category}/edit', [CategoriesController::class, 'edit'])
      ->middleware(['auth', 'permission:template.admin'])
      ->name('tech.documentations.categories.edit');
  ```
* **Why:** we keep routes → controllers → Blade, so UI/layout er enkel å videreutvikle, mens form-logikk gjenbrukes i Livewire.

---

## 4. View behaviour (create)

1. Controller kaller Blade: `return view('tech.documentations.categories.create');`
2. Blade setter opp page-chrome (tittel, breadcrumbs, actions)
3. Blade renderer Livewire-komponenten uten ID:

   ```blade
   <livewire:tech.documentations.categories.form />
   ```

   eller, hvis du vil være eksplisitt:

   ```blade
   <livewire:tech.documentations.categories.form :category="null" />
   ```
4. Livewire-komponenten skjønner at dette er **create-mode** fordi den ikke får inn en kategori.
5. På submit lagrer komponenten og redirecter til `tech.documentations.categories.edit:{id}` (samme som vi gjorde for edit-delen).

---

## 5. Layout (Bootstrap)

* **Top section:**

  * Page title: `New Documentation Category`
  * Breadcrumbs: `Documentation > Categories > New`
  * Actions (right): `Save`, `Cancel` (cancel → category list or docs dashboard)
* **Main section (left, wide):**

  1. **Category info card** (reusable component):

     * Field: `Category name` (text, required)
     * Field: `Category key` (text, readonly or generated; show small helper: “Used for internal reference and template matching.”)
     * Field: `Description` (textarea, optional)
  2. **Visibility/Scope card** (shared with edit):

     * Toggle: `Is global enabled` (only if user has technician/system scope)
     * Toggle: `Allow client scope docs` (future — stored but not yet used)
  3. **Validation messages** (Livewire errors, inline)
* **Right side panel (narrow):**

  * **Template binding widget** (reusable): multiselect of templates that can be used under this category
  * **Rule / automation info box**: shows that ticket/email parsing can push docs into the category in future
  * **System notes**: “This category is created in system scope.”

**Reusable components to mark:**

* `components.cards.form-header` (title, breadcrumbs, actions)
* `components.forms.category-base-fields` (name, key, description)
* `components.panels.template-binding` (template selection)
* `components.panels.audit-info` (read-only, will show nothing on create; still mount it for consistency)

---

## 6. Permissions & scope

* View requires: `documents.view.internal`
* Create/edit requires: `template.admin`
* Toggle `is_global_enabled` requires: technician/system-only check inside component (policy or custom Gate)
* All creation events must be **audited** under the documentation module (who/what/when)

This matches the permission taxonomy in `Views, Routes & Permissions.md` and keeps admin actions inside the `template.*` area.

---

## 7. Data flow

1. User åpner enten create- eller edit-ruten.
2. **Samme Blade** lastes med enten tomt eller utfylt `$category`-objekt.
3. Bruker fyller ut feltene (navn, key, description, flags, template-tilknytning).
4. Submit → går til riktig controller-metode (`store` eller `update`).
5. Controller kjører validering (Laravel form request), lagrer, logger audit, og **redirecter til edit-ruten** slik at videre endringer alltid skjer der.

---

## 8. Hva vi *bevisst* ikke bruker her

* Ingen Livewire
* Ingen per-felt realtime-validering
* Ingen dynamisk henting av templates i view (kan legges inn senere som egen partial)

---
