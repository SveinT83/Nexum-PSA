# Integration Hub Service Identity Runbook

Status: Ready for human review
Related: GitHub #214, `docs/adr/2026-08-14-integration-hub-service-identity-and-grants.md`

## Purpose

Provision, rotate, and revoke the private MCP-to-Nexum service identity without combining it with
the delegated human/workload identity. Do not paste tokens or signing keys into Git, tickets,
ordinary logs, shell history, or this document.

## Preconditions

- Use the intended Nexum installation and environment.
- Take the normal application/database backup before the migration.
- Configure a stable `INTEGRATION_HUB_INSTALLATION_KEY` for the installation.
- Set issuer, audience, and authorization-server URLs to the exact HTTPS origins used by Nexum and
  the MCP service.
- Generate the grant signing key in the approved secret store. It must contain at least 32
  characters. Keep the active key ID and key together.
- Identify the MCP service's fixed egress IP or CIDR. `--allow-any-network` is an explicit exception,
  not the default.
- Create or approve one coordinator workload in AI Privacy & Coordinator Governance. Give it only
  the required `integration-hub.*.read` abilities, a bounded Client allowlist where applicable,
  `local_only` processing unless a separately approved policy allows otherwise, and a review
  expiry.

## Provision without enabling

Run migrations, then provision descriptors, installation bindings, and the protected system actor:

```bash
php artisan migrate --force
php artisan integration-hub:bootstrap
```

This does not enable the Hub and never creates a token or provider credential. Review the
capability rows, workload, environment values, and readiness output before enabling.

## Enable

```bash
php artisan integration-hub:bootstrap --enable
```

The command fails if the signing key is absent or shorter than 32 characters. Re-running
`bootstrap` after enablement preserves the enabled state and idempotently refreshes descriptors and
installation bindings.

Use a narrow operator token to inspect:

```text
GET /api/v1/integration-hub/readiness
```

Expected state is HTTP `200`, `data.status=ok`, `hub_enabled=true`,
`global_disabled=false`, and equal defined/enabled capability counts. The response never includes
the key value.

## Issue a service token

Issue one token for the approved workload and the real egress boundary:

```bash
php artisan integration-hub:issue-service-token integration-hub-mcp-service \
  --name="Nexum MCP service 2026-08" \
  --days=30 \
  --network=192.0.2.10/32 \
  --rpm=30
```

Repeat `--network` for additional approved IPv4/IPv6 addresses or CIDRs. Invalid networks, a
missing network decision, and combining `--network` with `--allow-any-network` fail closed. Token
expiry is capped by the workload expiry.

The command prints a non-secret token record ID and shows the plaintext token exactly once. Capture
the plaintext directly into the MCP service's encrypted secret store. Do not redirect command
output to a general file or CI log. The token has only `integration-hub.service`; it cannot issue a
grant or use a capability by itself.

## MCP credential separation

The MCP runtime needs two separately named secrets:

- service token: used only as the bearer on protected read routes;
- actor/workload issuer token: used only to request a single short-lived execution grant.

The MCP inbound bearer, cookie, or protocol authorization header is never either of these values.
Do not log the issuer token, service token, execution grant, or authorization headers. Redact them
before exception handling.

## Safe smoke verification

1. Request `nexum.identity.read` with a narrow issuer token and a 30-120 second TTL.
2. Call `/identity` once with the service token and execution grant.
3. Confirm the response has contract `1.0`, the expected actor/workload kind, installation, scope,
   and no token/grant/profile material.
4. Repeat the same grant and confirm `grant_replayed`.
5. Confirm an audit event exists for both attempts.

Do not use the Plesk inspection route as the initial identity smoke test.

## Rotate a service token

1. Issue a new token with the same or narrower workload, network, expiry, and rate boundaries.
2. Store the new plaintext in the approved MCP secret store.
3. Restart only the MCP process and run the identity smoke test.
4. Revoke the old record by the non-secret ID printed during issuance:

```bash
php artisan integration-hub:revoke-service-token 123 --reason=operator_rotation
```

5. Confirm the old token is denied and the new token remains usable.

The revoke command only accepts a token bound to the configured Integration Hub service actor. It
does not display token material.

## Rotate the execution-grant signing key

1. Create a new key and key ID in the approved secret store.
2. Move the current active ID/key to `INTEGRATION_HUB_PREVIOUS_GRANT_KEY_ID` and
   `INTEGRATION_HUB_PREVIOUS_GRANT_KEY`.
3. Put the new ID/key in `INTEGRATION_HUB_GRANT_KEY_ID` and `INTEGRATION_HUB_GRANT_KEY`.
4. Reload configuration and workers, then verify readiness and one identity grant.
5. Keep the previous key only for the documented overlap. The maximum normal grant lifetime is five
   minutes; allow clock skew and in-flight request time before removing it.
6. Remove both previous-key settings, reload configuration, and verify an old-key grant returns
   `grant_key_unknown` while a new grant succeeds.

Never reuse `APP_KEY` as the grant key. A global emergency disable invalidates previously issued
grant records independently of key rotation.

## Recovery

- `grant_signing_unavailable`: restore the intended key from the secret store; do not generate an
  untracked replacement during an incident.
- `service_workload_binding_required` or `workload_token_expired_or_revoked`: inspect the exact
  token record ID and workload approval/expiry; issue a replacement rather than widening policy.
- `workload_network_not_allowed`: verify actual egress addressing. Do not use
  `--allow-any-network` as a diagnostic shortcut.
- `grant_audience_invalid` or `grant_installation_mismatch`: correct environment configuration;
  never accept a token for another audience or installation.
- suspected credential disclosure: apply global emergency disablement, revoke the service and
  issuer tokens, rotate the grant key, inspect sanitized audit, and only then re-enable.

Record the operator, reason, environment, old/new token record IDs, key IDs, verification results,
and time. Never record secret values.
