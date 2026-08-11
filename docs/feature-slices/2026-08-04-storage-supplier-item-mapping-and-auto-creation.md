# Feature Slice: Supplier Item Mapping And Controlled Auto-Creation

Status: Implemented - Awaiting Human Review
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`
Owner: Svein / Codex

## Goal

Resolve imported supplier lines safely to Storage Items, persist learned supplier-SKU mappings, and
support settings-controlled creation of distinct supplier-imported Items without name-only merges.

## User-Visible Behavior

The import detail page shows every source line, evidence, possible matches, and resolution status.
An authorized user can map to an existing Item, create a prefilled distinct Item, or reject the line.
A saved resolution is reused automatically on later imports.

Profiles may allow safe automatic creation of a distinct Item when no existing identity matches.
Ambiguous matches remain exceptions.

## Scope

- Implement explicit zero/one/several supplier-SKU match behavior.
- Support other exact unique identifiers only after source evidence and schema uniqueness are proven.
- Never auto-link by product-name or free-text similarity alone.
- Add reusable Storage actions for atomic Item plus ItemVendor creation/update with existing domain
  validation.
- Persist mapping method, source, actor/service identity, evidence, profile version, and timestamps.
- Add settings for identity order, SKU canonicalization, ambiguity, warehouse, and new-Item policy.
- Support `existing_only`, `create_distinct_review_flagged`, and `create_distinct_active` behavior.
- Require explicit defaults/evidence for status, orderability, warehouse, currency, tax,
  serial/batch/expiry, asset behavior, warranty, manufacturer, and other required Item facts.
- Keep catalog-review/provenance visible without necessarily blocking an otherwise allowed order.
- Audit current duplicate supplier-SKU mappings before any stronger uniqueness constraint.
- When permitted, call a Documentation-owned supplier-bootstrap action for a missing Vendor instead
  of writing Vendor master data from Storage.
- Update the import line state and rerun validation after every resolution.

## Out Of Scope

- AI extraction or name-based auto-merge.
- Purchase Order finalization.
- Catalog consolidation of separate Items.
- Receiving or inventory posting.
- Broad changes to the existing one-warehouse-per-Item model.

## Data Touched

- Storage import line resolution/mapping facts.
- `storage_items`, `storage_item_vendors`, and possible explicit provenance/catalog-review fields.
- Documentation Vendor records only through an owned guarded action when approved by policy.
- Storage actions, requests, views, profile settings, and tests.
- No receipt, Stock Unit, Movement, or on-hand quantity data.

## Permissions

- `storage.purchase_import_resolve` for manual line resolution.
- Existing Item create/manage permission remains required for manual Item creation.
- Automatic creation uses the configured technical actor and the effective policy.
- Documentation supplier creation requires its owned permission/action boundary.
- Viewing an import never grants Item or Vendor mutation.

## Tests

- Exactly one active mapping, zero mapping, several mappings, inactive/deleted Item, supplier mismatch,
  warehouse mismatch, and orderability guards.
- SKU canonicalization preserves meaningful leading zeros, spaces, punctuation, and supplier rules.
- Exact GTIN/MPN behavior only when unique; name similarity never auto-links.
- Manual mapping is audited and reused on a later import.
- Distinct Item auto-creation uses all required defaults, creates ItemVendor atomically, and is
  idempotent under concurrent/retried jobs.
- Missing critical tracking/asset/tax facts block or use only the documented profile default.
- Ambiguity cannot be overruled by a high AI suggestion confidence.
- Supplier bootstrap follows Documentation ownership and source/policy provenance.
- No final PO, receipt, movement, or stock change occurs.

## Documentation

- Storage Knowledge for matching priority, ambiguity, manual mapping, new Items, provenance, and
  catalog review.
- Documentation Knowledge if supplier bootstrap is implemented.
- Admin guidance for safe profile defaults.
- TODO and human-review updates.

## Done Criteria

- [x] Every imported line has a deterministic, inspectable resolution state.
- [x] Exact mappings are reused without repeated review.
- [x] Name-only matching cannot merge Items automatically.
- [x] Settings-controlled distinct Item creation is atomic, permissioned, and idempotent.
- [x] Supplier bootstrap, when enabled, follows the Documentation-owned guarded action boundary.
- [x] Collision-aware resolution is retained and no destructive supplier-SKU uniqueness constraint is introduced.
- [x] Focused Storage, Documentation, permission, concurrency, and no-stock coverage is implemented.
- [x] Documentation and the stable human-review entry are current.
- [ ] Validate mappings and controlled Item creation with protected real supplier emails during `HR-2026-08-04-003`.
