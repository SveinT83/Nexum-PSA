# Feature Slice: CloudFactory Services And Pricing

Status: Done
Date: 2026-07-20
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
Owner: Codex

## Goal

Turn selected CloudFactory offers into ordinary Nexum Services with controlled dynamic pricing.

## User-Visible Behavior

Administrators can enable or exclude offers, link Services, filter and sort Services by Vendor and
source, see each offer's commitment and billing cadence, and choose MSRP, MSRP markup, cost markup, or manual pricing with monthly automatic refresh.

## Scope

Offer staging, Service/Vendor links, cost/MSRP retention, price policy hierarchy, overrides, currency,
price history, and monthly schedule settings.

## Out Of Scope

Unapproved currency conversion and deletion of Services with active contracts.

## Data Touched

Services, Vendors, source offers, pricing settings, and price history.

## Permissions

Commercial managers control sale prices; integration managers control source synchronization.

## Tests

All price formulas, overrides, exclusion, Service creation, source visibility, sorting, and filtering.

## Documentation

Pricing policy and catalogue administration Knowledge.

## Done Criteria

- [x] Cost and MSRP remain distinct.
- [x] Manual prices are never overwritten.
- [x] Active subscriptions remain visible when resale is excluded.
- [x] Vendor and source remain separate on offers and generated Services.
- [x] Commitment and billing variants remain visible for otherwise identical offers.

## Verification

The CloudFactory feature suite covers canonical Vendor reuse, generated Service ownership, source,
and dynamic pricing. The live Dev catalogue backfill retained distinct cost and MSRP for 10,898 offers.

The live Microsoft 365 Business Basic data also confirmed monthly/monthly, annual/monthly, and
annual/annual commitment and billing variants for the same SKU.
