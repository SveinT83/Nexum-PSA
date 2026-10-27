# ADR: Knowledge API Uses Existing Publication And Sync Authority

Status: Accepted under the RFC approved by Svein Tore, 2026-10-27.
Date: 2026-10-27
Owner: Codex. Decision maker: Svein Tore.
Parent: ../rfc/2026-10-27-knowledge-full-api-parity.md

## Context
Knowledge already has immutable proposals and a published Article projection (#266), plus
revision-safe BookStack sync (#276). Full API access must reuse these contracts and preserve
Ticket-only review without broad library permissions. Documentation is a separate domain.

## Proposed Decision
Use domain-owned routes/controllers/actions and current actor/object rights plus explicit token
abilities. Keep existing proposal/publication and sync state machines as the source of truth;
local encrypted API command receipts provide transport replay and do not replace content history.
Shared browser/API actions preserve side effects. Read-back distinguishes local persistence,
provider queueing and proven provider content. API provider execution remains default-off.
Portal resources use their existing current membership and published-content boundary.

## Consequences
Legacy scope-only callers need corresponding native user rights. Narrow Ticket review remains
an exact-object operation. Unknown provider outcomes require reconciliation, never blind replay.
No new human role grants, audience model, publication bypass or second BookStack lifecycle.
Additional command storage and typed schemas require tests, additive migration and review.

## Alternatives
Direct published-row mutation bypasses approved review and is rejected. A separate API-only
publication/sync workflow duplicates authority and is rejected. Read-only endpoints do not
meet the user's requested operational support.
