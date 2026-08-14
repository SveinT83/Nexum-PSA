# ADR: Integration Hub Capability And Result Contracts

Status: Accepted
Date: 2026-08-14
Decision Makers: Svein / Codex
Related: GitHub #212, #213

## Context

Nexum's Sanctum ability catalogue answers whether a token may enter a domain API. It does not
describe an externally callable operation's schema, side effects, risk, target bindings,
verification, freshness, or compatibility. MCP, the visual UI, API consumers, Agents, and scheduled
workloads need one protocol-neutral contract without turning MCP metadata into authorization.

Provider observations also need a shared result language. A missing or old observation must not be
presented as healthy, and raw provider failures can contain credentials or customer data.

## Decision

Integration owns a versioned capability registry. A descriptor is identified by a stable key plus
an immutable contract version and includes schema references, lifecycle, access mode, side-effect,
risk, reversibility, idempotency, approval, timeout, quantity/rate/concurrency limits, verification,
freshness, providers, targets, deprecation, and compatibility metadata.

Bindings are explicit database records. They can constrain actor/role, workload, installation,
Client, Site, Integration, and environment. A capability is effective only when its Laravel ability
is present and every applicable policy dimension is satisfied. Missing capability or target
bindings deny by default; a binding can only narrow ordinary Nexum access.

External read contracts use envelope version `1.0` and one of `ok`, `denied`, `unavailable`,
`failed`, `unknown`, `stale`, or `partial`. The envelope contains correlation ID, contract identity,
source, observation/freshness metadata, sanitized reason information, data, and scope. Provider
payloads and exceptions are never returned directly.

Clients request a supported contract version. Exact major-version compatibility is required;
unknown majors fail closed. Compatible minor additions do not change an existing schema identity.
Deprecation is observable and never silently replaces a descriptor.

## Consequences

- Domain abilities remain the first coarse authorization gate.
- Integration Hub routes return only the effective catalogue after record and workload scope.
- Provider adapters normalize their output before it reaches API or MCP.
- Adding a capability requires a descriptor, bindings, tests, and documentation.
- The initial registry contains read-only capabilities only.

## Alternatives Considered

- Reuse Sanctum abilities as descriptors. Rejected because abilities do not contain the required
  execution and compatibility contract.
- Let MCP own schemas and risk metadata. Rejected because visual/API/Agent paths must share policy.
- Treat null provider data as healthy. Rejected because it creates false assurance.

## Follow-Up

Implement Feature Slice `2026-08-14-integration-hub-capability-contracts.md`; keep human review
pending until negative scope and compatibility checks are manually confirmed.
