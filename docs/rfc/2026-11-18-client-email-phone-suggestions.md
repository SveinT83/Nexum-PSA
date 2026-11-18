# RFC: Suggest an existing customer by email, phone or organization number

Status: Approved by Svein Tore, 2026-11-18
Date: 2026-11-18
Owner: Clients / Svein Tore
Related: approved Tripletex explicit selection and Clients API parity RFCs.

## Context and goals

When staff enter an email address, telephone number or organization number during registration, show matching
customers before saving. The official API must offer the same discovery and selection
workflow, including when its caller submits registration before searching.

## Current behavior

The existing picker searches Tripletex name, organization number and customer number. A caller
can explicitly send the provider's internal customer ID. The duplicate preflight can stop a new
registration on name or invoice email. It does not provide phone/email candidate selection.

## Proposed change

In New Client, debounce lookup as staff enter a complete email or phone. Group results by Nexum
Client and verified Tripletex identity. Include authorized Client billing email and linked Contact
channels, and provider customer/invoice email and telephone fields. Use exact normalized email
and phone matches (country-aware, no arbitrary suffix match). Return the source and matched
field, enough masked identity to distinguish candidates, local customer number, and allowed
actions. Never disclose another customer's contact data outside current access.

Offer Use existing to open or return an existing local Client, or use an unlinked provider
customer to create one Nexum Client. Where existing local and provider records need to be
paired, show both records, conflicting addresses and numbers, and the proposed field choices.
Require explicit confirmation and existing link/adopt management rights. Keep canonical Contact
identity separate from Client identity. Shared switchboards, family emails and multiple matches
require selection. Customer-to-customer consolidation of two established Nexum Clients has
separate effects on contracts, time, tickets and audit history and needs an explicit decision.

## API contract

Provide a typed authenticated candidate lookup using a request body for personal search terms.
The registration negotiation returns a documented successful selection_required outcome containing
authorized candidates and allowed choices, with no customer write. Support use_existing,
link_provider and explicitly reviewed reconcile actions on the browser and API. Preserve legacy
Client response contracts through versioning or negotiated support. Recheck identity, permission,
scope, data revision and number availability before commit; keyed replay returns the original
result. Stale identity, permission denial and unknown provider outcomes retain truthful errors.

## Impact, data and migration

Clients owns the UI and typed API, Contact owns canonical channels, Integration supplies bounded
verified provider reads, DataExchange owns provider identity and reconciliation. Review provider
search capability, privacy, quotas and paging before choosing a cached index. No bulk backfill,
schema change or provider synchronization is approved by this draft. Decide retention, encryption,
permissions and invalidation in an ADR if a provider index becomes necessary.

## Testing and documentation

Verify unique and ambiguous email/phone matches, country formats, shared family/company details,
inactive records, local-only/provider-only/already-linked results, changed addresses, permission
and token boundaries, no-write suggestions, concurrency, replay and unknown outcomes. Exercise
the real UI, official API, Swagger and any published MCP contract. Update employee Knowledge and
BookStack and verify read-back before release.

## Approval

Svein Tore approved explicit reuse of a Nexum Client or linking an existing Tripletex customer on 2026-11-18 and included organization-number matching in both interfaces. Consolidation of two local Clients is outside this workflow.
