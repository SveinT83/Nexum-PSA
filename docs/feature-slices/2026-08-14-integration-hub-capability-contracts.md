# Feature Slice: Integration Hub Capability Contracts

Status: Ready for Human Review
Date: 2026-08-14
Parent: GitHub #213 and `docs/rfc/2026-08-14-nexum-integration-hub-mcp.md`
Owner: Svein / Codex

## Goal

Expose a versioned, effective read-only capability catalogue and shared provider-result envelope.

## Routes And Behavior

- `GET /api/v1/integration-hub/capabilities` lists only effective descriptors.
- `GET /api/v1/integration-hub/capabilities/{key}/{version}` hides non-effective descriptors.
- Header `Accept-Capability-Version` may request a supported major; incompatible versions fail
  closed with `contract_version_unsupported`.
- All responses use envelope `1.0`, stable correlation, source, freshness, scope, and safe reasons.

## Data Touched

Capability descriptors and explicit scope bindings. Initial read capabilities are seeded
idempotently; existing Sanctum abilities are extended but not broadened.

## Permissions And Isolation

`integration-hub.capabilities.read` plus descriptor-required ability, current installation,
workload allowlist, grant target, and emergency controls. Missing bindings deny by default.

## Tests

Descriptor completeness; exact/unsupported versions; missing/wrong installation, Client, Site,
Integration, role, actor, and workload bindings; stale/partial/sanitized envelopes; pagination and
redaction.

## Documentation

Capability ADR, API contract, Integration/Agent/security notes, and human review.

## Done Criteria

- [x] Registry and bindings migrate safely and roll back.
- [x] Effective catalogue is deny-by-default and versioned.
- [x] Shared envelope covers all seven result states.
- [x] Focused and regression tests pass on authoritative Dev.

Automated evidence: `IntegrationHubFoundationTest` passes 34 tests / 290 assertions and the affected
Integration/AI regression group passes 70 tests / 667 assertions. Human review remains
`HR-2026-08-15-001`.
