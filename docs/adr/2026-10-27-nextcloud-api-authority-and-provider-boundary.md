# ADR: Nextcloud API Authority And Provider Boundary

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore (RFC approval 2026-10-27); Codex (implementation).
Parent: ../rfc/2026-10-27-nextcloud-full-api-parity.md.

## Context

Calendar-only APIs omit connection administration, mappings, folders, full sync and
Talk. Full manual sync can change contacts and calendars. Existing Calendar claims
and activation consent are already authoritative.

## Decision

Propose explicit module-owned typed routes and shared web/API actions, cumulative
fresh native actor/object/field and token checks, bounded projections and write-only
encrypted secrets. Preserve Calendar/Contact ownership, calendar API compatibility
and independent HMAC inbound authentication. New provider writes require exact target,
explicit grants and default-off execution; existing calendar consent remains separate.
Use additive durable actor/token command receipts, atomic local audit/effects/result,
keyed secret fingerprints and optimistic preconditions; no secret request snapshots.
Record remote intent separately and reconcile unknown outcomes before any retry.
Auto-match apply binds the exact reviewed local mapping proposal.

## Rationale

Shared behavior avoids web/API drift; separate general/calendar commands avoid
unexpected contact or calendar effects. Current rights and allowlisted results
prevent raw-model/receipt disclosure. Durable identity supports safe recovery.

## Consequences

Requires an additive command migration and native concurrency verification. API
availability grants no browser authority and activates no production capability.
Coordinate shared catalog/Swagger edits with Knowledge without overwriting work.
No new Talk destination or SSO architecture is accepted in this proposal.

## Alternatives Considered

Generic provider proxy, redirect wrappers, token-only checks, raw model JSON,
cache-only replay and duplicated Calendar/Contact implementations fail authority,
contract or recovery requirements.

## Follow-Up

All five sequential slices and exact typed coverage are verified under the approved RFC.
The employee handbook is read back. Complete HR-2026-10-27-NEXTCLOUD-API before release
unless explicitly scoped otherwise by Svein. Accepted under the approved parent RFC.
