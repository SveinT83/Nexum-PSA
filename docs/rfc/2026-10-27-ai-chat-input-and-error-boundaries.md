# RFC: AI Chat Input And Provider Error Boundaries

Status: Approved
Date: 2026-10-27
Owner: Codex / Svein Tore

## Context

Issues #269 and #278 document existing credential persistence and raw provider error leaks.
The controlling user request is "Da kan du ta å i verksette. Fix alt og merge til main".
This authorizes completing the concrete reviewed Issue requirements and tested Main merge.
It does not approve production rollout, a new Vault runtime, or an external data recipient.

## Goals

Reject credential-like technician chat input before title/message persistence, ordinary
validation old-input flashing, pending replies, jobs or provider calls. All current chat
entry points share a reusable detector; rejection never echoes the suspect value.
Normalize provider/transport/parse errors into stable categories, safe next action/retry
advice and correlation; no raw body, exception chain, endpoint or credentials in chat,
shared provider status, structured outcomes, telemetry, logs or queue failure state.
Keep legitimate chat, policy enforcement, fallback attempts and caller signatures intact.

## Non-Goals

Vault activation, secret rotation, historical chat/telemetry cleanup, new write tools,
provider SDK replacement, new permissions or production/provider activation.

## Current Behavior

AiChatController stores raw title/message before provider policy; ContextChat stores raw
message and public context. Standard web validation flashes request input and Livewire
snapshots public properties. AiChatResponder catches raw errors into assistant/provider
status and builds errors from response bodies. AiModelExecutor rethrows raw exceptions.
AiModelResult and StructuredAiProviderClient accept provider-supplied error codes.

## Proposed Change

Slice 1: one bounded credential detector handles labelled credentials, authorization,
known token/key forms, private keys and equivalent common text encodings. Apply before
ordinary HTTP/Livewire validation. Clear rejected request/public text and old-input state;
return static safe guidance via a separate authorized credential-destination resolver.
The existing encrypted provider administration form is only an interim eligible target;
other credential types use the authorized-form/administrator fallback, with no guessed route.
Slice 2: central normalized error object/exception, no original previous exception or HTTP
response retained. Map HTTP status and exception type using allowlisted categories; allowlist
provider error/finish codes and sanitize metadata before persistence. Preserve fallback
logic in memory, separate attempt numbers and structured result statuses/reason compatibility.

## Impact Analysis

Integration HTTP chat, Livewire chat, provider execution/result/structured paths and tests.
No routes, actor rights, database migration, source-data backfill, provider config, queue
activation or scheduler change. Existing source files may have unrelated shared Dev edits;
preserve them and assemble a scoped candidate against fresh origin/main.

## Data And Migration Plan

No schema migration. Existing unsafe history is not silently rewritten. Later production
rollout refreshes normal caches and gracefully refreshes already-approved workers.
Rollback is a source rollback, retaining existing execution/audit evidence and credentials.

## Testing Plan

Secret-canary tests cover HTTP create/message/title, malformed/oversize validation,
Livewire dehydration, alternate encodings and rejected-side-effect absence. Unit/feature
tests cover error categories, raw body/code/exception canaries, empty/malformed responses,
transport and safe structured failure plus legitimate chat and fallback regressions.
A fresh read-only investigator and candidate reviewer challenge the exact boundaries.

## Documentation Plan

Update Integration AI chat/provider Knowledge sources, publish/read back exact handbook
sections without changing access, and reconcile TODO/Issues and the human-review entry.

## Open Questions

No product decision blocks these bounded fixes. No pattern detector can recognize arbitrary
unlabelled random text as a credential; document supported detection and never advertise a
credential-management chat surface. Historical cleanup needs separate explicit approval.

## Approval

Svein Tore, 2026-10-27: "Fix alt og merge til main", following the concrete Issue audit.
Main promotion is authorized after tests; human scenario review remains Pending and gates
production rollout, not this explicitly requested tested merge.
