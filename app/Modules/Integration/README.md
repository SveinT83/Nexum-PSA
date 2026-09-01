# Integration Module

The Integration module owns external service configuration for tdPSA. It should expose provider
settings in Admin while keeping provider-specific API clients and sync jobs inside the module or a
clearly owned service namespace.

## Current Scope

- Integration overview under Admin System Integrations.
- N-able RMM settings and sync entry points.
- Tactical RMM settings and sync entry points.
- BookStack connection settings and health check.
- BookStack pull sync into Knowledge and guarded two-way push for shelves, books, chapters, and pages.
- API key management for tdPSA external access.
- Multi-record Email provider connections with normalized endpoints, staged password credentials,
  explicit verification/activation/revocation, and controlled legacy-account migration.
- Provider-neutral AI providers, agents, chat execution, and sanitized attempt-level model usage
  telemetry.

Routes live in `app/Modules/Integration/routes.php`. Controllers live in
`app/Modules/Integration/Controllers`. Views live in `app/Modules/Integration/Views`.

## Email Provider Connections

Integration is the only writer of Email provider endpoints and credentials. Administrators manage
independent connections under **Admin > System > Integrations > Email providers**. Each connection
has one safe display label, normalized IMAP/SMTP configuration, a monotonically versioned password
credential history, an exact active/verified pointer, and append-only metadata-only lifecycle events.
The generic Integration enable/disable form cannot mutate an Email provider connection.

Standard endpoints are fixed to IMAP 993 implicit TLS, IMAP 143 required STARTTLS, SMTP 465 implicit
TLS, and SMTP 587 required STARTTLS. A custom port must match one uniquely named installation entry
in `email_provider_security.additional_endpoints`. Public resolution rejects every mixed or unsafe
answer set and all private, loopback, link-local, metadata, documentation, benchmark, reserved, and
special-purpose destinations. One approved address is pinned for the connection while certificate
and hostname verification continue to use the original normalized hostname, TLS 1.2 or newer, and no
self-signed bypass.

Private/internal endpoints require all of the following: Superuser-only
`integration.email_private_endpoint_manage`, `trust_mode=trusted_private`, a non-empty operator
reason, and an exact named entry in `email_provider_security.trusted_private_cidrs`. Always-denied
destinations are never made available by private trust. The Admin permission
`integration.email_provider_manage` manages public connections; preview, stage, Verify, cutover, and
rollback also require `email.mailbox_sync_manage`. Binding a provider to an Email account additionally
requires `email.account_manage`.

Creating a connection or rotating a secret creates a staged credential. Only explicit **Verify** may
perform DNS and provider authentication. **Activate** accepts only that exact verified configuration
and credential version under provider/account locks, retires the previous version, and destroys its
ciphertext. **Revoke** destroys local ciphertext and blocks new runtime use; it does not claim that a
provider-side password was revoked. Only secret rotation is allowed in place. A username or endpoint
change requires a new connection, explicit account rebind, and mailbox re-baseline.
When PHP-FPM cannot provide the hard signal deadline, the Verify route queues one unique opaque-ID
job on the existing database `email` queue. The CLI Email worker performs the same bounded operation;
no endpoint or credential material enters the job payload.

The legacy migration surface is deliberately staged: read-only preview, local locked re-encryption,
separate provider Verify, pause/drain, exact cutover readiness, source/reference-only cutover, and a
guarded rollback while legacy ciphertext remains intact. Staging performs no DNS lookup, provider
call, send, provider mutation, or source switch. Legacy secret purge is readiness-only and requires a
later named human review plus backup/recovery proof.

Runtime credentials are short-lived, redacted, non-serializable value objects resolved only while the
owning Email provider lock is held. Queue payloads freeze opaque account and positive binding-version
facts, then re-resolve at execution; stale binding, revocation, endpoint/configuration change, or
missing exact verification fails before network I/O. There is no legacy or Laravel system-mailer
fallback. This applies to Mail, Ticket, Sales, Marketing, Commercial, Customer Portal, Storage,
Booking, Notification, and password-reset sends.

Telescope query and model watchers are disabled for this boundary, request values are recursively
redacted, and `/telescope` requires an active Superuser with `system.telescope_view`. Historical local
entries created before these guards must be handled with the bounded
`email-provider:telescope-remediate` preview/hash/purge command. Never substitute a broad
`telescope:clear`: unrelated observability history must be preserved unless a human separately
authorizes its deletion.

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
- Two-way write-back is guarded by the BookStack two-way sync setting and explicit API/admin sync
  actions.
- Synced content should become available to Knowledge search and later to AI retrieval.

The existing `BookStackClient` verifies connectivity through the BookStack books API, pulls visible
shelves, books, chapters, and pages into Knowledge, and pushes pending local Knowledge records back to
BookStack when two-way sync is enabled. Sync stores source metadata and checksums so unchanged records
can be skipped on later runs.

BookStack hierarchy sync must be idempotent. Shelves, books, and chapters are reconciled by
BookStack source metadata first and by the deterministic imported slug second. This lets the sync
repair earlier partial imports where a Knowledge record exists with the correct slug but missing
or stale BookStack source metadata.

## Suggested Build Order

1. Finish BookStack API and sync hardening for Knowledge-compatible records.
2. Add Knowledge source metadata and search/indexing hooks needed by both local and synced content.
3. Create provider-neutral AI Integration models and Admin UI.
4. Install and wrap Laravel AI SDK behind tdPSA-owned services.
5. Build a first internal support agent with strict tool and context boundaries.
6. Add the global AI chat window.
7. Add the shared page-header AI icon and page-context chat.
8. Add embeddings/vector search once Knowledge and BookStack content have stable source metadata.

## Guardrails

- API keys and provider secrets must be encrypted using existing integration secret patterns.
- Email endpoints and credentials must use the dedicated versioned provider lifecycle; do not add
  them to generic Integration settings or back to Email account forms.
- Agents must never bypass tdPSA authorization, tenant boundaries, or module ownership rules.
- Context providers should return structured, auditable context instead of passing entire pages
  blindly to a model.
- AI responses should be logged with provider, model, agent, context source IDs, and token/cost
  metadata where available.
- Every outbound request migrated to the shared execution boundary should create one sanitized
  attempt event. Endpoint fallback attempts must remain individually visible under one logical
  execution.
- Usage telemetry must never copy prompts, answers, credentials, headers, or raw provider errors.
  Missing usage and cost remain null rather than appearing as zero.
- Retrieval should prefer tdPSA Knowledge and synced BookStack content over arbitrary web access for
  operational answers.
