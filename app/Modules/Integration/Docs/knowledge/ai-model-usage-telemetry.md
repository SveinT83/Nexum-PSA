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

The first telemetry slice records:

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

The current slice covers outbound requests made by `AiChatResponder`. Direct provider transports in
Lead Intelligence web search and Nextcloud folder matching are migrated in the next coverage slice.

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

## Current Operations

The table is `ai_model_usage_events`. Each row is append-oriented and references operational
records through nullable relationships so later chat or user cleanup does not corrupt aggregate
history.

There is no Admin report or rate-card editor in this first slice. Versioned model prices,
calculated cost, reporting, retention controls, and budget alerts are later Feature Slices under the
approved AI Model Usage And Cost Telemetry RFC.
