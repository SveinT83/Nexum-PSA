# Integration Hub: Safe Read-Only MCP Access

The Integration Hub is Nexum's controlled API boundary for MCP and approved automation. Nexum—not
the MCP service—owns identity, policy, Client/Site scope, provider credentials, durable Execution,
audit, emergency controls, and result verification.

## What is available

The first release can read:

- the effective delegated identity and bounded scope;
- Clients and Sites explicitly included in that scope;
- explicit domain-to-Client/Site/Integration bindings;
- sanitized Integration catalogue and health;
- durable Execution and sanitized audit state;
- one explicitly bound Plesk subscription/site/domain observation with TLS hostname verification.

There is no provider mutation, generic HTTP request, shell, file, database, DNS, mail, restart, or
certificate-issuance tool.

## Why two identities are required

An approved user or workload first requests a short-lived execution grant for one capability. The
MCP service then presents its separate service token and that grant to the protected read route.
The service token identifies the MCP process; the grant identifies the delegated actor/workload,
scope, capability, policy snapshot, audience, installation, and correlation. The grant expires in
at most five minutes and is accepted once.

This prevents a compromised service token from becoming a broad user token. Never copy an inbound
MCP bearer into a Nexum header, and never store tokens or grants in chat transcripts, tickets,
ordinary logs, or source control.

## Result states

Every protected read returns contract `nexum.integration-hub.result` version `1.0` and one of:

- `ok`: fresh evidence and required verification passed;
- `denied`: identity, policy, binding, scope, or emergency control blocked access;
- `unavailable`: the dependency cannot currently provide a trustworthy result;
- `failed`: the request/provider response violated the expected contract;
- `unknown`: Nexum cannot establish the mapping or state;
- `stale`: the latest observation is older than its allowed freshness;
- `partial`: some required observations succeeded and others did not.

Do not translate unknown, stale, partial, or unavailable into success. Use `reason.code` and
`reason.retryable`, not the human message or HTTP status alone.

## Scope and privacy

Client, Site, Integration, environment, workload allowlist, user permission, token ability,
capability binding, installation, data-egress policy, and emergency controls are intersected on
every call. Scope filtering happens before pagination and record retrieval. Missing and
out-of-scope IDs use the same response so another customer's record existence is not disclosed.

Domain hostnames are customer data. Integration responses expose only a credential-configured
boolean and sanitized health. They do not expose credential values, provider endpoints, headers,
raw errors, raw XML, certificates/private keys, or unrelated customer metadata.

## Plesk inspection

Plesk inspection requires one reviewed Client, Site, Plesk Integration, environment, and active
domain binding. Nexum sends fixed read-only XML `get` operations, refuses redirects, limits timeout
and response size, checks subscription/site/alias relationships, and verifies only explicitly
bound hostnames. It never searches the provider and guesses ownership.

An empty, malformed, timed-out, rate-limited, mismatched, or cancelled request is not success. A
missing hostname or certificate observation is partial or unknown. The manual non-production check
must be recorded in `HR-2026-08-15-001` before rollout.

## Emergency stop

Authorized operators can disable global, capability/version, Integration, Client, or Site scope.
The operator needs a narrow `integration-hub.controls.manage` token and the normal Integration AI
governance permission; sessions and broad tokens are rejected. A global stop also invalidates grants
issued before it. Re-enable is a separate audited change with its own reason.

If credential disclosure is suspected: stop MCP, global-disable, revoke affected tokens, rotate the
grant key, inspect sanitized audit by correlation, run the minimal identity smoke, and only then
re-enable.

## Operations

The Hub starts disabled. Bootstrap provisions descriptors and bindings but creates no credential.
Service tokens require an approved coordinator workload, explicit read abilities, expiry, rate
limit, and IP/CIDR decision. Durable Executions and audit survive the requesting connection.
Expired metadata is pruned daily at 04:00 according to retention settings.

Use the versioned API document and the service-identity, emergency, migration, and Plesk runbooks
for exact deployment and recovery steps.
