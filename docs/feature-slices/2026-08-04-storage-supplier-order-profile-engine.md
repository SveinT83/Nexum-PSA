# Feature Slice: Storage Supplier Order Profile Engine

Status: Implemented - Awaiting Human Review
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`
Owner: Svein / Codex

## Goal

Deliver a complete no-AI profile workflow with immutable versions, a constrained extraction DSL,
protected fixtures, deterministic preview/shadow processing, activation, health, and rollback.

## User-Visible Behavior

Admins can list, create, edit, clone, test, activate, pause, retire, export, import, and roll back
supplier document profiles from Storage settings. A visual mapping/test preview shows source evidence
beside the canonical result and explains validation failures.

A Nexum installation without AI can operate the profile engine fully. Unknown or changed formats
enter the exception queue rather than requiring a code deployment.

## Scope

- Add profile containers, immutable versions, fixture references/assertions, lifecycle, health, and
  active-version relationships.
- Implement a versioned, constrained DSL for document signatures, safe DOM/text labels/selectors,
  table/column mapping, locale, bounded patterns, normalizers, required fields, and validators.
- Prohibit executable code, dynamic classes, shell commands, provider tools, remote URLs, and
  arbitrary callbacks in definitions.
- Normalize sanitized HTML into safe blocks/tables with plain-text fallback.
- Implement canonical schema validation, evidence anchors, amount/quantity reconciliation, and
  unknown-field handling.
- Implement manual profile fixtures and a protected fixture corpus with checksums and expected
  critical facts.
- Add deterministic preview and shadow comparison without PO/Item writes.
- Implement version validation, activation, superseding, one-click rollback, and pinned in-flight
  versions.
- Add Admin pages under Storage/Inventory settings following shared Bootstrap Admin patterns.
- Support installation-safe profile export/import without source emails, credentials, customer data,
  or AI secrets.
- Add a first Itegra-style body profile only as seeded declarative data and fixtures, never PHP
  supplier logic.

## Out Of Scope

- Email and Signal handoff.
- AI profile creation or repair.
- Item mapping or creation.
- Active auto-registration.
- PDF/attachment extraction.
- Generic Inbox AI bootstrap.

## Data Touched

- `storage_purchase_order_import_profiles` or the final Slice 1 equivalent.
- Profile versions, fixtures, checksums, test metrics, activation facts, health, and rollback data.
- Storage settings/navigation, actions, DTOs/contracts, parsers, views, and tests.
- Existing POs and Items remain unchanged.

## Permissions

- `storage.purchase_import_profile_manage` for mutation, testing, activation, and rollback.
- `storage.purchase_import_view` for read-only profile/fixture result visibility where allowed.
- Profile management never grants `storage.purchase_manage` or AI-provider administration.

## Tests

- DSL schema/version validation and rejection of unknown/executable constructs.
- Safe HTML/table/text normalization, no remote fetch, Unicode, locale/date/decimal handling, and
  bounded pattern limits.
- Single/multi-line, quantity above one, freight/discount, missing optional fields, and template
  variation fixtures.
- Required-field/evidence and arithmetic rejection.
- Version checksum, immutable history, activation, pinned imports, superseding, rollback, and
  concurrent activation.
- Shadow output cannot write Vendor, Item, PO, receipt, or stock data.
- Profile export/import excludes protected sources, credentials, and installation-specific IDs.
- Permissions, responsive Admin UI, and effective-policy explanations.

## Documentation

- Storage Knowledge for manual profile administration, fixtures, preview, shadow, activation, and
  rollback.
- Developer contract for canonical schema and DSL versions.
- Operations notes for protected fixtures and profile distribution.
- TODO and human-review updates after implementation.

## Done Criteria

- [x] A non-AI admin can build and test a working supplier profile without source-code changes.
- [x] Profile versions are immutable, reproducible, fixture-tested, and reversible.
- [x] Deterministic extraction produces evidence-backed canonical data or clear exceptions.
- [x] The seeded Itegra example is represented only by declarative profile data and a synthetic protected fixture.
- [x] No parser can execute code or make network requests.
- [x] Focused profile, UI, migration, permission, and no-side-effect coverage is implemented on Dev.
- [x] Documentation and the stable human-review entry are current.
- [ ] Add three to five protected real Itegra email fixtures and complete real-template shadow calibration.
- [ ] Complete the named human review in `HR-2026-08-04-003`.
