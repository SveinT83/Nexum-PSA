# ADR: CloudFactory Cost Normalization And Ownership

Status: Superseded
Date: 2026-07-21
Decision Makers: Svein Tore / Codex
Superseded by: `docs/adr/2026-07-21-cloudfactory-service-variant-identity.md`

## Context

CloudFactory catalogue prices are source term totals. A price for a twelve-month commitment may be
returned as the full annual amount even when CloudFactory bills monthly. Nexum Services and contract
profitability calculate amounts per their billing interval and currently read linked records from the
Commercial Costs catalogue. Storing the raw CloudFactory value only in `services.cost_price` therefore
both bypasses Nexum's existing Cost logic and can overstate a monthly cost by twelve times.

Several CloudFactory offers may share one SKU while differing by commitment and billing term. Linking
all offer costs to the same Service would make Nexum add mutually exclusive variants together.

## Decision

Integration retains the raw CloudFactory cost and MSRP on each source offer. It also creates one
externally managed Commercial Cost per offer. The managed Cost contains the normalized amount used by
Nexum, source identity, currency, Vendor, unit, recurrence, and a reference back to the exact offer.

Normalization converts the source commitment total to Nexum's supported commercial interval:

- one-time offers retain the raw amount;
- monthly Services use `raw price * 1 / commitment months`;
- yearly Services use `raw price * 12 / commitment months`.

For example, a NOK 896.64 annual MSRP with monthly billing becomes NOK 74.72 per month. A three-year
term billed annually becomes one third of the raw term total per year. Raw prices are never discarded.

One offer is the default commercial variant for a Service. Only that offer's managed Cost is linked
through `cost_relations`, so existing package, quote, contract, and profitability calculations see
one provider cost and never sum alternative commitment variants. Other offer Costs remain available
and synchronized without entering the Service total. Changing the default variant replaces the
provider-managed relation but preserves all manually maintained internal Cost relations.

Provider-managed Costs are read-only in the ordinary Costs UI. CloudFactory synchronization owns
their amount and source metadata. Manual Nexum Costs remain editable and continue to work unchanged.

## Rationale

This reuses Commercial's established Cost catalogue and calculation paths while preserving provider
source truth. A single default relation gives ordinary Services a deterministic price and cost, and
offer-level records retain the detail needed for exact commitment variants without double counting.

## Consequences

- A migration adds generic source-management metadata to Costs and a Cost reference/default flag to
  CloudFactory offers.
- Catalogue synchronization may create or update managed Costs.
- The Costs list shows source, currency, and managed state.
- Manual edit and delete operations reject externally managed Costs.
- Existing manual Costs and relations are not changed.
- Contract offer selection remains the exact source for licence provisioning; accepted price and
  binding snapshots are not rewritten by later catalogue changes.

## Alternatives Considered

- Continue using only `services.cost_price`: rejected because current Nexum profitability ignores it.
- Attach every offer Cost to one Service: rejected because alternative commitments would be added.
- Create a duplicate Service for every commitment variant: rejected because the approved catalogue
  model allows one canonical Service with several source offers.
- Overwrite raw provider prices with normalized values: rejected because reconciliation and audit
  require the original term totals.

## Follow-Up

Keep the CloudFactory human review open until live Business Basic variants confirm raw and normalized
amounts, the Costs list, Service profitability, and default-variant switching.
