# Feature Slice: CloudFactory Licence, Contract And Billing

Status: Done
Date: 2026-07-20
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
Owner: Codex

## Goal

Operate Microsoft and Adobe licences from each Client while preserving contracts, commitments, and
Economy billing.

## User-Visible Behavior

The Client licence workspace shows provider state and allows supported add, increase, decrease,
renewal, suspension, or cancellation actions. A suitable active contract is mandatory for automatic
issuance. Direct CloudFactory/customer-portal changes reconcile back into Nexum.

## Scope

Normalized subscriptions, provider adapters, operation ledger, contract policy gates, append-only
amendments, effective dates, commitment data, billing periods, and Economy draft order lines.

## Out Of Scope

Automatic MCA acceptance; the customer must use CloudFactory's attestation link when required.

## Data Touched

Contracts, contract items, subscriptions, operations, amendments, billing periods, and Economy orders.

## Permissions

Client licence view, integration licence write, Commercial contract control, and Economy generation.

## Tests

Contract gates, idempotency, provider adapters, portal reconciliation, commitment rules, effective
price/quantity, and recurring Economy output.

## Documentation

Client licensing, MCA, renewal, cancellation, and billing runbooks.

## Done Criteria

- [ ] No automatic issue without an eligible contract.
- [ ] Billing changes only after provider confirmation.
- [ ] Repeated writes and billing runs are idempotent.
