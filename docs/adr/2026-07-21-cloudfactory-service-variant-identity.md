# ADR: CloudFactory Service Variant Identity

Status: Accepted
Date: 2026-07-21
Decision Makers: Svein Tore / Codex
Supersedes: `docs/adr/2026-07-21-cloudfactory-cost-normalization-and-ownership.md`

## Context

CloudFactory publishes several offers with the same provider SKU while commitment and billing terms
differ. The earlier design linked these offers to one canonical Nexum Service and selected one default
variant for ordinary Service pricing. This left the Service identity ambiguous when multiple variants
were available for sale and made it too easy to select a commitment that did not match the chosen
Service.

Nexum must issue, contract, cost, price, reconcile, and bill the exact provider commitment variant.

## Decision

Every distinct CloudFactory offer variant has its own Nexum Service. Automatic Service SKUs use the
provider SKU followed by deterministic commitment and billing suffixes:

- `CFQ7TTC0LH18:0001-C1-B1` for monthly commitment and monthly billing;
- `CFQ7TTC0LH18:0001-C12-B1` for annual commitment and monthly billing;
- `CFQ7TTC0LH18:0001-C12-B12` for annual commitment and annual billing.

`CX` or `BX` represents a missing provider term. If the generated SKU is already owned by a
different CloudFactory offer, Nexum appends a stable short offer identifier rather than merging the
offers.

One CloudFactory offer may link to one Service, and one Service may link to at most one CloudFactory
offer. A database uniqueness constraint enforces the Service side of this relationship.

The offer's managed Commercial Cost is always linked to its dedicated Service. Manual Costs may still
be linked to that Service and are added normally. There is no default-variant switch.

Selecting a Service on a contract automatically stores its one CloudFactory offer and snapshots the
exact cost, sale price, currency, commitment, and billing terms. Licence issue and provider
reconciliation require that exact Service and offer pair.

## Rationale

Separate Services make commitment variants visible and unambiguous throughout Services, contracts,
licences, Costs, profitability, and Economy. The deterministic SKU is searchable and stable across
catalogue refreshes. One-to-one database ownership prevents accidental cross-variant pricing or
licence issue.

## Consequences

- The catalogue creates a separate Service for every enabled or actively subscribed offer.
- Service selectors distinguish otherwise identical product names by the generated SKU.
- The catalogue no longer exposes a default-variant control.
- The contract editor no longer needs a second commitment selector after Service selection.
- Each Service has exactly one provider-managed Cost relation.
- Existing exact offer references on contract and licence lines remain the provider source of truth.
- Manual linking may target only a Service that is not linked to another CloudFactory offer.

## Alternatives Considered

- One canonical Service with a selectable offer: superseded because Service pricing and commitment
  identity remain ambiguous outside the contract editor.
- One Service with all managed Costs: rejected because mutually exclusive variants would be summed.
- SKU-only identity without term suffixes: rejected because the provider reuses one SKU across
  commitment and billing combinations.

## Follow-Up

Keep `HR-2026-07-20-001` open until all three Business Basic variants create separate Services,
Costs, contract lines, and safe allowlisted licence operations on Dev.
