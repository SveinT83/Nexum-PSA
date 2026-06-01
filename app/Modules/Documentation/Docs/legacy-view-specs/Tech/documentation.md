# tech.documentations* — Documentation Module (Concept & View Spec)

**Creation date:** 2025-11-03
**Status:** Done
**Difficulty:** Medium
**Estimated time:** 5.0 hours (index 2h, base model 1h, forms 2h)

---

## 1. Purpose

A unified internal and customer-scoped documentation module for storing operational and technical documents. The system supports **internal**, **client**, and **site** scopes, with automatic data isolation per tenant.

Most documents are created from a **category template**, and the chosen template is **snapshotted into the document** at creation. This guarantees that documents remain consistent even if templates are updated later.

Vendor and supplier records are the exception. They are Documentation-owned master data stored in the shared `vendors` table with fixed forms, because Assets, Storage, Commercial costs, and future purchasing workflows need one canonical partner register.

Use cases include:

* Internal procedures and configurations
* Vendor and supplier master data
* ISP and manufacturer references
* Network documentation (LAN/WiFi/Firewall)
* Client- or site-specific assets or virtual machines
* Policies and operational guidelines

---

## 2. URLs & Access

* **Index:** `tech.documentations.index` — list and search all documents by category and scope
* **Create:** `tech.documentations.create` — create new document using `_form`
* **Edit:** `tech.documentations.edit:{docId}` — edit existing document using `_form`
* **Show:** `tech.documentations.show:{docId}` — read-only view of document
* **Templates:** `tech.documentations.templates.*` — manage templates per category
* **Categories:** `tech.documentations.categories.*` — manage available categories
* **Vendors:** `tech.documentations.vendors.*` — fixed vendor/manufacturer master data
* **Suppliers:** `tech.documentations.suppliers.*` — fixed supplier entry point using the shared vendor model

**Access levels:**

* `documents.view.tech` — view internal documents
* `documents.view.client` — view client documents
* `documents.manage` — create or edit documents
* `documents.delete` — delete documents

**Controller root:** `App\Modules\Documentation\Controllers\Tech`

---

## 3. Layout & Navigation

### Global structure (Bootstrap)

**Top section:**

* Page title: `Documentation`
* Buttons: `+ New Document`, `Manage Templates`, `Manage Categories`
* Scope switcher dropdown: `Internal / Client / Site`

**Main section (split layout):**

* **Left column:** Category menu

  * First item: `All` → global view across all categories
  * Each category: name + count badge (docs count)
  * Click to filter right-hand list by category

* **Right column:** Document list / search results

  * Contextual search (in selected category or global if “All” selected)
  * Filters: `Created by`, `Last updated`, `Template`, `Tags`
  * Responsive table or card list
  * Click row → open `tech.documentations.show:{docId}`

* **Right-side narrow panel:**

  * Category info or document preview
  * Recent updates, pinned/favorites (future)

---

## 4. Data Model

**documents**

* id (uuid)
* title (string)
* category_id (foreign key)
* template_snapshot_json (json) — embedded copy of template schema
* data_json (json) — actual user-entered field values
* scope (enum: internal | client | site)
* documentable_type / documentable_id (morph for Client/Site/User/Asset)
* tags (json array)
* created_by / updated_by
* timestamps

**categories**

* id
* name
* icon_hint
* is_global_enabled (bool)
* default_template_id (nullable)

**templates**

* id
* category_id
* name
* description
* fields_json (schema definition)
* is_active (bool)
* created_by / updated_by

**vendors**

* id
* name
* vendor_code
* org_no
* url
* phone
* email
* default_lead_time_days
* terms
* note
* is_vendor / is_supplier / is_manufacturer
* is_active

Vendor/supplier usage:

* Assets link `vendor_id` to this register for hardware manufacturers and vendors.
* Storage item forms select `Vendor / Manufacturer` and `Supplier` from this register.
* Storage opens `New vendor` and `New supplier` in new tabs instead of creating partner records inline.
* Commercial costs can reference the same vendor records.
* Future purchase ordering should use this table for supplier and manufacturer identity.

---

## 5. Behaviors

1. **Scope system**

   * Default: `Internal`
   * Scope switcher updates available categories, templates, and documents
   * Client → reveals site filter
2. **Template copy-on-create**

   * On create, selected template schema duplicated into document
   * On edit, stored schema is used; cannot change template
3. **Category behavior**

   * Each category may define one or multiple templates
   * If only one template → preselected and locked
   * `Vendors` and `Suppliers` categories open fixed register screens instead of dynamic templates
4. **Validation**

   * No drafts — all required fields must be filled before save
   * Template field rules enforced (required, min/max, dependencies)
5. **Real-time updates**

   * Document create/update/delete events emitted to Livewire lists
6. **Audit logging**

   * Log all changes with actor, document id, category, and diff summary

---

## 6. Components & Reusable Partials

* `_form.blade.php` — shared form for create/edit
* `components.category-menu` — left navigation
* `components.document-card` — list/card view for index
* `components.scope-switcher` — top-right dropdown
* `components.inline-errors` — validation feedback
* `components.preview-panel` — right-side info widget

---

## 7. Permissions & Guards

* Technicians can only access documents within scopes they are authorized for.
* Internal documents are hidden from client scopes.
* Client and site scopes require active client context.
* Category and template access filtered by scope and active state.

---

## 8. Developer Notes

* Template and category data cached for performance.
* Documents stored with schema snapshot for stability.
* Use morph relationships for documentable models.
* Avoid direct references to live templates after creation.
* Designed for Livewire conversion later if reactive field logic expands.

---

## 9. Future Extensions

* Add document favorites and pinning
* Introduce document versioning
* Add global search index
* Integrate with ticket view for related documentation
