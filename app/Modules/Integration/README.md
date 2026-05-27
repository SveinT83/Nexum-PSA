# Integration Module

The Integration module owns external service configuration for tdPSA. It should expose provider
settings in Admin while keeping provider-specific API clients and sync jobs inside the module or a
clearly owned service namespace.

## Current Scope

- Integration overview under Admin System Integrations.
- N-able RMM settings and sync entry points.
- Tactical RMM settings and sync entry points.
- BookStack connection settings and health check.
- BookStack manual read-only sync into Knowledge articles.
- API key management for tdPSA external access.

Routes live in `app/Modules/Integration/routes.php`. Controllers live in
`app/Modules/Integration/Controllers`. Views live in `app/Modules/Integration/Views`.

## Product Direction

### AI Integrations

tdPSA should replace the current single-purpose "OpenAI" integration concept with a provider-neutral
AI Integrations area:

- Admin path: `Admin -> Integrations -> AI Integrations`.
- The UI should allow multiple AI providers to be configured at the same time.
- Each provider connection should support one or more encrypted API keys.
- Provider records should track enabled state, health, default model choices, cost/rate metadata
  where practical, and intended use cases such as chat, embeddings, reranking, file search, or audio.
- Agent records should define the assistant role, instructions, selected provider/model, available
  tools, fallback providers, memory policy, and allowed context sources.
- The implementation should use Laravel's first-party AI SDK where available. As of May 14, 2026,
  Laravel documents the AI SDK as a first-party package with agents, tools, memory, streaming,
  embeddings, vector stores, RAG, and multi-provider support.

Planned assistant surfaces:

- A page-context AI icon in every page header.
- Page chat should receive scoped context from the current page, current record, visible metadata,
  route, permissions, and relevant Knowledge or BookStack-backed content.
- A global AI chat window should be available independently of the current page.
- Global chat should support broader workspace context while still respecting permissions, tenant
  boundaries, and configured agent/tool access.

Implementation note: the page-header icon should be added through the shared layout/header component,
not copied into every module view.

### BookStack Integration

BookStack should be completed before broad AI chat rollout because it is a key external knowledge
source for retrieval and grounding.

BookStack must not replace tdPSA Knowledge. The target model is synchronization:

- tdPSA Knowledge remains the internal source of truth for PSA-native articles, tags, ownership,
  review state, and client/workflow context.
- BookStack remains an external documentation source that can be imported, mirrored, or linked.
- Sync should map BookStack shelves, books, chapters, and pages into tdPSA Knowledge structures.
- Sync should preserve external IDs, source URLs, checksums, last synced timestamps, and conflict
  status.
- The first production sync path should be read-only from BookStack into tdPSA unless a specific
  write-back workflow is designed and approved.
- Synced content should become available to Knowledge search and later to AI retrieval.

The existing `BookStackClient` verifies connectivity through the BookStack books API and can pull
visible pages through the BookStack pages API. Manual sync stores pages as Knowledge articles with
BookStack source metadata and checksums so unchanged pages can be skipped on later runs.

## Suggested Build Order

1. Finish BookStack read sync into Knowledge-compatible records.
2. Add Knowledge source metadata and search/indexing hooks needed by both local and synced content.
3. Create provider-neutral AI Integration models and Admin UI.
4. Install and wrap Laravel AI SDK behind tdPSA-owned services.
5. Build a first internal support agent with strict tool and context boundaries.
6. Add the global AI chat window.
7. Add the shared page-header AI icon and page-context chat.
8. Add embeddings/vector search once Knowledge and BookStack content have stable source metadata.

## Guardrails

- API keys and provider secrets must be encrypted using existing integration secret patterns.
- Agents must never bypass tdPSA authorization, tenant boundaries, or module ownership rules.
- Context providers should return structured, auditable context instead of passing entire pages
  blindly to a model.
- AI responses should be logged with provider, model, agent, context source IDs, and token/cost
  metadata where available.
- Retrieval should prefer tdPSA Knowledge and synced BookStack content over arbitrary web access for
  operational answers.
