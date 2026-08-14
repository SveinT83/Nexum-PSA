# Feature Slice: Integration Hub Integration Catalogue

Status: Approved
Date: 2026-08-14
Parent: GitHub #219
Owner: Svein / Codex

## Goal

Expose visible Integration metadata and normalized health without exposing configuration secrets.

## Routes And Behavior

Bounded list/get/health routes return identity, provider type, owner scope, installation, optional
Client/Site, environment, lifecycle, credential availability boolean, effective capability versions,
controls, and shared health/freshness envelope. Raw test-credential actions are not exposed.

## Data Touched

Nullable/defaulted ownership and health-observation fields on existing `integrations`; explicit
capability bindings and Hub audit. Existing records remain usable and default to internal scope.

## Permissions And Isolation

`integration-hub.integrations.read` plus owner, installation, Client/Site, workload, grant,
capability, and emergency scope. Server URLs, secrets, headers, certificates, raw errors, and stack
traces are excluded.

## Tests

All owner scopes, wrong installation/Client/Site, missing ability, disabled/misconfigured/auth/
rate-limit/unavailable/stale/partial/unknown/healthy states, BookStack normalization, pagination,
credential metadata, and redaction.

## Documentation

Integration/API/OpenAPI/operations/security/Knowledge docs and human review.

## Done Criteria

- [ ] Existing Integrations migrate without credential exposure.
- [ ] Catalogue reflects effective capability and control state.
- [ ] Health never claims success without a fresh observation.
