# ADR: Integration Hub Service Identity And Execution Grants

Status: Accepted
Date: 2026-08-14
Decision Makers: Svein / Codex
Related: GitHub #212, #214

## Context

The MCP access token must not be forwarded to Nexum or a provider. Nexum already supports Sanctum
service tokens, protected system actors, and workload-token bindings, but a long-lived bearer token
alone cannot safely represent one delegated actor, capability, and target.

## Decision

The MCP service authenticates to Nexum with a dedicated non-login system actor and a narrowly scoped
Sanctum token carrying `integration-hub.service`. Interactive users and approved workloads mint a
separate short-lived execution grant through Nexum after ordinary role, token, record, workload,
privacy, capability, and emergency-control checks.

The compact grant is signed with HMAC-SHA-256 using a dedicated environment key ring, never
`APP_KEY`. It carries issuer, audience, key ID, issued-at, not-before, expiry, unique grant ID,
correlation ID, installation scope, actor/workload identity, capability key/version, Client/Site,
Integration, environment, and a digest of the evaluated policy. The active key signs new grants;
configured non-retired keys verify during rotation overlap. The maximum lifetime is five minutes
and grants cannot be refreshed.

Every Integration Hub service call requires both the dedicated service token and the signed grant.
Central middleware validates signature, issuer, audience, time bounds, revocation epoch, one-time
grant ID, exact route capability/version, target scope, service identity, and current emergency
controls. Successful verification consumes the grant ID transactionally, providing replay
protection. Grant issuance is rate-limited and may only narrow the caller's current access.

OAuth protected-resource metadata advertises the Integration Hub audience and authorization server
boundary. Implementing a replacement interactive authorization server is outside this slice; the
existing Nexum authenticated session/Sanctum boundary mints grants. Future public MCP authorization
can add PKCE without changing the downstream grant contract.

## Consequences

- The MCP bearer token is never accepted as a Nexum service credential.
- Service-token compromise does not provide a usable delegated operation without a valid grant.
- Grant signing keys must be provisioned and rotated through environment configuration.
- One-time grants require a new grant for each API operation, simplifying replay analysis.
- Missing key configuration makes issuance and verification unavailable, never permissive.

## Alternatives Considered

- Forward the MCP token. Rejected because audiences and compromise boundaries would collapse.
- Store grants as long-lived bearer tokens. Rejected because revocation and target narrowing weaken.
- Sign with `APP_KEY`. Rejected because grant-key rotation must be independent.
- Add a full OAuth server now. Rejected because it would replace established login behavior without
  a separately approved migration and is not required for the private first slice.

## Follow-Up

Implement the grant slice, operational key-rotation runbook, redaction tests, and human review.
