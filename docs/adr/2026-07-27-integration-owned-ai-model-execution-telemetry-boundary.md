# ADR: Integration-Owned AI Model Execution And Telemetry Boundary

Status: Accepted
Date: 2026-07-27
Decision Makers: Svein / Codex

## Context

Nexum invokes AI providers from several domains. Most calls use Integration's `AiChatResponder`,
while Lead Intelligence web search and Nextcloud folder matching currently issue their own provider
HTTP requests. The response paths extract model text but discard provider request identifiers, the
actual model, token usage, timing, finish details, and reported cost.

Chat messages cannot be the shared accounting boundary because background and non-conversational
workloads do not always create a chat. Provider contracts also differ across OpenAI-compatible Chat
Completions, Completions, Responses, Ollama, and OpenRouter.

The approved organization-controlled AI data-access RFC separately requires one Integration-owned
privacy gate before protected data leaves Nexum. The execution design must compose with that gate
without merging access decisions, model payloads, and operational cost evidence into one record.

## Decision

Integration will own one provider-neutral AI model-execution boundary and the sanitized usage-event
ledger around it.

Domain modules continue to own prompts, source records, authorization, workflow behavior, and
interpretation of model output. They provide a typed execution context and request to Integration
instead of owning provider transport.

The execution boundary will:

- run the approved data-egress policy decision before protected outbound payloads,
- execute provider requests through provider adapters,
- normalize output and usage into typed result objects,
- assign one logical execution UUID to the product operation,
- record one event for every actual outbound provider attempt,
- preserve endpoint retries/fallbacks as ordered attempts under the logical execution,
- retain only allowlisted operational metadata and nullable usage fields, and
- return structured model output to the calling domain.

The usage event does not contain prompts, answers, credentials, headers, or raw provider errors.
Unknown usage or cost remains null rather than becoming zero.

Provider-reported cost will take precedence when available. Later Nexum calculations will use
versioned rate cards and store immutable calculation snapshots. The telemetry boundary does not
create Economy records, Client charges, employee rankings, or governance approvals.

Telemetry persistence errors produce an explicit structured health/error signal but do not convert
an otherwise successful provider result into a failed user workflow. The data-egress policy and its
mandatory access audit keep their separate fail-closed requirements.

## Rationale

- One transport boundary prevents domain-specific HTTP paths from drifting or losing accounting
  fields.
- Attempt-level events accurately represent endpoint fallback, provider fallback, failures, and
  potentially billable repeated requests.
- A separate logical execution ID supports product-level reporting without hiding actual attempts.
- Typed nullable usage avoids false zeroes and provider-specific arrays leaking throughout domains.
- Integration already owns providers, agents, chats, and the approved data-egress policy.
- Payload-free events reduce privacy and retention risk while preserving operational evidence.
- Keeping governance audit, usage accounting, and domain content separate gives each record a clear
  purpose and retention policy.

## Consequences

Positive:

- Every migrated AI path can produce consistent usage and failure evidence.
- New providers need one adapter contract instead of module-specific parsing.
- Feature, agent, model, provider, Work Context, and attempt-level reporting becomes possible.
- Cost calculation can be added later without rewriting domain workflows.
- The privacy gate and execution transport have one composition point.

Negative:

- The shared boundary becomes critical infrastructure and needs broad regression tests.
- Existing call sites need typed context and direct HTTP paths must be migrated.
- Provider adapters require explicit allowlists and maintenance as response contracts evolve.
- Synchronous telemetry writes add database work to model calls.
- A non-blocking telemetry write policy can leave visible gaps when persistence fails, requiring
  health monitoring and possible later outbox hardening.

## Alternatives Considered

- **Store usage only on `ai_chat_messages`.** Rejected because many AI workloads are not chats and one
  logical message can involve several provider attempts.
- **Let each domain log its own provider response.** Rejected because normalization, payload
  filtering, retries, and cost rules would drift.
- **Record one row per logical feature operation.** Rejected because it hides failed or billable
  fallback attempts.
- **Store raw provider responses for later parsing.** Rejected because responses can contain model
  output, sensitive data, and unstable provider-specific structures.
- **Make telemetry failure fail the AI workflow.** Rejected for the initial operational ledger
  because telemetry is not the authorization gate; failures must instead be explicit and measurable.
- **Create Economy costs immediately.** Rejected because internal model cost is not automatically a
  billable Client transaction and financial posting needs its own approved rules.

## Follow-Up

- Implement the parent RFC
  `docs/rfc/2026-07-27-ai-model-usage-and-cost-telemetry.md` through Feature Slices.
- Create the typed execution contract and add-only usage ledger first.
- Migrate every `AiChatResponder` and direct provider-HTTP call path.
- Add versioned rate cards, Admin reporting, retention, and later optional budget alerts.
- Revisit durable outbox handling if coverage monitoring shows unacceptable telemetry loss.
