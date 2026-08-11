# Feature Slice: Storage Managed Automation Actor

Status: Done
Date: 2026-08-10
Parent: `docs/rfc/2026-08-10-simplified-storage-supplier-order-ai.md`
Owner: Svein / Codex

## Goal

Replace the selectable human automation User with one protected Nexum-managed actor.

## User-Visible Behavior

Supplier Order Policy no longer asks for an Automation user. Audit records identify **Nexum
Supplier Order Automation** when unattended Item, profile, or Purchase Order actions are permitted.

## Scope

Add system-actor identity fields, authentication and User Management protections, an idempotent
ensure action, exact direct permissions, Storage resolution, migration, and tests.

## Out Of Scope

Generic service-account management, external service users, or changing manual action attribution.

## Data Touched

`user_management`, Spatie direct permission rows, existing Storage policy actor reference, auth and
User Management query/controller/API behavior.

## Permissions

The actor receives exactly `storage.purchase_manage`, `storage.purchase_import_profile_manage`, and
`documentation.create`. It cannot log in or be managed as a human user.

## Tests

Idempotent creation, exact permissions, login denial, list/API exclusion, mutation denial, policy
save, Item creation, profile learning, and finalization attribution.

## Documentation

RFC, ADR, User Management Knowledge, Storage Knowledge, TODO, and human review.

## Done Criteria

- [x] Normal policy save provisions and references the system actor.
- [x] The actor cannot authenticate or be modified through human-user surfaces.
- [x] Existing automated Storage actions pass with exact audit attribution.
- [x] Focused tests pass on Dev.
