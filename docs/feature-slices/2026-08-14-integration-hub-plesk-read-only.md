# Feature Slice: Integration Hub Plesk Read-Only Adapter

Status: Approved
Date: 2026-08-14
Parent: GitHub #220
Owner: Svein / Codex

## Goal

Inspect one explicitly bound Plesk subscription/site/domain through a typed read-only Nexum adapter.

## Routes And Behavior

`GET /api/v1/integration-hub/hosting/sites/{site}/inspect` resolves the current installation, Client,
Site, Plesk Integration, and domain bindings before any provider call. It returns normalized account,
subscription, domains/aliases, hosting/runtime, and hostname-verified certificate observations in
the shared result envelope.

## Data Touched

Existing encrypted Integration secrets, explicit domain/provider references, health observation,
Execution/audit metadata. Provider response bodies are not persisted.

## Permissions And Isolation

`integration-hub.hosting.read`, service identity/grant, effective capability binding, explicit
Client/Site/domain/Integration scope, and all emergency controls. The adapter has no generic request,
shell, file, database, DNS, certificate issuance, or mutation method.

## Provider Contract

Allowlisted HTTPS endpoint, TLS verification, connect/total timeout, one bounded retry for safe
transient failures, response size/schema limits, 401/403/404/409/429/5xx classification, redaction,
and freshness. Timeout, ambiguity, malformed/schema-drift, and cancellation are never success.

## Tests

Success, empty, malformed, auth failure, timeout, rate limit, partial response, schema drift, wrong
mapping/installation/Client/Site, disabled path, redaction, response limit, certificate hostname and
expiry, cancellation contract, and absence of mutation methods.

## Documentation

Provider ADR, Plesk operations/security/API/Knowledge docs and Level 3 human review. One safe
non-production manual read remains required before rollout.

## Done Criteria

- [ ] Mock/contract tests pass on authoritative Dev.
- [ ] Provider credentials remain Nexum-only.
- [ ] Manual non-production verification is recorded by a named human.
- [ ] No mutation surface exists.
