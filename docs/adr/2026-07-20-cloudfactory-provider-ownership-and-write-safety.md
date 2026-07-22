# ADR: CloudFactory Provider Ownership And Write Safety

Status: Accepted
Date: 2026-07-20
Decision Makers: Svein Tore / Codex

## Context

CloudFactory exposes production-only partner APIs through a Portal-user refresh token. It does not
offer OAuth client registration, PKCE, scopes, or a sandbox. Nexum must support two-way customer,
catalogue, licence, contract, and billing workflows without storing a Portal password or allowing
ambiguous retries to provision twice.

## Decision

The Integration module owns the CloudFactory connection, encrypted token lifecycle, external links,
normalized provider records, synchronization runs, conflicts, and an idempotent operation ledger.
Client, Documentation, Commercial, Contract, and Economy remain owners of their domain records.

A dedicated least-privilege CloudFactory Portal service account is required. Nexum accepts only its
refresh token in a write-only masked field, exchanges it with the current JSON-body endpoint, caches
the short-lived access token encrypted, and never stores passwords or MFA material. Disconnect uses
the bearer-authenticated `RevokeAllTokens` operation; the legacy token-in-URL operation is forbidden.

Polling and reconciliation are authoritative until CloudFactory documents webhook signing and replay
protection. Provider writes require an active contract, the relevant CloudFactory role, an enabled
write setting, and during initial validation an allowlisted fictitious Nexum Client. Every write is
represented by a durable operation fingerprint before it is sent.

Catalogue offers are staged separately from Nexum Services. Enabling an offer creates or links a
normal Service, Vendor, cost, MSRP, sale policy, and source metadata. Active subscriptions are always
visible even when the catalogue offer is excluded from resale.

## Rationale

This keeps secrets and provider semantics in Integration while preserving existing Nexum ownership,
permissions, contracts, and Economy behavior. The operation ledger and reconciliation state make
production-only retries observable and safe.

## Consequences

- CloudFactory setup needs a dedicated Portal identity and manual one-time token transfer.
- A disabled or missing role degrades only the affected capability.
- Webhook latency is replaced by scheduled polling until its security contract is known.
- External state is never deleted when a Nexum catalogue offer is hidden.
- The first live write is intentionally limited to an explicitly allowlisted fictitious Client.

## Alternatives Considered

- External OAuth client with PKCE: unsupported by CloudFactory.
- Portal password or browser automation: rejected because Nexum must not hold interactive credentials.
- Provider records as the domain source of truth: rejected because contracts and billing remain Nexum-owned.
- Immediate webhooks: rejected until signing and replay behavior are documented.

## Follow-Up

Implement the approved Feature Slices, keep the runtime human review open, and create a new ADR if
CloudFactory later introduces external OAuth clients or a verified webhook contract.
