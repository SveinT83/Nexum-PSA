# tech.documentations.edit — Edit Document View

**URL:** `tech/documentations/edit:{docId}`
**Access & permissions:** View: `documents.view.tech`; Edit: `documents.edit`; Delete: `documents.delete`
**Creation date:** 2025-11-03
**Controller / Path:** `App\Http\Controllers\Tech\Documentations\EditController@edit`
**Renders partial:** `tech/documentations/_form` (`mode = edit`, `document` bound)
**Status:** In progress
**Difficulty:** Low
**Estimated time:** 2.5 hours (5×30 min)

---

## Purpose

Allow technicians/admins to **edit an existing document** using the same shared form. Ensures predictable behavior, auditability, and no schema drift from templates (schema copied at create-time).

---

## Entry & Scoping

* Entry points: from index list row click, from show page `Edit` button, or deep link.
* Scope badge shows document scope (`Internal`/`Client`/`Site`). Scope is **read-only** here; changing scope requires a move action (future).
* Category is editable unless locked by policy; **Template is not changeable** in edit mode.

---

## Layout (Bootstrap)

**Top section**

* Title: `Edit Document`
* Breadcrumb: `Documentation / Edit`
* Scope badge (read-only)

**Main section**

* Renders `_form.blade.php` with `mode=edit` and pre-filled data.
* Field groups provided by the partial: Title, Category, Description, Stored schema fields, Tags, Owner, Attachments

**Right-side panel (narrow)**

* **Document info:** ID, created by/at, last updated by/at
* **Template snapshot info:** name/version at creation time (read-only)
* **Validation status:** missing required fields
* **Quick actions:** Duplicate, Preview, Delete (permission-gated)

---

## Behaviors & Rules

* **No template switch** in edit mode to avoid breaking stored data. (Optional migration tool in future.)
* **Autosave warning** on navigation if dirty.
* **Duplicate** creates a new document using the **stored schema + current data**.
* **Delete** requires confirmation; blocked when policy forbids (e.g., referenced by other records).
* **Real-time updates**: emit event on save for list refresh elsewhere.

---

## Buttons & Actions

* **Primary:** `Save changes`
* **Secondary:** `Cancel` → back to `tech.documentations.show:{docId}` or previous
* **More actions:** `Duplicate`, `Preview`, `Delete` (only with `documents.delete`)

---

## Validation

* Title and Category required.
* Stored schema field rules enforced (required/min/max/regex/dependencies).

---

## Components / Widgets / Icons (suggested)

* Scope badge icon: building/users/map-pin
* Inline errors component
* Panel cards for audit info and snapshot

---

## Events & Logging

* Log: `document.update_attempt`, `document.updated`, `document.duplicated`, `document.deleted`
* Include actor, document id, scope, category id, diff summary
* Emit UI event: `document.saved`

---

## Notes

* Keep all edit logic in the shared partial; the edit view is a thin wrapper.
* Moving scope (internal → client/site) is out of scope for this page; handle via a dedicated move flow later.
