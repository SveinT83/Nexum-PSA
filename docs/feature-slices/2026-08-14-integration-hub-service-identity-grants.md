# Feature Slice: Integration Hub Service Identity And Grants

Status: Approved
Date: 2026-08-14
Parent: GitHub #214
Owner: Svein / Codex

## Goal

Create the separate Nexum service boundary and one-time delegated execution grants used by MCP.

## Routes And Behavior

- `GET /.well-known/oauth-protected-resource/api/v1/integration-hub` advertises audience metadata.
- `POST /api/v1/integration-hub/grants` mints a maximum-five-minute narrowed grant for an
  authenticated interactive actor or approved bound workload.
- Service routes require a dedicated system actor token plus `X-Nexum-Execution-Grant`.

## Data Touched

Grant issue/use/revocation metadata and the existing protected system actor/workload/token models.
Signing keys are environment configuration and never stored in database rows or logs.

## Permissions And Isolation

Issuer requires `integration-hub.grants.issue`; service token requires `integration-hub.service`.
The grant must match installation, capability/version, Client, Site, Integration, environment,
actor/workload policy digest, and service audience. It can only narrow existing access.

## Tests

Valid issue/use, expired, early, wrong audience/signature/key, revoked epoch, replay, broadened scope,
wrong installation/Client/Site, wrong service actor, MCP-token passthrough, redaction, key overlap,
rate limit, and missing configuration.

## Documentation

Auth ADR, API/security docs, key-rotation/emergency runbook, and human review.

## Done Criteria

- [ ] Service and delegated identities are distinct.
- [ ] Grants are short-lived, signed, audience-bound, scoped, and one-time.
- [ ] Failure is safe and machine-readable.
- [ ] Focused security tests pass on authoritative Dev.
