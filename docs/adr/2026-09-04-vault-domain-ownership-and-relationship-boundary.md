# ADR 1: Vault Domain Ownership and Relationship Boundary

Scope update (2026-10-01): the PSA-owned MCP purpose is superseded by
[Separate NexumMCP Ownership](2026-10-01-separate-nexummcp-ownership.md).
The remaining PSA credential/security contract and the historical decision below
are retained. PSA Secrets/Vault is not a future PSA MCP-server backend.

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFC: #279

## Context

Nexum currently stores credentials in several provider- and domain-specific locations. Earlier architecture decisions assign external credentials to Integration because no dedicated secret domain existed. The Vault RFC introduces a complete Nexum-native credential platform that must also serve technicians, customers, Connections, Tools, AI, and Automation without duplicating secret ownership.

A durable boundary is needed so that Vault does not absorb provider logic, Integration does not remain a competing secret store, and Client, Asset, WordPress, Email, and other domains do not create their own credential implementations.

## Decision

Create the singular Vault module as the sole target authority for Nexum-managed secret material, secret versions, secret-field schemas, access grants, flat collections, Vault access groups and their memberships, authorization decisions, customer publication, lifecycle, protected search, recovery, and Vault-specific audit.

UserManagement remains authoritative for user identity, exact persisted `PENDING_INVITE|ACTIVE|DISABLED` state, passwords, 2FA/passkey enrollment, ordinary session security, and general roles/permissions. Only the exact `ACTIVE` state can contribute a human Admin/Superuser Vault candidate. Vault references those current facts. Vault-owned `vault_access_groups` are narrow authorization objects whose memberships directly change Vault access; they are not general Nexum groups and do not duplicate user profiles or role membership. A later adapter for external or general groups requires its own ADR.

Because UserManagement role, permission, TOTP, and status mutations can change the effective Vault
approver pool, those mutations must cross one Vault-owned authority-mutation guard whenever Vault
authority is affected. UserManagement still performs and owns the identity mutation; Vault owns the
locked before/after pool decision, its approval requirement, epoch invalidation, and audit. Generic
role editors cannot mutate migration-managed Vault permissions around this boundary.

Integration continues to own Connectors, Connections, endpoints, provider capabilities, transport policy, connection health, the Connection Broker, and external-vault adapters. A Connection references an exact Vault Item or permitted selector; it never owns a second secret value.

Client, Site, Asset, WordPress, Service, Contract, Domain, Network, Email, and other domains own their business records and domain permissions. They expose typed relationships to Vault and may show Vault-owned shortcuts or summaries. They do not render or persist an independent secret editor.

AI, Agent, Tool, Script, Automation, API, and MCP surfaces receive safe metadata, opaque identifiers, or a bounded use-without-reveal capability. They do not become secret owners.

Every Vault Item is organizational business data. A grant limited to one technician is not personal ownership. Deactivating a technician removes access but never orphans or deletes the item.

Existing credential stores remain compatibility sources until migrated individually. For each source, the approved migration defines preview, backfill, verification, cutover, rollback window, and purge. After cutover, callers use Vault and fail closed; they do not silently fall back to legacy ciphertext.

Older accepted ADRs are not rewritten. Statements assigning credentials permanently to Integration become compatibility-only for sources not yet migrated and are superseded for each source at its verified Vault cutover. Their endpoint, transport, health, validation, and provider-lifecycle controls remain valid.

## Rationale

This gives Nexum one auditable secret lifecycle while preserving the domain architecture: Vault owns the secret, Integration owns external communication, and each business domain owns its own records and actions. It also makes Vault useful independently of AI or Vaultwarden and avoids a second Passportal-like data silo.

## Consequences

Positive:

- One source of truth for secret versions, Vault access-group membership, access decisions, recovery, and audit.
- Credentials can be related to any supported record without duplicating storage.
- Connection and AI runtimes can use secrets through one security boundary.
- Technician offboarding cannot strand business credentials.
- Legacy migration can proceed source by source without pretending the old boundary already changed.

Negative:

- Every current secret location and caller must be inventoried.
- Cross-domain relationship, identity-state, and permission checks are required.
- Vault access-group membership changes require the same step-up, approval, and audit discipline as other access-expanding grants.
- UserManagement changes that affect Vault authority require a cross-domain locked decision and may
  be denied while the Vault approver pool cannot be changed safely.
- Existing Integration and Email code must temporarily support explicit compatibility paths.
- The migration cannot be completed through documentation changes alone.

## Alternatives Considered

- Keep all credentials in Integration. Rejected because customer-facing items, record relationships, full lifecycle, recovery, and Passportal replacement are broader than connector configuration.
- Let each domain own its secrets. Rejected because encryption, access, rotation, audit, runtime use, and recovery would drift.
- Copy secrets into Vault while providers keep their originals. Rejected because two authorities create silent divergence and unsafe rotation.
- Make Vault only an AI secret broker. Rejected because the approved product is a complete credential platform.

## Follow-Up

- Inventory every secret source and dependent runtime.
- Add the Vault module only through approved Feature Slices.
- Update affected ADRs by superseding them at actual source cutover, never retroactively.
- Add typed relationship contracts and negative cross-Client tests.
- Keep Vault access groups separate from general UserManagement roles/groups; introduce any external/general-group adapter only through a later ADR.
- Document the compatibility and cutover state of every provider source.
