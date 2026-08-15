# Integration Hub API v1

Status: Ready for human review
Contract: `nexum.integration-hub.result` `1.0`
Related: GitHub #212-#220 and `HR-2026-08-15-001`

## Boundary

The Integration Hub API is Nexum's authoritative, protocol-neutral read boundary for MCP, visual
clients, approved workloads, and future automation. MCP does not own authorization, provider
credentials, durable execution state, or audit state. The first slice is read-only. It contains no
generic HTTP, shell, database, file, DNS, certificate-issuance, or provider-mutation endpoint.

All paths in this document are relative to the Nexum origin. The protected service paths are under
`/api/v1/integration-hub`.

## Authentication and delegation

Protected reads use two distinct credentials:

1. An interactive actor or approved bound workload sends a narrow Nexum Sanctum token to
   `POST /api/v1/integration-hub/grants`. The token must contain
   `integration-hub.grants.issue` and the requested capability ability. Its User must also have the
   descriptor's normal Nexum permission.
2. The MCP service calls exactly one protected read with its separate service token in
   `Authorization: Bearer ...` and the short-lived signed value in
   `X-Nexum-Execution-Grant: ...`.

The service token contains only `integration-hub.service`, is bound to an active approved
coordinator workload and an explicit source-network policy, and never acts as the delegated actor.
The execution grant is audience-, installation-, service-identity-, policy-, scope-, and
capability-bound; it is valid for at most five minutes and can be used once. The inbound MCP bearer
token must never be forwarded as either Nexum credential.

The public protected-resource metadata endpoint is:

`GET /.well-known/oauth-protected-resource/api/v1/integration-hub`

It advertises the configured resource audience and authorization server without exposing keys or
tokens. The private first slice uses existing Nexum sign-in and narrow Sanctum issuer tokens. A
general OAuth authorization-server migration is not part of this slice.

## Grant request

```http
POST /api/v1/integration-hub/grants
Authorization: Bearer <narrow actor or workload token>
Accept: application/json
Content-Type: application/json

{
  "capability_key": "nexum.clients.read",
  "capability_version": "1.0",
  "ttl_seconds": 120,
  "correlation_id": "f30d258d-e188-45b0-8af7-80d67ebf4628",
  "scope": {
    "client_ids": [42],
    "site_ids": [91],
    "integration_ids": [],
    "environment": "production"
  }
}
```

The successful `201` response uses the common envelope. `data.grant` is secret bearer material and
must stay in memory only. Responses include `Cache-Control: no-store` and `Pragma: no-cache`.
Validation errors contain only bounded field names, not reflected input values.

## Capability catalogue

| Capability | Issuer ability | Nexum permission | Target scope | Routes |
| --- | --- | --- | --- | --- |
| `nexum.capabilities.read` | `integration-hub.capabilities.read` | `integration.view` | installation | `GET /capabilities`, `GET /capabilities/{key}/{version}` |
| `nexum.identity.read` | `integration-hub.identity.read` | `integration.view` | installation | `GET /identity` |
| `nexum.clients.read` | `integration-hub.clients.read` | `client.view` | Client | `GET /clients`, `GET /clients/{client}` |
| `nexum.sites.read` | `integration-hub.clients.read` | `client.view` | Client and Site | `GET /sites`, `GET /sites/{site}` |
| `nexum.domains.read` | `integration-hub.domains.read` | `client.view` | Client and Site | `GET /domains`, `GET /domains/{domain}` |
| `nexum.integrations.read` | `integration-hub.integrations.read` | `integration.view` | Integration and owner | `GET /integrations`, `GET /integrations/{integration}`, `GET /integrations/{integration}/health` |
| `nexum.executions.read` | `integration-hub.executions.read` | `integration.view` | all populated dimensions | `GET /executions`, `GET /executions/{execution}` |
| `nexum.audit.read` | `integration-hub.audit.read` | `integration.ai_audit_view` | all populated dimensions | `GET /audit-events` |
| `nexum.hosting.sites.inspect` | `integration-hub.hosting.read` | `integration.view` | exactly one Client, Site, Integration | `GET /hosting/sites/{site}/inspect` |

`/capabilities` returns only descriptors effective for the delegated actor/workload and resolved
scope. Missing or expired bindings deny by default. Descriptor metadata includes schema URNs,
read/side-effect/risk classification, idempotency, approval, timeout, rate, quantity, cost,
concurrency, verification, freshness, providers, targets, and lifecycle compatibility.

## Result envelope

Every protected read and grant response uses this shape:

```json
{
  "contract": {"name": "nexum.integration-hub.result", "version": "1.0"},
  "status": "ok",
  "correlation_id": "f30d258d-e188-45b0-8af7-80d67ebf4628",
  "capability": {"key": "nexum.clients.read", "version": "1.0"},
  "source": {"type": "nexum", "name": "nexum"},
  "freshness": {"observed_at": "2026-08-15T10:00:00+02:00", "stale_after_seconds": null, "is_stale": false},
  "scope": {"installation": "installation", "client_ids": [42], "site_ids": [91], "integration_ids": [], "environment": "production"},
  "data": [],
  "reason": null,
  "meta": {"pagination": {"current_page": 1, "per_page": 25, "total": 0, "last_page": 1}}
}
```

