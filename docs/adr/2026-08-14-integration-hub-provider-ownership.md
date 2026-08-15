# ADR: Integration Hub Domain And Provider Ownership

Status: Accepted
Date: 2026-08-14
Decision Makers: Svein / Codex
Related: GitHub #212, #218, #219, #220

## Context

The existing Integration record stores provider configuration and encrypted secrets but has no
explicit owner, Client/Site, environment, capability binding, or common health contract. ClientSite
does not contain a canonical hosting-domain model. Guessing ownership from DNS, hostnames, or
provider searches would risk cross-customer disclosure.

## Decision

The existing `integrations` table remains the provider-connection source of truth and gains explicit
owner scope (`internal`, `installation`, `client`, or `site`), installation key, optional Client and
Site, environment, health observation, and emergency-disable metadata. Secrets remain encrypted on
the existing model and are never serialized by Integration Hub resources.

Integration owns `integration_hub_domains` as the canonical mapping between normalized hostname,
installation, Client, Site, Integration, environment, and provider object reference. A hostname is
lowercase ASCII/IDNA, has no trailing dot, and is unique within installation/environment. Invalid,
duplicate, orphaned, inactive, or conflicting mappings return `unknown` or `unavailable`; they are
never guessed. Provider references are identifiers, not credentials.

Plesk is implemented behind a typed, read-only adapter. The adapter receives a resolved authorized
Integration and domain binding, then calls only allowlisted Plesk read endpoints with bounded
timeouts, response limits, and no mutation methods. Provider credentials remain in Nexum's encrypted
Integration secrets. The external API returns normalized subscription, domain/alias, hosting,
runtime, and certificate observations through the shared result envelope.

The first slice uses one installation scope because Nexum currently has no tenant/organization
table. `INTEGRATION_HUB_INSTALLATION_KEY` is therefore the stable organization boundary. Every new
row carries it, every query filters it, and future multi-tenant extraction can replace the value
without weakening current isolation.

## Consequences

- Existing provider integrations remain valid through nullable/defaulted ownership fields.
- Explicit records are required before a provider object can be inspected.
- Health is unknown until a fresh observation exists; configuration alone is not health.
- Manual non-production provider verification remains a human-review gate.

## Alternatives Considered

- Put domains in `client_sites` as one text field. Rejected because aliases, environments, provider
  references, lifecycle, and observation evidence require first-class records.
- Search Plesk and infer the Client. Rejected because ambiguous ownership must fail closed.
- Return raw provider responses. Rejected because schema drift and secret-bearing errors would leak.

## Follow-Up

Implement ownership/domain/catalogue APIs, the Plesk adapter, migration/backfill validation,
operations documentation, and manual read-only review.
