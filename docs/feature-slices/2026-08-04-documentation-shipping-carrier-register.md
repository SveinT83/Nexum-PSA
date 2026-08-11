# Feature Slice: Documentation Shipping Carrier Register

Status: Done
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-purchase-orders-shipping-receiving.md`
Owner: Codex

## Goal

Provide the structured carrier master data and safe tracking-link contract required before Storage
can expose shipment tracking.

## User-Visible Behavior

Technicians can open a fixed Shipping Carriers register from the Documentation sidebar. Authorized
users can create and edit carrier identity, lifecycle, services, official URLs, tracking method,
allowed hosts, link visibility, connector identifier, official source, and verification state.

The register distinguishes active, legacy, and inactive profiles. Storage can later turn a tracking
number into the safest available browser link without rendering an unsafe or mismatched URL.

## Scope

- Documentation-owned carrier schema and model.
- Fixed list, create, show, and edit routes and Bootstrap views.
- HTTPS, host-allowlist, and exact-placeholder validation.
- Current-carrier and immutable-snapshot-compatible tracking-link resolution.
- Idempotent seed-missing-only profiles for common deliveries into Norway.
- Documentation sidebar discoverability.
- Dedicated feature and resolver tests.
- Knowledge documentation and ownership ADR.

## Out Of Scope

- Purchase-order and shipment records.
- Receiving or inventory movements.
- Carrier credentials, booking, labels, status polling, or webhooks.
- Server-side requests to carrier tracking URLs.
- Deleting carrier history; lifecycle state is used instead.

## Data Touched

- New `shipping_carriers` table.
- Optional relation to canonical `vendors`.
- Nullable `created_by` and `updated_by` audit actors.
- New `ShippingCarrierSeeder`, registered by the integration handoff.

## Permissions

- `documentation.view` permits list/show access.
- `documentation.carrier_manage` permits create/store/edit/update access.
- Route mapping and default role grants are integrated in the shared permission files.

## Tests

- Fixed route/controller and sidebar behavior.
- View versus management permissions.
- Successful create/update persistence and audit actors.
- HTTPS and allowed-host enforcement.
- Exactly one tracking placeholder.
- Direct, template, generic, and unsafe resolver outcomes.
- Seeder profile distinctions, idempotency, and administrator-change preservation.
- Blade rendering for list, form, and detail pages.

## Documentation

- Add `app/Modules/Documentation/Docs/knowledge/shipping-carriers.md`.
- Add the cross-module ownership ADR.
- Keep the parent RFC and shared human-review entry as release-level sources of truth.

## Done Criteria

- Migration and seeder run successfully on Dev.
- Fixed routes render and enforce the intended permissions.
- Unsafe tracking configurations are rejected and unsafe links resolve to `null`.
- Seed reruns preserve administrator changes.
- Focused Documentation tests pass on Dev.
- Knowledge and architecture documentation match implemented behavior.
