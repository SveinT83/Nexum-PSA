# ADR: Domain API Authorization And Existing Audit Journal

Status: Accepted
Date: 2026-10-20
Decision Makers: Codex under Svein Tore's approved Ticket/Task parity RFC

## Context

Browser/API parity needs current actor permissions, separate token grants, bounded projections and durable administrative receipts without a new integration platform. Ticket Rules and domain generation actions already own their transactions and evidence.

## Decision

Module routes own each explicit typed API operation. Sanctum grants and current active-user browser permissions are cumulative requirements; record/context/field policy stays in domain actions. Full conversation access remains separate from legacy metadata reads. Shared extracted actions serve browser and API. Configuration writes reuse the installed Spatie activity_log journal inside the same domain database transaction. Receipts contain changed field names, content fingerprints and selected identifiers, never original content or credentials. Disabled/unavailable logging fails the configuration write. Existing domain rows/keys/locks retain operational idempotency; narrow transport adapters bind keys to the actor and normalized payload.

Route defaults also declare cumulative grants, conditional authorization notes and the fixed full-operation input field names. A small middleware rejects unsupported write fields before effects; it does not dispatch operations or derive permissions from runtime OpenAPI. This supports existing commands whose validation occurs in several shared form stages. Static Swagger attributes and route fields are cross-checked in tests. Browser/API route inventory tests require an explicit parity audit when a new domain route is registered.

Native quote/review/evidence write results use the same narrow projections as their read-back endpoints. Public-access tokens, disk paths and arbitrary requirement/metadata snapshots are not part of those Ticket result contracts; Sales and other owning domains retain their separate authority.

## Rationale

This preserves existing authority and side effects and avoids schema changes or a general unrestricted execution layer. Live Dev read-only preflight confirmed an enabled journal on the same domain connection with the required columns.

## Consequences

API access does not grant browser rights or activate providers/rule runtime. Audit failures block configuration writes. Missing journal schema/configuration is an explicit deployment prerequisite. SQLite feature tests establish rollback/replay behavior but do not establish MariaDB concurrency guarantees.

## Alternatives Considered

New generic request/audit tables add redundant lifecycle and migration risk. Raw model serialization leaks fields. Browser-controller wrappers cannot provide stable HTTP contracts. Token-only authorization grants excessive authority.

## Follow-Up

Maintain typed OpenAPI/capability and parity contracts; verify real concurrency and named operational human review before production deployment/release. Svein Tore separately approved the scoped Main merge on 2026-10-28; see ../plans/2026-10-28-ticket-task-api-main-promotion.md. Publish exact Knowledge sources through a configured supported BookStack path and read back employee access separately.
