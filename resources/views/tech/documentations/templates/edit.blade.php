# tech.documentations.templates.edit — Edit Template View

**URL:** `tech/documentations/templates/edit:{templateId}`
**Access:** `template.admin` (minimum)
**Used by:** Internal technicians (system or documentation admins)
**Creation date:** 2025-11-03
**Controller / Path:**
`resources/views/tech/documentations/templates/edit.blade.php`
**Livewire Component:** `App\Livewire\Tech\Documentations\Templates\Form`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 2.5 hours

---

## Purpose

Allow administrators to edit existing documentation templates efficiently while preserving version integrity and audit trace.
The view reuses the shared Livewire form for Create/Edit and supplements it with key metadata and quick actions.

---

## Layout Structure (Bootstrap)

### Top Section – Template Header

**Components:**

* **Title:** `{{ $template->name }}`
* **Status badge:** “Active” / “Disabled”
* **Last edited:** `{{ $template->updated_at->diffForHumans() }}`
* **Edited by:** `{{ $template->updated_by->name }}`
* **Linked categories:** small rounded chips with category names (clickable → opens category view)
* **Audit icon:** small clock/history icon → opens right-side drawer with full audit log

**Actions (right-aligned toolbar):**

* **Save changes** (primary button)
* **Clone** (dropdown under More)
* **Disable / Enable** (toggles based on current status)

---

### Main Section – Livewire Form

Renders the shared form component:

> `@livewire('tech.documentations.templates.form', ['template' => $template])`

Form handles all field logic for name, description, scope, custom fields, and relationships to categories.

Behavior:

* Auto-loads existing data via bound model.
* Validates inputs dynamically.
* Shows inline save success/failure toasts.

---

### Right-Side Drawer (Audit Log)

Triggered via the history icon in header.

Contents:

* Change history: user, action, field, timestamp.
* Pagination for long histories.
* Optional diff viewer for text fields.

Access limited to roles with `template.admin`.

---

## UX Notes

* Keep the form centered with max-width container.
* Show unsaved-changes warning before navigation.
* Sticky footer for main actions (Save / Cancel).
* Consistent with Create Template view for familiarity.

---

## Linked Views

* `tech.documentations.templates.create` → uses same Livewire form.
* `tech.documentations.templates.index` → returns to list view after save.

---

## Future Enhancements

* Version compare modal (visual diff between template revisions).
* Inline preview for template fields (render mode).
* Quick duplicate with prefilled metadata.

---
