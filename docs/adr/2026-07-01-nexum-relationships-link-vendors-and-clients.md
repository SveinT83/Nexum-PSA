# ADR: Nexum Relationships Link Vendors And Clients

Status: Accepted
Date: 2026-07-01
Decision Makers: Svein Tore Ramstad / Codex

## Context

Discussion #150 and RFC
`docs/rfc/2026-07-01-nexum-relationship-and-vendor-provider-routing.md` need one early architecture
decision: where should remote Nexum relationship configuration live?

Nexum already has:

- Client records for external customers.
- Vendor records for vendors, suppliers, and manufacturers.
- Integration-owned API key management and ability catalog.
- Ticket, Documentation, and Knowledge domain behavior that must remain in those modules.

The relationship plan needs to support both cases:

- We are the provider for a Client that also runs Nexum.
- We use a Vendor or provider that also runs Nexum.

The relationship also needs API tokens, webhook secrets, remote instance identity, capabilities,
routing policy, sync state, conflict state, health, retries, and audit logs. Those fields do not
belong directly on the `vendors` table or directly on Client records.

## Decision

Create an explicit NexumRelationship concept owned by a new singular `Relationship` module.

A NexumRelationship may reference:

- a Client when we are provider for that Client, or
- a Vendor when we use that Vendor as an upstream provider.

Vendor remains master data. Client remains external customer data. NexumRelationship owns the
connection, routing, capability, sync, and health state.

Nexum-to-Nexum product sync uses independent instances connected through scoped API tokens and signed
webhooks. It does not use a shared database, tenant merge, or SSH as the normal data exchange
primitive.

## Rationale

Client and Vendor are business records. A remote Nexum connection is a relationship/integration
state with operational behavior. Mixing those concepts would make it difficult to support multiple
providers, multiple service areas, token rotation, per-capability policy, sync retries, conflict
handling, and health reporting.

A separate relationship record keeps the data model explicit:

- A Client can have zero, one, or several provider relationships over time.
- A Vendor can be a supplier/manufacturer without being a remote Nexum provider.
- A Vendor can become an upstream Nexum provider without adding sync credentials to all vendor
  records.
- Ticket and Documentation can refer to a relationship for routing/sync without moving domain logic
  out of their modules.
- WorkContext can stay focused on local scope, while Relationship handles external exchange.

Using API tokens and signed webhooks keeps the product sync path auditable, permissioned, and
rotatable. SSH may still be useful for server administration, but it should not become the normal
application-level integration primitive.

## Consequences

Positive:

- Relationship sync state does not pollute Client or Vendor master data.
- The model supports both customer-provider and upstream-provider relationships.
- Multiple service areas and multiple providers can be added without redesigning Client or Vendor.
- Credential storage, health, audit, and retries have one owner.
- Ticket, Documentation, Knowledge, and future domains can consume the same relationship identity.
- Public/private sync boundaries are easier to test and document.

Negative:

- A new module and tables are required.
- Client and Vendor profile panels need to query relationship records instead of owning the state
  directly.
- Implementation must coordinate with WorkContext before routing is safe.
- API/webhook security, sync links, and conflict handling add operational complexity.

## Alternatives Considered

- Store remote URL, tokens, capabilities, and sync state directly on `vendors`. Rejected because not
  every vendor is a remote Nexum provider, and customer-provider relationships can reference Clients
  instead.
- Store remote relationship state directly on Clients. Rejected because upstream provider routing
  references Vendors, and Client should stay focused on customer master data.
- Put all relationship behavior in Integration. Rejected because Integration owns generic API
  management conventions, while this relationship is a business workflow that routes Tickets,
  Documentation, Clients, Vendors, and future domains.
- Use shared database access or tenant merging. Rejected because each Nexum installation must own its
  own data and privacy boundary.
- Use SSH as the normal sync primitive. Rejected because product data exchange needs scoped API
  permissions, webhook signatures, audit logs, retries, and token rotation.

## Follow-Up

- Approve or revise RFC
  `docs/rfc/2026-07-01-nexum-relationship-and-vendor-provider-routing.md`.
- Keep RFC `docs/rfc/2026-07-01-work-context-organization-scope.md` as the required local scope
  dependency.
- Implement feature slice `docs/feature-slices/2026-07-01-nexum-relationship-foundation.md` only
  after the RFC is approved and the target environment has the WorkContext foundation.
- Create later ADRs only if sync transport, conflict strategy, or domain ownership changes from this
  decision.
