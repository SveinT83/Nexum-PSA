# ADR: AI Cost Source And Decimal Contract

Status: Accepted
Date: 2026-10-28
Decision Makers: Svein Tore (approved Issue completion), Codex (technical defaults)

## Context

Issue #277 and the approved July telemetry RFC require precise nullable cost evidence. Existing
float/truthiness, missing model fields and currency-blind reporting violate that intent.

## Decision

Use existing decimal columns with 24,12 precision and decimal strings/Brick Math arithmetic.
Reported cost, including zero, wins when non-null; estimates require known supported units and
an active currency-bearing rate card. Otherwise cost remains unknown. Stored snapshots preserve
rate and currency provenance. Aggregates group currencies, expose unknown/mixed-source state and
sum unique attempt rows; no FX conversion or historical invented backfill.
Strict provider-reported budgets continue denying unavailable/mismatched cost and use exact decimal
comparison. The same owner query/evidence projection feeds current UI and three narrow typed API
reads with native plus token authority. No new AI execution or external grant is introduced.

## Rationale

This repairs the approved business contract with existing ownership/storage and preserves low-cost
calls, failed attempts and conservative budgets without inventing financial facts.

## Consequences

Unknown values display as Unknown; known subtotal is partial when any attempt is unknown. Mixed
currencies remain separate. Legacy ambiguous rows stay ambiguous. Decimal expansion requires its
own deployment migration; lossy rollback is refused. Streaming exact sums may cost more CPU than
SQL floating aggregation; reads retain bounded pages and authorized filters.

## Alternatives Considered

Zero fallbacks, float arithmetic and assumed USD/FX totals are rejected as misleading. Historical
repricing/backfill is rejected because current rate cards do not prove historical prices.

## Follow-Up

Complete both slices/tests/handbook and Main verification. HR-2026-10-28-AI-COST-EVIDENCE stays
Pending until named manual review; production deployment, pricing and provider activation separate.

Precision clarification: reported amounts that cannot be represented exactly at 12 fractional
places stay unavailable. This prevents a sub-scale overage from rounding down below a strict cap;
rate-card estimates may round to 12 places but positive underflow remains unknown.
