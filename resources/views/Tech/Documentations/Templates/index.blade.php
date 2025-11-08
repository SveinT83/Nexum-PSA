# tech.documentations.templates.index — View Specification (Templates List)

**URL:** `tech.documentations.templates.index`
**Access & permissions:** `documentations.admin`
**Creation date:** 2025-11-03
**Controller / path:** `App\Http\Controllers\Tech\Documentations\Templates\IndexController@index`
**Livewire/Components:** `App\Livewire\Tech\Documentations\Templates\Index\*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 3.5 hours

---

## Purpose

Provide a fast overview of all documentation **templates**, grouped by **category** with robust **sorting** and **filtering**. Supports quick navigation to the **field builder**.

---

## Layout (Bootstrap)

**Top section**

* Breadcrumbs: `Documents > Templates`
* Page title: **Templates**
* Actions (right-aligned):

  * **New Template** (primary) → route: `tech.documentations.templates.create`
  * **Import** (dropdown)
  * **Export** (dropdown)

**Main section**

* **Controls row** (left→right):

  * Search input (placeholder: *Search name, description, category*)
  * Filter: Category (multi-select)
  * Filter: Status (Draft / Active / Archived)
  * Filter: Updated (Today / 7d / 30d / Custom)
  * Sort (select): *Name*, *Category*, *Updated*, *Fields*, *Usage count*
  * View toggle: **Grouped by Category** | **Flat list**
* **Results area**:

  * Default: **Grouped by Category**. Each category forms a collapsible section (accordion style).
  * Each section renders a **Template table** (see Columns) with pagination.

**Right-side panel (narrow)**

* **Selection preview** (updates when a row is selected):

  * Template name, status badge, last updated, owner
  * Category(ies) badges
  * Field count; key field types summary (chips)
  * Quick actions: **Edit**, **Duplicate**, **Archive**
* **Recent activity** (template/audit snippets)
* **Tips** (contextual help)

---

## Table (Columns)

1. **Name** (link → `tech.documentations.templates.edit:{templateId}`)
2. **Category** (badge; supports multiple)
3. **Fields** (count)
4. **Status** (Draft / Active / Archived)
5. **Usage** (# documents created from this template)
6. **Updated** (relative + exact date on hover)
7. **Actions** (icon buttons): *Edit*, *Duplicate*, *Archive*, *Delete* (guarded)

---

## Components & Widgets (reusable)

* `ui.searchbar.basic` — debounced search with clear button
* `ui.filter.multi` — pill-based multi-select filter
* `ui.sort.selector` — unified sort control
* `ui.accordion.category` — grouped list container
* `ui.table.templates` — standardized table with responsive stacking
* `ui.badge.status` — status chip (Draft/Active/Archived)
* `ui.panel.preview` — right panel preview summary
* `ui.pagination.basic` — consistent paging controls
* `ui.empty.templates` — empty-state widget with CTA to **New Template**

---

## Buttons & Icons (suggested)

* **New Template**: plus-square icon
* **Import/Export**: inbox/outbox icons (dropdown)
* Row actions: edit (pencil), duplicate (copy), archive (box), delete (trash)
* Group toggles: chevron-down/up

---

## Sorting & Filtering Rules

* Sort defaults: **Updated (desc)**.
* Search targets: template name, description, internal key, category names.
* Category filter supports multiple selections; when grouped view is active, only matching categories render.
* Status filter defaults to **Active + Draft**.
* Updated filter applies date range to `updated_at`.

---

## Interactions

* **Row click** opens **Edit/Field Builder**: `tech.documentations.templates.edit:{templateId}`.
* **Duplicate** opens modal: choose target category(ies) and status for new copy.
* **Archive** immediately hides from default views; reversible from Status filter.
* **Delete** requires 2-step confirm and is disabled if template is referenced by rules (show tooltip).
* Selecting a row populates the right panel preview.

---

## Smart UX

* **Sticky controls**: search/filters remain visible on scroll.
* **Stateful URL params**: `?q=&category[]=...&status=&sort=` for shareable views.
* **Remember last view** (grouped vs flat) per user session.
* **Inline field summary**: hover the Fields count to see top 5 fields (type:name).
* **Bulk actions** (optional, if needed later): archive, export selected.

---

## Permissions & Guards

* Only `documentations.admin` may access the view and all actions.
* **Delete** action hidden for users without `documentations.admin` (same role gate keeps it simple).

---

## Empty States

* No templates overall → show **Create your first template** CTA.
* No results after filters → show **No matches** with *Clear filters* button.

---

## Telemetry & Logs

* Log: view opened, filters used, exports run, destructive actions (archive/delete/restore).

---

## Dependencies

* Relies on categories existing; otherwise render a notice and keep **New Template** enabled (category selectable in modal).

---

## Related Routes

* Create: `tech.documentations.templates.create`
* Edit (Field Builder): `tech.documentations.templates.edit:{templateId}`
* Categories index: `tech.documentations.categories.index`