Allowed `status` values are `ok`, `denied`, `unavailable`, `failed`, `unknown`, `stale`, and
`partial`. A configured provider or a successful HTTP response is not enough to produce `ok`;
fresh authoritative evidence and mapping checks must pass. Observational states such as unknown,
stale, partial, or provider unavailable normally use HTTP `200` so clients inspect `status` and
`reason.code`. Authorization, validation, conflict, and rate-limit failures use the matching HTTP
status.

## Version negotiation

The grant request names the requested capability version. Protected reads may also send
`Accept-Capability-Version`. `1` and `1.0` negotiate to the current `1.0` contract. Unknown major or
unsupported minor versions fail with HTTP `409` and `contract_version_unsupported`; Nexum never
silently switches contract major. Successful and denied protected reads return
`Content-Capability-Version: 1.0` and `Vary: Accept-Capability-Version`.

Capability lifecycle metadata exposes deprecation and replacement without changing an existing
descriptor in place.

## Pagination, filters, and existence hiding

List endpoints use `page` (minimum 1) and `per_page` (1-50 by default). Scope filters are applied in
the database before pagination and model retrieval. A missing and an out-of-scope identifier both
return HTTP `404` with `record_not_found_or_out_of_scope`.

| Route | Additional filters |
| --- | --- |
| `/clients` | `q`, `active`, `sort=name|-name|id|-id` |
| `/sites` | `q`, `client_id`, `sort=name|-name|id|-id` |
| `/domains` | exact normalized `hostname`, `client_id`, `site_id`, `integration_id`, `environment`, `lifecycle`, `sort` |
| `/integrations` | `type`, `environment`, `owner_scope`, `status`, `sort` |
| `/executions` | `status`, `capability_key`, `correlation_id` |
| `/audit-events` | `decision`, `result_status`, `capability_key`, `correlation_id` |

Client/Site lists return only minimized operational fields. Domain hostnames are customer data.
Integration responses expose a credential-configured boolean but never server URLs, credentials,
headers, raw provider errors, certificates/private keys, or stack traces.

## Durable hosting inspection

`GET /api/v1/integration-hub/hosting/sites/{site}/inspect` requires exactly one explicit Client,
Site, Plesk Integration, and active domain binding. The optional `Idempotency-Key` header is bounded
and scoped into the durable Execution digest. A completed duplicate returns the stored sanitized
result without a second provider call. An in-progress duplicate returns HTTP `409`, reason
`execution_in_progress`, and `retryable: true`.

The Plesk adapter performs only fixed XML API `get` operations for the bound subscription, site, and
aliases. It refuses redirects, limits time and response size, retries one safe transient failure,
checks subscription/site/alias relationships, and verifies only explicitly bound hostnames. TLS
inspection verifies the expected hostname. Missing or ambiguous provider observations are unknown
or partial, never success.

## Operator control endpoints

The following routes are for authorized Nexum operators, not the MCP service grant flow:

- `GET /api/v1/integration-hub/readiness`
- `GET /api/v1/integration-hub/controls`
- `POST /api/v1/integration-hub/controls`

They require an explicit non-broad Sanctum token with `integration-hub.controls.manage` and the
User permission `integration.ai_governance_manage`. Session authentication and `*` tokens are
rejected. Disable and re-enable are separate audited changes with an operator reason. A global
disable invalidates all grants issued before the change.

## Stable reason codes

Clients must branch on `reason.code`, not human text. Important classes include:

- identity/grant: `issuer_token_required`, `broad_token_rejected`,
  `service_workload_binding_required`, `grant_not_yet_valid`, `grant_expired`, `grant_replayed`,
  `grant_revoked`, `grant_record_invalid`, `grant_installation_mismatch`,
  `contract_version_unsupported`;
- policy/scope: `required_permission_missing`, `required_ability_missing`,
  `record_scope_invalid`, `site_client_scope_mismatch`, `integration_owner_scope_mismatch`,
  `emergency_control_active`;
- request/record: `request_validation_failed`, `record_not_found_or_out_of_scope`,
  `execution_in_progress`;
- provider: `provider_misconfigured`, `provider_authentication_failed`,
  `provider_timeout_or_connection_failed`, `provider_rate_limited`, `provider_unavailable`,
  `provider_schema_invalid`, `provider_mapping_not_found`,
  `provider_site_subscription_mismatch`, `bound_domain_not_observed_by_provider`,
  `execution_cancelled`.

`reason.retryable` is authoritative for retry decisions. Never infer retryability from HTTP status
alone.

## Audit and retention

Allowed and denied protected reads create sanitized Integration-owned audit events with correlation,
identity IDs, capability/version, populated scope dimensions, result/reason, source/freshness,
duration, and HTTP status. Request/response bodies, authorization headers, grants, service tokens,
credentials, and raw provider payloads are excluded.

Executions, grants, approvals, steps, and audit survive the MCP request boundary. Retention is
settings-led; `integration-hub:prune` runs daily at 04:00. Queue and scheduler recovery never claim
that cancellation compensated an external side effect.

## Verification state

The automated candidate passes 34 focused tests plus 70 affected Integration/AI regression tests.
The only provider gate intentionally left open is one named human's safe read-only check against an
approved non-production Plesk Integration in `HR-2026-08-15-001`.
