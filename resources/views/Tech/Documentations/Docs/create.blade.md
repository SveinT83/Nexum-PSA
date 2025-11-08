# tech.documentations.create — Create Document View

**URL:** `tech/documentations/create`
**Access & permissions:** View: `documents.view.tech`; Create: `documents.manage`
**Creation date:** 2025-11-03
**Controller / Path:** `App\Http\Controllers\Tech\Documentations\CreateController@create`
**Renders partial:** `tech/documentations/_form` (`mode = create`)
**Status:** In progress
**Difficulty:** Low
**Estimated time:** 3.0 hours

---

## Purpose

Start a **new document** with category/scope awareness and (when applicable) **auto-select the category’s default template**. Predictable, fast og uten klikk-støy.

---

## Entry Scenarios (and defaults)

1. **From index with a selected category (left menu)**

   * `scope` = current index scope (e.g., Internal) — **locked**.
   * `category` = selected category — **preselected** (can be changed unless policy enforces).
   * If category has **one default template** → **auto-select + lock**.
   * If multiple templates → show template select filtered by category.

2. **From index with `All` selected**

   * `scope` = current index scope — **locked**.
   * `category` = **required** (user must choose).
   * Template select remains disabled until category is chosen; then loads templates.

3. **Deep-link with query params** (optional)

   * Supports `?scope=internal|client|site&category={id}`; same locking rules apply.

---

## Layout (Bootstrap)

**Top section**

* Title: `Create Document`
* Breadcrumb: `Documentation / Create`
* Scope badge (read-only) and selected category chip

**Main section**

* Renders `_form.blade.php` with `mode=create`
* Form fields (delegated to partial): Title, Category, Scope (read-only), Template, Description, Template fields, Tags, Owner, Attachments

**Right-side panel (narrow)**

* Category info widget (description, document count, updated at)
* Template preview (when selected)
* Validation checklist (live)

---

## Behavior & Rules

* **No drafts** — required fields must be filled before submit.
* **Template copy-on-create** — selected template schema is duplicated into the new document, insulated from future template changes.
* **Scope guard** — when `client`/`site`, only categories/templates the user can access are shown.
* **Preselection logic** — respects defaults from entry scenario; surface a small “Why preselected?” tooltip.
* **Unsaved changes** — leave-page confirm.

---

## Buttons & Actions

* **Primary:** `Create`
* **Secondary:** `Cancel` → back to `tech.documentations.index` (keep current scope & category selection)
* **Context shortcuts:** `Manage Categories`, `Manage Templates` (open in new tab)

---

## Components / Widgets / Icons (suggested)

* Searchable selects for Category/Template (icon: magnifier)
* Scope badge (icon: building for Internal, users for Client, map-pin for Site)
* Inline errors / validation summary
* Panel cards with subtle icons (info, list, layout)

---

## Events & Logging

* Log: `document.create_attempt`, `document.created` with actor, scope, category_id, template_id
* Emit: `document.saved` for real-time updates

---

## Notes

* Parent controller populates `categories`, `templates` (filtered by scope/category), and `scope` for the partial.
* Keep all form logic centralized in `_form.blade.php`.
