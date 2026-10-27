Nexum records sanitized operational metadata for AI model requests so administrators can later
understand model usage, failures, fallback attempts, and provider-reported cost.

The telemetry is an operational ledger. It is separate from AI chat history, the data-egress
governance audit, Economy, and Client billing.

## Logical Executions And Attempts

One product action is a logical execution. One actual outbound provider request is an attempt.

A logical execution can contain several attempts when Nexum tries Chat Completions, falls back to
Completions, and then uses the Responses API. Each attempt receives its own ordered event while all
attempts retain the same execution ID.

This distinction prevents a successful final response from hiding earlier provider failures or
potentially billable requests.

## Recorded Fields

The operational ledger records:

- Provider and AI agent references.
- Stable feature, operation, and domain keys.
- Requested and provider-returned model identifiers.
- Endpoint type, provider request ID, HTTP status, finish reason, duration, and sanitized error
  category/code.
- Input, output, total, cached, cache-write, reasoning, and audio token counts where reported.
- Ollama token counts and provider timing fields.
- Allowlisted non-token units such as reported web-search calls.
- Provider-reported cost and currency where the response includes both.
- Optional actor, Work Context, source-record, chat, message, and correlation references.

Missing usage remains unknown. Nexum never turns missing token or cost fields into zero.

These guarantees apply to requests using the shared Integration model executor and structured
workload path. Check the recorded feature key when investigating coverage; the report is not a
provider invoice and does not prove that every external provider service is metered.

## Data That Is Not Recorded

The usage ledger does not store:

- Prompts or model answers.
- Chat-message bodies or source-record text.
- Provider credentials, authorization headers, or HTTP request payloads.
- Raw provider error bodies.
- Client invoice lines, Economy transactions, or employee performance scores.

Only an explicit allowlist of provider usage fields can enter the JSON metadata columns.

## Failure Behavior

Successful and failed outbound attempts are recorded. A transport exception receives a sanitized
error class; an unsuccessful HTTP response receives a provider HTTP category and safe code.

If the telemetry database write itself fails, Nexum records a structured application error. It does
not discard an otherwise successful model answer. The separate data-egress policy and mandatory
governance audit keep their own fail-closed requirements.

## Provider Differences

OpenAI-compatible Chat Completions and Completions normally report prompt and completion tokens.
Responses reports input and output tokens. Ollama reports evaluation counts and durations.
OpenRouter-compatible responses may also include provider-reported cost.

Nexum normalizes the common fields while retaining only approved numeric provider details. The
actual model remains separate from the configured model because provider routing and aliases can
make them differ.

## Availability And Access

The Issue #277 cost correction is implemented and verified on Dev. Main merge is being verified;
production has not been changed. Human-review checklist HR-2026-10-28-AI-COST-EVIDENCE remains
Pending before production rollout. Employee assistant retrieval is a separate check.

The report requires current AI administration permission. API access additionally requires the
dedicated `integration.ai_telemetry.read` scope. A token cannot grant native permission, and no
existing token or role is expanded automatically.

## Read The Telemetry Report

1. Open **Admin > Integrations > AI Integration > Telemetry**.
2. Enter **Domain** and/or **Feature**, then select **Filter**. The trace and summaries use the same
   filters. An execution ID can also be supplied to the API or report query to inspect fallback.
3. Read **Known cost subtotals by currency**. USD and NOK stay separate; there is no currency
   conversion or cross-currency grand total. Reported and estimated subtotals are shown separately.
4. Check the counts of unknown cost, unknown currency and unknown token usage. Partial evidence
   means the known subtotal excludes those amounts; it does not mean those attempts were free.
5. Select **Details** for an attempt. Compare **Reported**, **Estimated**, **Source** and currency.
   Zero is known only when explicitly reported or calculated from known usage and valid prices.
6. Open **Rate Cards** to inspect model patterns, currency, effective dates and supported metrics.
   This is a read-only surface. This change does not introduce a price editor or budget-alert UI.

## Cost Evidence And Precision

Reported provider cost takes precedence, including explicit zero. A rate-card estimate is separate
evidence and never relabels the provider charge. A missing rate card, unknown required usage or
unsupported charge metric leaves the estimate unknown. Cached input is a subset of input and is
charged once at an explicit cache rate when configured; otherwise the base input price covers it.
Non-token work without supported pricing remains unknown.

Amounts use decimal strings with 12 fractional places. Values such as 0.000000000001 remain visible.
A provider amount requiring more than 12 non-zero fractional places stays unavailable, so a
sub-scale overage cannot round down below a strict budget. Estimates are rounded to 12 places; a
positive estimate below representable precision remains unknown instead of free.
A missing currency is displayed as unknown and excluded from currency subtotals. Old ambiguous
null/none cost records are not rewritten from today's prices or converted into authoritative zero.

One execution can contain several failed/successful fallback attempts. Each attempt contributes
once, including a failed attempt with reported cost. Execution counts and attempt counts differ.
The telemetry is separate from customer billing and does not prove that every provider charge is
known. If a workload has an explicit provider-reported cost cap, unknown cost/currency or mismatched
currency is denied; decimal comparisons are exact. No configured cap means no global budget is implied.

## Read-Only API

Use a current internal AI administrator and a token scoped to `integration.ai_telemetry.read`:

- `GET /api/v1/integrations/ai/telemetry`: trace, per-currency summary and completeness counts.
- `GET /api/v1/integrations/ai/telemetry/{event}`: the same attempt evidence as **Details**.
- `GET /api/v1/integrations/ai/rate-cards`: versioned price evidence.

Lists accept `page` and `per_page` (1-100). Telemetry also accepts `domain`, `feature` and
`execution_id`. Unsupported query fields are rejected. Amounts are nullable JSON decimal strings,
token fields remain nullable integers, and summaries never add currencies together. These endpoints
do not execute a model, change prices, disclose provider secrets or grant recipient access.

## Troubleshooting

- **Unknown cost**: check whether the provider reported a cost, or whether a matching active
  rate card has known units for all priced metrics. Do not backfill old uncertainty with new prices.
- **Unknown currency**: inspect the provider's cost currency or the estimate's price snapshot.
  Do not assume USD.
- **403**: check both the current native AI administration permission and API scope.
- **422**: check filter names, UUID execution IDs and pagination bounds.
- **Partial subtotal**: inspect unknown attempt counts before using the figure for any decision.

The table is `ai_model_usage_events`. This change widens effective/calculated amounts to the same
24,12 precision as provider amounts; it does not backfill history. Deployment needs the exact
precision migration and normal cache/Swagger refresh. Production rollout still needs the named
human review. Retention, provider billing reconciliation, general budget alerts and FX remain
separate work.
