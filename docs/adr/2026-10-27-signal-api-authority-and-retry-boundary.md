# ADR: Signal API Shares Rule Authority And Retry Identity

Status: Accepted - parent RFC approved by Svein Tore on 2026-10-27.
Date: 2026-10-27
Owner: Codex. Decision maker: Svein Tore.
Parent: ../rfc/2026-10-27-signal-full-api-parity.md

## Context
Signal already owns cross-domain automation, immutable execution attempts, stable
action keys and a webhook outbox. Complete API support must expose current behavior
without a second engine, scope-only authority, duplicate effects or false delivery.

## Decision
Keep domain-owned registrations in routes.php with a compatibility API loader.
Share queries, validators and actions with browser entry points; require current
native/object rights plus token abilities. API command receipts are actor/token bound,
encrypted transport evidence, not a replacement for immutable rule history. Serialize
retry roots across browser/API entry points, retain snapshot action positions and
require stale-state/replay guards. Separate provider intent from verified receipt;
external API execution has an additional explicit scope and default-off switch.
Pure discovery must not grant roles, activate AI or configure providers on GET.

## Consequences
Legacy ingest actors need native action rights, plus an action ability for rule
processing; external effects need an explicit provider grant/switch. Record-only
input remains available through process_rules=false. Existing automated producers
retain domain-owned policy. Local writes and receipts are atomic where possible;
external ambiguity remains visible and cannot trigger blind replay. No automatic
role grants, source-trust forgery or unrelated customer's record access.

## Alternatives
Read-only APIs fail operational parity. An API-only rule engine duplicates authority.
Mutable execution history loses audit. Blanket transaction retry around provider
work risks duplicate messages and is rejected. Resetting webhook failed/unresolved
rows is outside this API parity work.
