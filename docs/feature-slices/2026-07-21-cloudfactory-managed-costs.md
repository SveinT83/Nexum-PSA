# Feature Slice: CloudFactory Managed Costs

Status: Done (default-variant ownership superseded)
Date: 2026-07-21
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
Owner: Codex

The normalization and integration-managed Cost work remains active. The later one-Service-per-offer
decision supersedes this slice's references to a default offer sharing one Service; see
`docs/adr/2026-07-21-cloudfactory-service-variant-identity.md`.

## Goal

Make CloudFactory purchase prices participate in Nexum's existing Cost and profitability logic with
the correct amount per supported billing interval.

## User-Visible Behavior

CloudFactory offers show both the raw source term price and the normalized Nexum price. Enabling an
offer creates a read-only CloudFactory Cost. The default offer for a Service controls its provider
Cost, sale price, and billing interval. Alternative commitment variants are not added together.

## Scope

Offer normalization, externally managed Cost records, one default offer per Service, safe Cost UI,
Service cost relations, and regression coverage for monthly and annual commitment combinations.

## Out Of Scope

Foreign-exchange conversion when CloudFactory is not already in the configured Nexum currency,
provider invoice reconciliation, and support for a Commercial recurrence shorter than monthly or
longer than yearly.

## Data Touched

`costs`, `cost_relations`, `cloudfactory_offers`, Services derived pricing, catalogue UI, and
Commercial profitability consumers.

## Permissions

Existing Integration permissions control source offer settings. Existing Commercial permissions
continue to control manual Costs. Externally managed Costs cannot be edited or deleted manually.

## Tests

Offer normalization, managed Cost lifecycle, relation replacement, no double counting, read-only Cost
operations, catalogue labels, and existing Commercial calculation regression tests.

## Documentation

CloudFactory RFC, pricing ADR, CloudFactory Knowledge, Cost Knowledge, TODO, and human review.

## Done Criteria

- [x] Raw cost and MSRP remain unchanged on the offer.
- [x] The managed Cost uses the normalized Service billing interval.
- [x] Only the default offer contributes provider cost to a Service.
- [x] Manual Cost relations remain intact.
- [x] Provider price sync updates the same managed Cost.
- [x] Managed Costs are visibly read-only.
- [x] Commercial and CloudFactory tests pass on Dev.

## Verification

Migration `2026_07_21_180000_add_cloudfactory_managed_costs` completed on Dev. The CloudFactory
suite passed 25 tests and 268 assertions; Commercial passed 32 tests and 272 assertions; Sales and
Economy passed 32 tests and 289 assertions. Blade compilation passed. Automated regressions cover
annual/monthly normalization, default-variant relation replacement, preservation of manual Costs,
read-only managed Costs, exact contract offer snapshots, server-side protection of authoritative
cost snapshots, offer-specific subscription pricing, catalogue term filtering and sorting, and the
intentional omission of redundant catalogue source identity. The previous complete Dev suite passed
849 tests and 6,378 assertions.
Commercial and Integration Knowledge synchronization processed two chapters and eleven articles,
and the queued BookStack push completed without a failed job.
