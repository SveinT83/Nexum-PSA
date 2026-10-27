# RFC: Truthful AI Cost Evidence

Status: Approved
Date: 2026-10-28
Owner: Codex / Svein Tore
Parent: approved 2026-07-27 AI Model Usage And Cost Telemetry RFC; Issue #277.

## Context

Svein explicitly authorized the audited fixes and tested Main merge ("Fix alt og merge til main").
The July RFC/ADR already requires unknown cost to remain null and prohibits mixed-currency totals.
Current implementation violates that approved contract: missing rate card returns 0.0, recorder
uses truthiness for reported zero, and the four calculated-cost fields are absent from model
fillable/casts. Admin aggregates use SUM without currency grouping and views format null as zero.
Provider cost is decimal(24,12), whereas effective/calculated columns are decimal(18,12).
AiModelUsage parses exponential numeric values through float formatting. Structured provider cost
limits compare floats. Attempt identity is already unique on execution_id + attempt_number.

## Goals

Preserve provider_reported, estimated and unknown cost; distinguish missing/zero token usage;
use decimal strings and exact arithmetic with 12 fractional places, including low positive values.
Keep reported zero authoritative; preserve real failed/fallback attempt cost and sum each attempt
once. Report known/partial/unknown/mixed source and currency truthfully across persistence, UI,
JSON/export representations and strict provider-cost budgets. No prompts or credentials in telemetry.

## Non-Goals

Customer billing, exchange-rate selection/conversion, new global autonomous budgets, provider
runtime replacement, external execution activation or invented historical usage/pricing certainty.

## Proposed Change

Slice 1: typed cost evidence, decimal normalization/calculation and persistence correction. Expand
only effective/calculated precision to 24,12; retain all data. Estimates require a matching active
rate card, declared currency and known units for supported priced metrics. Cached input is priced
separately only when that metric is explicitly configured; otherwise base input covers all input.
Unknown/unsupported units or rate metrics never imply free work. Numeric provider inputs preserve
12-place precision; positive values below representable scale remain unavailable instead of zero.
Strict maxProviderReportedCost remains fail closed on unknown cost/currency or currency mismatch;
compare decimal amounts exactly. No cap means no global budget is claimed.

Slice 2: one cost evidence/read model for filtered UI and API summaries, per-currency known
subtotals, unknown counts, source completeness and distinct logical executions. Stream decimal
addition rather than binary SQL/float arithmetic. No FX and no cross-currency grand total.
Expose existing three telemetry/rate-card read surfaces through three typed Integration-owned API
GET operations: /integrations/ai/telemetry, /integrations/ai/telemetry/{event}, and
/integrations/ai/rate-cards. Use dedicated read scope plus fresh native integration.ai_manage;
no role/token grant, system-actor use or write endpoint. Paginate <=100 and share filters/state
with the existing UI. Existing BookStack API routes remain unchanged.

## Impact Analysis

Integration execution ledger, cost calculator/normalizer, structured budget comparison, Admin
telemetry controller/views, three scoped read routes/OpenAPI, tests and Knowledge. Source files
are shared; apply only exact changes and extract a scoped latest-Main candidate. No worker,
scheduler, provider, notifications, customer data, Economy posting or pricing mutation.

## Data And Migration Plan

One exact Dev migration expands effective_cost/calculated_cost decimals from 18,12 to 24,12;
record preflight schema/cohort counts and a protected encrypted rollback copy first. No backfill
or historical amount rewrite. Historical explicit reported amounts remain evidence; legacy null/
none/ambiguous estimates remain unknown. Down must refuse a lossy shrink when expanded values
exist. Main/production migration is separate; production is not authorized by merge.

## Testing Plan

Reproduce reported-zero truthiness and mass-assignment failures first. Cover missing/zero tokens,
known estimates, unsupported metrics, tiny decimal/exponential values, unknown currency, mixed
currencies/sources, failed/fallback attempts, exact budget comparison, historical ambiguity, UI
and JSON/typed API permission denial/pagination/read-back. Run existing shared executor/usage and
Admin regressions, generated Swagger and isolated Main checks. Native Dev decimal/schema probe
must use synthetic rolled-back rows and preserve the existing ledger.

## Documentation Plan

Update AI usage/cost and governance Knowledge, exact native/BookStack read-back, TODO, Issue and
HR-2026-10-28-AI-COST-EVIDENCE. Keep production/manual review and actual employee AI retrieval separate.

## Open Questions

No product choice blocks these corrections to the approved contract. No implicit FX conversion,
new provider/model choice, recipient grant or historical cleanup is introduced.

## Approval

Svein Tore's explicit audited-Issue completion/Main-merge instruction authorizes this concrete
#277 scope under the already-approved July telemetry direction. Technical defaults are reversible;
named human review remains Pending for production, not this explicitly requested tested Main merge.

Precision clarification: reported amounts that cannot be represented exactly at 12 fractional
places stay unavailable. This prevents a sub-scale overage from rounding down below a strict cap;
rate-card estimates may round to 12 places but positive underflow remains unknown.
