# Feature Slice: CloudFactory Client And Catalogue Sync

Status: Done
Date: 2026-07-20
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
Owner: Codex

## Goal

Synchronize customers, Vendors, products, and source offers automatically without unsafe merges.

## User-Visible Behavior

Strong matches link automatically, ambiguous matches enter a conflict queue, operators can link
manually, missing CloudFactory customers can create Nexum Clients, and the full catalogue is staged.
Cloud Factory category identities map to canonical Nexum Vendors, with a manual mapping card when an
automatic decision would be unsafe.

## Scope

Two-way customer fields, deterministic matching, manual linking, Vendor normalization, catalogue
pagination, sync history, conflicts, stable category-to-Vendor links, and propagation of manual
Vendor choices to existing offers and Services.

## Out Of Scope

Weak fuzzy merges and deletion of provider records.

## Data Touched

Clients, Vendors, CloudFactory links, products, offers, sync runs, conflicts, and audit events.

## Permissions

View for technicians with Client access; linking and sync for integration managers.

## Tests

Strong/ambiguous matching, inbound creation, outbound updates, pagination, idempotency, conflicts,
category-to-Vendor mapping without duplicate Microsoft records, generic-category hold, and manual
Vendor propagation into offers and Services.

## Documentation

Operator matching and reconciliation guidance.

## Done Criteria

- [x] Repeat syncs do not duplicate records.
- [x] Ambiguous customers are never merged automatically.
- [x] Manual linking is audited.
- [x] Existing Microsoft catalogue families reuse the canonical Microsoft Vendor.
- [x] Generic or ambiguous Vendor categories wait for a manual decision instead of being guessed.

## Verification

The Dev catalogue synchronization processed 10,898 offers. Fifteen of sixteen category mappings
resolved automatically, 10,883 offers received a canonical Vendor ID, and all 10,638 Microsoft
offers reused the existing Microsoft Vendor. The remaining fifteen IaaS offers await manual mapping.
