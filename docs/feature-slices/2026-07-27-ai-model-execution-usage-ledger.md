# Feature Slice: AI Model Execution Contract And Usage Ledger

Status: Done
Date: 2026-07-27
Completed: 2026-07-27
Parent: `docs/rfc/2026-07-27-ai-model-usage-and-cost-telemetry.md`
ADR: `docs/adr/2026-07-27-integration-owned-ai-model-execution-telemetry-boundary.md`
Owner: Codex

## Goal

Create the Integration-owned typed execution result and sanitized attempt ledger, then make
`AiChatResponder` record normalized usage for every provider request it performs.

## User-Visible Behavior

Existing AI chat and completion features keep the same output and error behavior. No new settings or
report UI appears in this slice. Successful and failed provider attempts become available as
sanitized operational records for later reporting.

## Scope

- Add an Integration-owned `ai_model_usage_events` migration and model.
- Add typed execution context, normalized usage, and structured result value objects.
- Add a recorder that persists one event per actual outbound provider attempt.
- Preserve one logical execution UUID and ordered attempt numbers across endpoint fallback.
- Normalize supported usage from OpenAI-compatible Chat Completions, Completions, Responses, Ollama,
  and OpenRouter-compatible response bodies already handled by `AiChatResponder`.
- Capture requested/actual model, endpoint kind, provider request ID, finish reason, timing, status,
  nullable usage counters, and allowlisted provider-specific usage metadata.
- Record sanitized failure category/code without prompts, answers, credentials, headers, or raw
  provider error bodies.
- Update `AiChatResponder` to use the typed result internally while preserving its current public
  string-returning behavior for callers.
- Attach provider, agent, chat, pending-message, actor, and correlation context where the current
  `respond()` flow already provides it.
- Use a documented temporary Integration fallback feature/operation key for existing `complete()`
  callers until the complete call-path coverage slice assigns domain-specific catalog keys.
- Emit a structured application error if telemetry persistence fails without changing an otherwise
  successful model response into a user-visible failure.

## Out Of Scope

- Migrating the direct provider HTTP paths in Lead Intelligence and Nextcloud.
- Assigning final feature/operation catalog keys to every domain caller.
- Provider price configuration, calculated cost, or currency conversion.
- Admin reports, export, retention UI, cleanup scheduling, budgets, or alerts.
- Economy transactions, Client invoices, or chargeback.
- Prompt/response retention.
- Implementing the separate organization data-egress policy or privacy gateway.

## Data Touched

- New Integration-owned `ai_model_usage_events` table.
- New Integration model, value objects, and recorder/service classes.
- Existing `AiChatResponder` implementation and focused Integration tests.
- Existing `ai_providers`, `ai_agents`, `ai_chats`, `ai_chat_messages`, and users are referenced only
  through nullable foreign keys; their records are not migrated or rewritten.

The migration does not backfill historical events or invent historical zero usage.

## Permissions

No new UI, route, or permission is added in this slice. Direct database access remains governed by
the application and existing operational access. Explicit report and rate-management permissions are
deferred to later slices.

## Tests

- Migration/model tests for nullable relationships, indexes, casts, and append-oriented records.
- Normalization tests for Chat Completions, Completions, Responses, Ollama, and OpenRouter fields.
- Tests for missing versus explicit zero usage.
- Tests for cached, cache-write, reasoning, audio, and non-token usage when supplied.
- Tests for requested versus actual model, provider request ID, finish reason, duration, and status.
- Tests that endpoint fallback records ordered failed and successful attempts under one logical
  execution.
- Negative assertions that prompt text, model output, credentials, headers, and raw provider error
  bodies are not stored.
- Tests that telemetry persistence failure is explicitly logged without replacing a successful model
  result.
- Existing focused Integration responder/chat tests remain green.
- Dev migration, focused Laravel tests, and an authenticated HTTP smoke test for an existing AI chat
  flow before completion.

## Documentation

- Update Integration module/Knowledge documentation with the execution-event contract and payload
  exclusions.
- Update the parent RFC and this slice status as work progresses.
- Add the required entry to `docs/human-review.md` before completion handoff.
- Add TODO/index updates only after reconciling the concurrent Web Push edits in those shared files.

## Done Criteria

- Every outbound request made by `AiChatResponder` produces one sanitized success or failure event.
- Fallback attempts share one logical execution ID and retain their order.
- Supported provider usage is normalized without converting missing values to zero.
- Existing caller-visible response and error behavior remains compatible.
- No prompt, answer, credential, header, or raw provider error body is persisted.
- Migration and focused Integration tests pass on Dev.
- Existing AI chat behavior passes an authenticated Dev HTTP smoke test.
- Required documentation and human-review entry are present.

## Implementation Result

- Added the Integration-owned `AiModelExecutor`, typed context/trace/result/usage objects,
  `AiUsageRecorder`, `AiModelUsageEvent`, and attempt-ledger migration.
- `AiChatResponder` now records each Chat Completions, Completions, Responses, or Ollama attempt
  while keeping its public string-returning behavior.
- OpenRouter provider-reported cost, OpenAI cache/reasoning/audio details, Ollama timing, actual
  model IDs, provider request IDs, and safe non-token counters are normalized where available.
- Missing fields remain null, explicit zero remains zero, and prompt/answer/error payloads are
  excluded.
- Three-step endpoint fallback retains one logical execution ID and ordered attempt numbers.
- Migration `2026_07_27_120000_create_ai_model_usage_events_table` ran on Dev in batch 52.
- Focused telemetry verification passes with 8 tests and 95 assertions. The affected Integration,
  Signal, Marketing, Lead Intelligence, Task, and Ticket run passes with 307 tests and 2,600
  assertions.
- Repository Knowledge synchronization processed one Integration chapter and five articles with no
  skips.
- The complete Dev Laravel suite passes with 880 tests and 6,720 assertions.
- Authenticated Dev application HTTP chat flows pass in the Integration tests, and the server-side
  HTTPS route returns the expected login redirect for unauthenticated access. Visual browser review
  remains open in `HR-2026-07-27-001` because the in-app browser rejects the known self-signed Dev
  certificate.
