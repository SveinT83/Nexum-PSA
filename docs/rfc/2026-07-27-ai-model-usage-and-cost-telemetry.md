# RFC: AI Model Usage And Cost Telemetry

Status: Approved
Date: 2026-07-27
Owner: Svein / Codex
Related discussion: [GitHub Discussion #20](https://github.com/SveinT83/Nexum-PSA/discussions/20)
Related RFC: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`
Related ADR: `docs/adr/2026-07-14-central-privacy-gate-for-ai-data-egress.md`

## Context

Nexum already uses configured AI providers and agents in Integration, Ticket, Task, Signal,
Marketing, Lead Intelligence, and Nextcloud workflows. The existing Integration-owned
`AiChatResponder` extracts answer text from OpenAI-compatible Chat Completions, Completions,
Responses, and Ollama Chat responses, but discards provider request identifiers, the actual model,
token usage, timing, finish details, and provider-reported cost.

Most workflows call `AiChatResponder`, while at least Lead Intelligence web search and Nextcloud
folder matching currently perform their own provider HTTP requests. Some workflows create
`AiChat`/`AiChatMessage` records and some do not. Chat messages are therefore not a complete or
appropriate accounting boundary.

Nexum has no versioned model rate card, no distinction between provider-reported and calculated
cost, and no report that can attribute model usage to a stable product feature. This makes it
difficult to:

- understand which Nexum features consume model capacity,
- compare providers and models,
- investigate retries, fallbacks, failures, and unexpected spend,
- measure cache and reasoning-token behavior where providers expose it,
- plan budgets or later allocate approved costs to a Work Context, and
- verify that all outbound model paths use the intended Integration controls.

Provider response contracts are not identical. OpenAI Responses and Chat/Completions, Ollama, and
OpenRouter expose different usage, timing, cache, reasoning, and cost fields. A provider-neutral
ledger must preserve a stable common contract without pretending unavailable data is zero.

This work complements, but does not replace, the approved organization-controlled AI data-access
RFC. The data-egress policy gate decides whether and how Nexum data may leave the application. This
RFC records the operational result of each permitted provider attempt. It does not widen access,
retain model payloads, or turn usage telemetry into a privacy bypass.

This is Level 3 work because it adds Integration-owned accounting data and a shared model-execution
contract used across several modules.

## Goals

- Record one sanitized usage event for every actual outbound model attempt.
- Correlate multiple attempts with one logical Nexum AI operation.
- Normalize common token, timing, model, request, status, and cost data across providers.
- Preserve an allowlisted provider-specific usage subset when a common field is unavailable.
- Attribute usage to stable features and operations, with optional agent, actor, Work Context, and
  source-record references.
- Distinguish provider-reported cost, Nexum-calculated cost, and unavailable cost.
- Use versioned rate cards and immutable price snapshots when Nexum calculates cost.
- Route all current and future model calls through one Integration-owned execution boundary.
- Provide an Admin report for usage, cost, failures, and coverage without storing prompts or answers.
- Remain compatible with the approved central data-egress policy gate and privacy-gateway routing.
- Create a trustworthy foundation for later budgets, alerts, and approved cost allocation.

## Non-Goals

- Do not store prompts, answers, message bodies, provider credentials, HTTP headers, or raw provider
  error bodies in the usage ledger.
- Do not automatically invoice Clients, create Economy transactions, or treat model cost as
  billable work.
- Do not automatically download or silently overwrite model prices in the first implementation.
- Do not convert currencies or add amounts in different currencies into one misleading total.
- Do not use actor attribution to rank employees, measure productivity, or create a per-user cost
  leaderboard.
- Do not guarantee that Nexum's calculated amount exactly matches a provider invoice.
- Do not backfill historical token usage or cost when the original provider response was discarded.
- Do not replace provider billing portals, financial bookkeeping, or the governance access ledger.
- Do not require a chat record for background, classification, search, or document-matching calls.

## Current Behavior

- `ai_providers` stores provider connection and default-model configuration.
- `ai_agents` stores agent model and behavior configuration.
- `ai_chats` and `ai_chat_messages` store selected conversational workflows.
- `AiChatResponder::complete()` returns only response text.
- `AiChatResponder::respond()` writes response text and limited message status metadata.
- OpenAI-compatible endpoint fallback can perform more than one HTTP attempt, but no attempt ledger
  exists.
- `SearchLeadWebWithAi` calls the Responses API directly for web search.
- `AutoMatchClientFolders` calls OpenAI-compatible or Ollama endpoints directly.
- No structured model pricing or cost-calculation configuration exists.
- A missing usage block, a legitimate zero, and discarded usage are currently indistinguishable.

## Proposed Change

### 1. Integration-Owned Model Execution Boundary

Integration will own one provider-neutral model-execution service. Domain modules continue to own
their prompts, domain records, authorization, workflows, and interpretation of the model result.
They submit a typed execution request and context to Integration instead of issuing provider HTTP
requests directly.

The boundary will:

1. Accept a stable execution context and provider/model request.
2. Compose with the approved data-egress policy gate before any protected payload leaves Nexum.
3. Perform the provider HTTP attempt through an adapter.
4. Normalize the provider result into a typed result and usage value object.
5. Persist a sanitized usage event in a `finally`-style recording path for successful and failed
   outbound attempts.
6. Return model output and structured execution metadata to the calling domain.

The existing `AiChatResponder` may remain as the conversational facade, but its transport behavior
must use the shared execution boundary. Direct provider HTTP calls in domain actions must be migrated
to the same boundary.

### 2. Logical Operations And Attempt Events

Nexum will distinguish:

- **Logical execution:** one product operation requested by Nexum, such as drafting a campaign plan
  or reviewing one lead candidate.
- **Attempt event:** one actual outbound provider request.

Every logical execution receives a UUID. Every outbound attempt receives its own event with an
attempt number. Endpoint fallback, provider fallback, or an explicit retry creates another attempt
under the same logical execution.

This distinction is required because a failed Chat Completions request followed by a successful
Responses request may consume time or billable provider units twice. Collapsing them into one row
would hide failure cost and operational behavior.

Only actual outbound provider attempts create usage events. Validation or policy denial before an
outbound request remains part of the governance/access audit and application logs, not artificial
token usage.

### 3. Stable Execution Context

Every logical execution supplies:

- `feature_key`: stable machine-readable product capability, for example
  `marketing.campaign_plan` or `lead_intelligence.web_search`.
- `operation_key`: the specific model step when one feature performs several calls.
- `domain`: owning Nexum module.
- optional `ai_agent_id`.
- optional authenticated or initiating `actor_user_id`.
- optional `work_context_id`.
- optional source subject type and ID.
- optional `ai_chat_id` and `ai_chat_message_id`.
- optional correlation ID from the surrounding request or queued job.
- a billing classification such as internal, client-context, marketing, or lead-intelligence.

Actor and Work Context are nullable because background work and installation-level operations must
not invent a user or Client. The source subject reference is for traceability and aggregation, not
for copying source content into Integration.

Feature and operation keys are code-owned catalog values. They are not arbitrary labels entered by
end users, because stable reporting and tests depend on them.

### 4. Normalized Usage Contract

The normalized usage value object supports nullable fields for:

- input tokens,
- output tokens,
- total tokens,
- cached input tokens,
- cache-write tokens,
- reasoning tokens,
- audio input/output tokens where reported,
- request and provider timing,
- non-token billable units such as web searches, images, or tool calls, and
- an allowlisted provider-specific usage map.

Missing means `null`, not zero. Zero is stored only when the provider explicitly reports zero or a
deterministic local calculation proves zero.

Each event records a usage source:

- `provider_reported`,
- `nexum_estimated`, or
- `unavailable`.

The first implementation must not estimate tokens with a tokenizer unless the provider omitted
usage and the selected model has a verified tokenizer. Estimated usage must never be presented as
provider-reported usage.

### 5. Provider And Model Identity

Each event records:

- configured provider ID,
- configured/requested model,
- actual model identifier returned by the provider when available,
- endpoint or API kind,
- provider request ID when available,
- finish reason/status when available, and
- sanitized provider error category/code for failed attempts.

Requested and actual model are separate because provider aliases, routing, snapshots, or fallbacks
can make them differ. Cost matching prefers the actual model when an approved rate-card rule matches
it and otherwise falls back to the requested-model rule. The selected matching rule is included in
the immutable pricing snapshot.

Provider request IDs are operational references. They must not contain credentials or be exposed
outside the restricted Admin report.

### 6. Usage Event Ledger

The proposed Integration-owned table is `ai_model_usage_events`. The exact migration is confirmed
against the Dev database during the first approved Feature Slice.

Proposed columns include:

- UUID primary key and logical execution UUID,
- attempt number,
- provider, agent, actor, Work Context, subject, chat, and message references where applicable,
- feature, operation, domain, billing classification, and correlation identifiers,
- requested model, actual model, endpoint kind, and provider request ID,
- started/finished timestamps and duration,
- success/failure/cancelled status, finish reason, and sanitized error category/code,
- nullable normalized token counters,
- usage source,
- provider-reported cost, calculated cost, effective reporting cost, cost source, and currency,
- immutable pricing snapshot,
- allowlisted non-token usage and cost components, and
- allowlisted provider usage metadata.

Foreign references to operational records use nullable relationships with deliberate delete
behavior so retention or deletion of a chat/user/source record does not corrupt historical aggregate
cost. The event does not copy source names, prompt text, or response text.

The ledger is append-oriented. Corrections to rates do not rewrite historical calculated amounts.
An explicit adjustment/recalculation workflow, if later required, must preserve the original event
and calculation evidence.

Indexes must support time range, provider/model, feature/operation, status, agent, Work Context, and
logical-execution queries without indexing payload content.

### 7. Cost Sources And Versioned Rate Cards

Cost selection follows this order:

1. Use provider-reported cost when the response supplies a documented amount and currency.
2. Otherwise calculate cost from a matching active Nexum rate-card version.
3. Otherwise leave cost and currency unavailable.

Unknown cost is never stored or displayed as zero.

Integration will own versioned rate-card records with:

- provider and model matching rules,
- effective start/end times,
- currency,
- input/output/cache-read/cache-write/reasoning/request rates as applicable,
- non-token unit rates where supported,
- price units such as per token, per million tokens, per request, or per tool call,
- source/reference, review actor, and review time, and
- active/replaced state.

Every calculated event stores the exact matching rate-card version, price units, rates, calculation
components, and rounding result as a snapshot. Later price changes affect only later attempts.

Decimal arithmetic must be used. Floating-point arithmetic is not acceptable for stored cost.

Provider-reported and calculated amounts are retained separately. The effective reporting amount
uses provider-reported cost first, otherwise calculated cost. Reports identify the source and must
not sum different currencies into one total.

The first implementation uses Admin-maintained rates. Automatic provider catalog synchronization,
currency conversion, and invoice reconciliation require later approved work.

### 8. Failure And Telemetry-Persistence Behavior

Failed provider attempts are recorded even when no usage block is returned. Their known timing,
provider/model, endpoint, request ID, and sanitized error data remain useful.

Usage recording must not log request payloads through exception messages. Provider error bodies are
classified and sanitized before persistence.

A telemetry database failure must not silently disappear. It emits a high-signal structured
application error and a health/coverage signal. It does not turn an otherwise successful provider
response into a failed user workflow in the initial implementation, because operational telemetry is
not the authorization gate. A later durable outbox may harden this behavior if measured loss justifies
the additional complexity.

The approved data-egress policy and mandatory governance access audit retain their own fail-closed
requirements. This RFC does not weaken them.

### 9. Admin Reporting

Integration will provide a permission-controlled Bootstrap Admin report with:

- call count, success/failure rate, token totals, cached/reasoning usage, and effective cost,
- explicit reported/calculated/unavailable coverage,
- filters for date, provider, requested/actual model, agent, feature, operation, status, billing
  classification, and Work Context,
- logical-execution drill-down showing attempts and fallback sequence,
- export of the sanitized report fields, and
- warnings when rates, usage, currency, or telemetry coverage are incomplete.

The report does not show prompts or model answers. Actor-level filtering is restricted, purpose
limited, and not a default dashboard dimension. Nexum must not present employee rankings or imply
that model spend measures employee performance.

No settings or report UI is exposed until the corresponding persistence, permissions, calculations,
and queries are implemented and tested.

### 10. Retention And Privacy

Usage telemetry is operational accounting metadata and has a retention policy separate from optional
prompt/response retention and the governance access ledger.

The owning company configures a finite retention period within safe system limits. The exact initial
default and maximum are confirmed in the reporting/retention Feature Slice after operational and
bookkeeping needs are reviewed. Aggregate reports must make deleted references appear as deleted or
unavailable rather than reconstructing names in the ledger.

Mandatory usage events contain no prompt or response content. Optional content retention remains
governed by the separate organization-controlled AI data-access RFC and is off by default.

### 11. Economy And Future Allocation

An event may carry a billing classification and nullable Work Context reference so Nexum can
distinguish internal and client-context activity. This is classification only.

No Economy cost, invoice line, markup, or client charge is created by this RFC. Any future financial
posting or customer chargeback requires a separate approved RFC that defines accounting date,
currency conversion, tax, markup, reversals, permissions, and customer visibility.

### 12. Feature Slice Order

1. **Execution contract and usage ledger:** typed execution context/result/usage objects, provider
   normalization, add-only ledger migration/model/recorder, and `AiChatResponder` integration.
2. **Complete call-path coverage:** stable feature/operation catalog, context at every caller, direct
   Lead Intelligence and Nextcloud HTTP calls moved behind the shared boundary, and coverage tests.
3. **Versioned rate cards and cost calculation:** Admin-maintained rates, decimal calculator,
   provider-reported precedence, immutable snapshots, and multi-currency-safe aggregation.
4. **Admin reporting and retention:** permissions, aggregates, filters, fallback drill-down, sanitized
   export, retention cleanup, and coverage warnings.
5. **Budgets and alerts:** optional later slice for thresholds and notifications after real usage is
   measured.

An ADR for the Integration-owned execution/telemetry boundary is required before the first
implementation slice. Feature Slice documents are created after this RFC is approved.

## Implementation Progress

- 2026-07-27: The execution-contract and usage-ledger slice is complete on Dev. Integration now
  owns a typed model-attempt executor, normalized nullable usage values, a sanitized
  `ai_model_usage_events` ledger, OpenAI-compatible/OpenRouter/Ollama normalization, ordered
  fallback attempts, and `AiChatResponder` coverage. Migration batch 52 ran on Dev.
- Remaining slices: migrate direct Lead Intelligence and Nextcloud transports with stable
  feature/operation keys; add versioned rate cards; add Admin reporting and retention; then
  consider optional budgets and alerts after measured usage.

## Impact Analysis

- **Integration:** owns execution adapters, normalization, usage ledger, rates, calculations,
  permissions, report, retention, and compatibility with the egress gate.
- **Ticket:** supplies stable context for invoice-text drafting without transferring Ticket ownership.
- **Task:** supplies stable context for AI field suggestions.
- **Signal:** supplies classification feature/operation context.
- **Marketing:** supplies campaign-plan and campaign-email context.
- **Lead Intelligence:** supplies segment/discovery/review/search context and moves web-search
  transport behind Integration.
- **Nextcloud:** supplies folder-match context and moves model transport behind Integration.
- **Work Context:** provides an optional attribution reference; no ownership change.
- **Economy:** no records or behavior change in this RFC.
- **Database:** new append-oriented usage events and later versioned rate-card records.
- **Queue/scheduler:** retention cleanup is introduced only with the reporting/retention slice.
- **Permissions/UI:** new explicit telemetry/rate/report permissions and Bootstrap Admin pages.
- **Privacy/security:** adds actor and source metadata but prohibits payload duplication; must compose
  with the approved data-egress gate.
- **Operations:** migrations, cache clear, permission synchronization, retention scheduling, and HTTP
  smoke tests are required by the relevant slices.

## Data And Migration Plan

- Create the usage-event table with nullable counters and cost fields.
- Do not backfill invented historical usage or zero-cost records.
- Existing provider, agent, chat, and message records remain unchanged in the first migration.
- Add nullable foreign references with delete behavior appropriate for a retained operational ledger.
- Add rate-card tables only in the pricing Feature Slice.
- Add retention configuration and cleanup only when the report can show what will be retained.
- Ensure migration rollback does not delete unrelated AI chats, providers, agents, or domain data.
- Before deployment, estimate event volume and confirm index/query behavior on Dev.

## Testing Plan

- Adapter tests for OpenAI-compatible Chat Completions, Completions, and Responses.
- Adapter tests for Ollama Chat timing and token fields.
- OpenRouter tests for provider-reported cost and cost components.
- Tests for missing, zero, cached, cache-write, reasoning, audio, and non-token usage fields.
- Tests that actual and requested model identifiers remain distinct.
- Tests that provider request IDs and finish reasons are captured when present.
- Tests that one logical execution with endpoint fallback records ordered failed and successful
  attempts.
- Tests that unknown usage/cost remains `null`, never an invented zero.
- Decimal rate-card tests for effective dates, model matching, units, rounding, provider-reported
  precedence, immutable snapshots, and multiple currencies.
- Negative tests proving prompts, answers, headers, credentials, and raw error bodies are absent from
  events and exports.
- Coverage tests proving every known AI call path uses the Integration boundary and supplies a stable
  feature/operation key.
- Authorization tests for report, export, rate management, and actor-level filters.
- Retention tests that remove eligible events without modifying source-domain records.
- Focused Integration and affected-domain Laravel tests on Dev.
- Authenticated Dev HTTP smoke tests for each added Admin surface.

## Documentation Plan

- Integration Knowledge article for model usage, costs, rate sources, and limitations.
- Provider-adapter documentation for supported normalized fields.
- Admin documentation for rate maintenance, incomplete coverage, currency handling, export, and
  retention.
- Developer documentation for registering feature/operation keys and supplying execution context.
- Update `docs/TODO.md`, RFC/ADR/Feature Slice indexes, and `docs/human-review.md` during approved
  implementation slices without overwriting concurrent work.
- Add deployment and rollback instructions for every migration and scheduled cleanup.
- Reference current provider contracts used by the adapters:
  - [OpenAI Chat and Completions API](https://developers.openai.com/api/reference/resources/completions)
  - [OpenAI Responses usage fields](https://platform.openai.com/docs/api-reference/responses-streaming/response/refusal?lang=python)
  - [Ollama usage and duration fields](https://docs.ollama.com/api/usage)
  - [OpenRouter usage accounting](https://openrouter.ai/docs/cookbook/administration/usage-accounting)

## Risks

- A missing provider usage block can create false confidence if reports silently display zero.
- Provider aliases and changing price catalogs can calculate the wrong cost without versioned
  matching and snapshots.
- Endpoint fallback can double count logical operations if reports do not distinguish attempts.
- Storing raw provider metadata can leak payloads or identifiers unless the adapter allowlist is
  strict.
- Actor attribution can become workforce monitoring if reporting permissions and purpose limits are
  weak.
- High event volume can degrade reports without suitable indexes, retention, and aggregate queries.
- Telemetry persistence failure can create gaps; coverage health must make gaps visible.
- A shared execution boundary becomes critical infrastructure and needs broad regression coverage.

## Open Questions

None blocking for RFC approval. The initial retention default/system maximum and exact table/index
names will be confirmed in their approved Feature Slices after inspecting Dev volume and operational
requirements. They may not weaken payload minimization, null semantics, immutable pricing evidence,
or the distinction between usage telemetry and governance audit.

## Approval

Approved by Svein in conversation on 2026-07-27. The approved direction is an Integration-owned
execution boundary, one sanitized event per outbound provider attempt, nullable normalized usage,
provider-reported cost before versioned Nexum calculation, immutable pricing evidence, payload-free
Admin reporting, and no automatic client billing or employee ranking.
