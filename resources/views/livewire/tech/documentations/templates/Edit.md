# Livewire.Tech.Documentations.Templates.Edit — Template Field Builder

**URL:** `tech/documentations/templates/edit:{templateId}`
**Access:** `template.admin` (minimum)
**Creation date:** 2025-11-03
**Controller / Path:**
`App\Livewire\Tech\Documentations\Templates\Edit`
**View Path:**
`resources/views/livewire/tech/documentations/templates/edit.blade.php`
**Status:** In progress
**Difficulty:** High
**Estimated time:** 7.0 hours

---

## Purpose

A full-featured **visual field builder** that allows administrators to define the structure and layout of documentation templates. Fields are stored as structured **JSON** directly in the `templates.fields` column. The builder provides a drag-and-drop interface to organize sections, columns, and fields visually — no raw HTML.

---

## Data Model

All builder data is stored in the `fields` JSON attribute of the `Template` model.

**Structure example:**

```json
{
  "sections": [
    {
      "title": "Basics",
      "columns": [
        { "span": {"md":6}, "fields": ["client", "sites", "contact"] },
        { "span": {"md":6}, "fields": ["vendor", "manufacturer"] }
      ]
    }
  ],
  "fields": [
    { "key": "client", "type": "select.dynamic", "source": "clients" },
    { "key": "sites", "type": "select.dynamic", "source": "sites", "depends_on": {"client": "client"} },
    { "key": "manufacturer", "type": "select.dynamic", "source": "manufacturers" }
  ]
}
```

---

## Layout Structure (Bootstrap)

### Top Section – Template Metadata

Reuses header from parent `edit.blade.php` (template name, status, audit icon, etc.).

### Main Section – Field Builder Canvas

**Regions:**

* **Left Panel:** Toolbox with available field types.
* **Center Canvas:** Visual grid builder (sections → columns → fields).
* **Right Panel:** Inspector for selected element (field or section properties).

**Interactions:**

* Drag sections, columns, and fields into position.
* Click any item to edit its properties in the right panel.
* Reorder fields and sections by dragging.
* Manual **Save** button commits JSON to database.
* “Unsaved changes” warning when navigating away.

---

## Supported Field Types (v1)

✅ Text
✅ Textarea
✅ Number
✅ Email
✅ URL
✅ Date / DateTime
✅ Checkbox / Switch
✅ Select (static)
✅ Select (dynamic: Client, Site, Contact, Asset, Vendor, Manufacturer)
✅ Multi-select (static/dynamic)
✅ Secret / Password
✅ Section / Heading (non-data grouping)
✅ Repeater / Group (nested array fields)
❌ File Upload (not supported)

---

## Layout & Sections

**Section properties:**

* Title (optional)
* Description (optional)
* Column layout: 1–4 columns
* Collapsible toggle (optional)

**Column properties:**

* Width: `col-span` (1–12 per breakpoint)
* Contains ordered list of field IDs

**Drag-drop rules:**

* Fields must reside inside columns.
* Columns must reside inside sections.
* Sections can be reordered vertically.

---

## Field Configuration Panel

When a field is selected, the right panel displays editable properties:

### General Tab

* Label
* Field key (auto-generated from label, editable)
* Type (locked after creation)
* Placeholder
* Default value
* Tooltip/help text
* Visibility scope (`internal_only`, `client_read`, `client_form`, `hidden_system`)

### Validation Tab

* Required (checkbox)
* Min / Max length (for text)
* Min / Max value (for numbers)
* Min / Max date (for dates)
* Regex pattern (optional)

### Options Tab (for selects)

* Static options (label/value list)
* Dynamic source (`clients`, `sites`, `contacts`, `assets`, `vendors`, `manufacturers`)
* Multi-select toggle

### Dependencies Tab (for dynamic fields)

* Simple filter binding syntax: `sites?client_id={{client}}`
* No code or custom expressions — only key bindings.

### Advanced Tab (for layout and visibility)

* Column span override (per breakpoint)
* Conditional visibility (future)

---

## Actions & Buttons

* **Add Section** → creates new section (prompts for columns count)
* **Add Column** → adds column to selected section
* **Add Field** → opens field-type selector modal
* **Duplicate Field / Section** → clones structure
* **Delete Field / Section** → confirmation modal
* **Save** → persist JSON to database (manual only)
* **Cancel** → reload from DB (discard unsaved)

Keyboard shortcuts:

* Ctrl/Cmd + S → Save
* Ctrl/Cmd + Z → Undo (local state)
* Ctrl/Cmd + Y → Redo (local state)

---

## Behavior & Rules

* Manual save only — no autosave.
* Local unsaved state tracked in memory (warning before leaving page).
* Builder updates preview immediately when fields are modified.
* JSON validated before save to ensure integrity.
* When saved successfully, emits event `template.fields.updated` → parent view toast.

---

## Dependencies & Data Sources

**Dynamic sources:** Clients, Sites, Contacts, Assets, Vendors, Manufacturers.
**Scope logic:**

* Sites, Contacts, and Assets depend on selected Client.
* Vendors/Manufacturers show client-specific options if available, else global.
* Inline creation **not supported** — must be created via their respective categories.

---

## UX Guidelines

* Always visible Save/Cancel in sticky footer.
* Drag-drop handles clearly indicated with grip icon.
* Empty state message: *“No fields yet — add a section to start building.”*
* Preview alignment uses Bootstrap grid rules.
* Builder mirrors how documents will render for accuracy.

---

## Non-functional

* Average latency <150ms for local changes.
* JSON size limit: 512KB per template.
* Full audit on save under event `template.fields.updated`.
* Real-time sync (future) via Echo channel for collaborative editing.

---

## Test Checklist (Dev)

* Add, reorder, and remove fields → JSON updates correctly.
* Validation rules persist in JSON.
* Dependent selects (Site ← Client) resolve properly in preview.
* Save / Cancel logic consistent.
* Permissions: 403 if not `template.admin`.
* Reload shows identical structure after DB roundtrip.

---

## Future Enhancements

* Field library import/export.
* Version diff viewer.
* Conditional visibility rules (expressions).
* Visual preview toggle between internal/client modes.
