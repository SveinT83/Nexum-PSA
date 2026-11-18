# ADR: Explicit identity choices during client registration

Status: Accepted for Dev implementation
Date: 2026-11-18
Decision makers: Svein Tore (product scope), Codex (implementation)

## Context

Billing and contact email, phone numbers and organization numbers can identify existing records in Nexum and Tripletex. Family members and organizations can share contact details. Registering another provider customer has accounting and reconciliation consequences.

## Decision

Use a shared read-only Clients query for both interfaces. Match normalized complete identifiers and expose authorized customer choices. Group matches by existing provider link and local identity. Return the action and matched field names. Retain Contact authority for private channels. Resolve an occupied provider number or unresolved identity through the existing reconciliation workflow.

On the public API a successful HTTP 200 selection response can precede the existing HTTP 201 creation flow. An idempotent registration stores the selection outcome without contact values. Replays recheck the caller's access before exposing current choices. Explicit provider selection continues through the existing identity validation, lock, command and number-authority code.

## Rationale

Live, company-verified, bounded provider reads keep suggestions consistent with the current connection. A candidate is evidence for a human or API consumer to select an identity. Opening a local client and pairing an existing provider customer are distinct actions.

## Consequences

No migration or additional copy of contact channels is required. The provider lookup depends on availability and can cost several pages for large companies. Debouncing, rate limiting and complete identifiers bound interactive calls. A remote lookup failure stops the registration check.

## Alternatives considered

An indexed provider directory would require freshness, retention and company isolation controls. Name matching alone cannot disambiguate people. Merging separate local records would require decisions about contracts, tickets and history and is outside the approved scope.

## Follow-up

Use HR-2026-11-18-CLIENT-REGISTRATION-SUGGESTIONS for manual acceptance and the related RFC for scope. Review lookup latency with the intended operator before production promotion.
