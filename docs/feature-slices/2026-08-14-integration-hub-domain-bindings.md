# Feature Slice: Integration Hub Domain Bindings

Status: Approved
Date: 2026-08-14
Parent: GitHub #218
Owner: Svein / Codex

## Goal

Create canonical, explicit domain-to-installation/Client/Site/Integration bindings for later provider
inspection.

## Routes And Behavior

Bounded domain list/get supports exact normalized hostname, Client, Site, lifecycle, environment,
and Integration filters. Ambiguous, orphaned, inactive, stale, or conflicting mappings return safe
`unknown`/`unavailable` envelopes and are never resolved heuristically.

## Data Touched

New domain mapping table with normalized hostname, Unicode display value where available, explicit
ownership, environment, provider reference, lifecycle, verification and freshness. Migration starts
empty; no inferred backfill. Rollback removes only this table.

## Permissions And Isolation

`integration-hub.domains.read` plus effective Client/Site/Integration and installation scope.
Hostnames are customer data. Cross-scope lookup does not reveal existence.

## Tests

Normal mapping, duplicate/conflict, orphan, inactive, stale, malformed, case/trailing dot,
IDN/punycode, wrong installation/Client/Site, permissions, pagination, redaction, and empty state.

## Documentation

Provider ownership ADR, Clients/Integration/API/security/Knowledge docs, and human review.

## Done Criteria

- [ ] Canonical normalization is deterministic.
- [ ] Every provider-resolvable domain has explicit ownership.
- [ ] Unknown ownership fails closed without guessing.
