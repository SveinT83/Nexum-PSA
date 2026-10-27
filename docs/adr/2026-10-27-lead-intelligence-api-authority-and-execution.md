# ADR: Lead Intelligence API Authority And Execution Boundary

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore (RFC approval, 2026-10-27), Codex (proposal)
Parent: ../rfc/2026-10-27-lead-intelligence-full-api-parity.md

## Context

Existing token abilities alone are weaker than browser actor policy. Queued discovery,
external AI and promotion can create cross-domain records and incur provider costs.
Legacy promotion bypasses Clients-owned provider numbering. Current APIs lack durable
request identity and complete typed contracts.

## Decision

Propose intersection of current actor rights and token abilities for all APIs; shared
web/API domain mutations; pure context reads; encrypted actor-bound durable commands,
strict inputs and revisions. Keep queued acceptance separate from completed discovery.
Retain initiating API actor identity and reauthorize side effects; use Integration's
AI consent/telemetry boundary. Keep new API external execution default-off. Preserve
uncertain provider attempts for reconciliation without blind retry. Delegate Client
creation/numbering to Clients/CreateClientRecord and provider reconciliation to
DataExchange. Persist one provider request UUID before any external attempt.

## Rationale

Equivalent authority and one mutation path prevent a second operational implementation.
Durable identities make retries reviewable without duplicate jobs, customer records,
Marketing memberships or AI costs. Domain ownership protects existing integrations.

## Consequences

Additive receipt/locking schema and explicit legacy validation/error documentation
are required. Existing tokens do not gain native permissions. Some formerly accepted
unauthorized/invalid API calls will be denied. Shared worker changes require tests
for both manual and scheduled origins and no unrequested runtime activation.

## Alternatives Considered

Read-only APIs do not meet the request. Duplicated API business rules would drift.
Token-only authorization broadens rights. Synchronous discovery and automatic retries
would hide queue/provider uncertainty. A second Client allocator bypasses ownership.

## Follow-Up

Implement only after parent RFC approval; record native DB, exact Swagger, handbook,
concurrency and provider-fake evidence and retain the pending human-review checklist.
