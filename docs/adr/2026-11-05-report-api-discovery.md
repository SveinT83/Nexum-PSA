# ADR: Domain-owned report API descriptors
Status: Accepted
Date: 2026-11-05
Decision Makers: Codex under Svein Tore's approved reporting API RFC

## Context
The Report registry exposed only UI links although domain report APIs already exist.
## Decision
Add optional ReportApiDefinition descriptors; domains provide paths, parameters and scopes.
ReportEntry transports these to metadata. No generic execution runner or inferred grant.
The customer API uses its existing query/export with dedicated opt-in native read/export abilities.
## Rationale
Preserves calculation ownership and current runtime/actor checks, while making supported APIs discoverable.
## Consequences
Metadata remains backward compatible. Descriptors are documentation, not capability activation.
Identified customer reporting does not replace coordinator governance or grant provider access.
## Alternatives Considered
A global execution engine duplicates domain policy and is beyond this scope.
## Follow-Up
RFC 2026-11-05-client-time-report-api; HR-2026-11-05-CLIENT-TIME-REPORT-API.
