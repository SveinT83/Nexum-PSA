# Feature Slice: CloudFactory Service Variant Services

Status: Done
Date: 2026-07-21
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
ADR: `docs/adr/2026-07-21-cloudfactory-service-variant-identity.md`
Owner: Codex

## Goal

Create one Nexum Service and deterministic Service SKU for every distinct CloudFactory commitment and
billing variant before live licence issue is enabled.

## User-Visible Behavior

Enabling otherwise identical offers creates separate Services such as `...-C12-B1` and
`...-C12-B12`. Choosing the Service in a contract automatically chooses the exact provider offer;
there is no separate default-variant or commitment selector.

## Scope

Variant SKU generation, one-to-one offer/Service ownership, managed Cost ownership, catalogue
controls, contract snapshots, licence guards, provider reconciliation, schema constraint, tests,
Knowledge documentation, and human review.

## Out Of Scope

Cross-provider canonical product grouping, automatic migration of already issued provider licences
between commitment variants, and foreign-exchange conversion.

## Data Touched

`cloudfactory_offers.service_id`, generated `services.sku`, Cost relations, contract offer
snapshots, catalogue controls, and licence matching.

## Permissions

Existing Integration and Commercial permissions remain unchanged.

## Tests

Variant SKU generation, separate Service and Cost ownership, one-to-one database enforcement,
contract automatic offer snapshots, exact licence matching, catalogue controls, and regressions.

## Documentation

CloudFactory RFC, replacement ADR, CloudFactory Knowledge, TODO, and human review.

## Done Criteria

- [x] Different commitment/billing combinations create separate Services.
- [x] Generated Service SKUs are deterministic and unique.
- [x] One Service cannot be linked to two CloudFactory offers.
- [x] Each Service receives only its offer's managed Cost.
- [x] Contract selection snapshots the Service's exact offer automatically.
- [x] Licence issue requires the exact Service/offer contract line.
- [x] Default-variant controls and behavior are removed.
- [x] Relevant automated tests pass on Dev.
- [x] Knowledge and human-review records are updated.

## Verification

Migration `2026_07_21_190000_enforce_cloudfactory_service_variants` completed on Dev. The focused
CloudFactory, Commercial, Sales, and Economy run passed 90 tests and 842 assertions. Coverage includes
separate Services and managed Costs for shared provider SKUs, deterministic `-C{term}-B{term}`
suffixes, database one-to-one enforcement, automatic contract offer snapshots, exact licence guards,
and inbound subscription matching by commitment and billing. Blade compilation and PHP formatting
passed. Human review remains open under `HR-2026-07-20-001`.
