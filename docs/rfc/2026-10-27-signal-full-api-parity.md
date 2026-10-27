# RFC: Full Signal Browser/API Parity

Status: Approved - Done On Dev; human review Reviewed; Main/production separate.
Date: 2026-10-27
Owner: Codex. Product/release owner and intended reviewer: Svein Tore.
Human-review checklist: HR-2026-10-27-SIGNAL-API (Pending; planned scope).

## Context
Svein requested 100% API support for Signal. Authoritative Dev runtime discovery
finds 18 browser route rows (10 canonical workflows including settings and 8 aliases)
and one API operation: POST /api/v1/signals. There is no existing full-parity RFC.
The approved June ingest slice and July builder/recovery RFCs describe existing
behavior; this RFC extends its transport coverage rather than replacing Signal.
Baseline evidence and exact route mapping are in ../plans/2026-10-27-signal-api-*.
At the planning baseline Signal source files were clean; the shared Dev checkout has unrelated
contributor work that must be preserved. Knowledge parity is independently approved and in progress; it is not silently completed, cancelled, or implemented by this work.

## Goals
Cover every supported browser read, write, action and administrative workflow with
typed official API operations, discoverable context, equivalent native actor and
record/field authorization, validation, exact read-back, relevant side effects,
OpenAPI contracts, meaningful tests and current employee documentation.
Full support is accepted workflow coverage, not an endpoint count or read-only API.

## Non-Goals
New Signal producers/action types, rule deletion or Signal editing/deletion where
no browser workflow exists, a second rule engine, public unauthenticated ingest,
blanket human role grants, AI/provider activation, historical queue recovery,
production changes, commits/pushes or Main promotion. #262 runtime reconciliation
and #285 Ticket Rules activation stay separate. No RMM data-model redesign.
No frontend redesign or language-file changes are planned; interface copy is English.

## Current Behavior
The feed supports 7/30/90/custom/all history (30-day default), text search, source,
type, severity/status filters, supported-column sorting and pagination. Details
include payload, related Client/Contact and immutable ordered execution attempts.
Rules have priority, active/stop flags, grouped all/any or legacy conditions and
ordered actions. The form exposes reference catalogs, active actor/template choices,
portal roles and Intake-specific prefill. Settings control AI fallback, confidence,
source/type allowlists, ticket-routing exclusions, grounding prompt and safe agent
status. AI is disabled by default; Integration owns agent/provider configuration.

Normal retry selects failed/unstarted positions not already done, queued or skipped.
Full-rule rerun is explicit and retains stable action keys and linked audit attempts.
Webhook delivery has a durable outbox and pending/running/delivered/failed/unresolved
states; unknown external outcomes are never evidence of delivery or safe replay.
Ten action types are implemented, including Storage supplier-order import and Task
template application. Storage rederives source trust from canonical Email evidence;
a caller-supplied trusted_auth field is never authoritative.

POST ingest currently validates and calls RecordSignal, executing rules by default;
it checks signals.create but has no explicit current native Signal permission check.
Feed/rule/settings browser reads currently call EnsureSignalDefaults, which may seed
permissions and AI defaults. The API must not accidentally introduce privilege
creation or provider configuration as a side effect of an ordinary GET.

## Proposed Change
1. Register domain routes in Signal/routes.php and retain api.php only as an
   idempotent compatibility loader for the existing API loading arrangement.
   Preserve POST /api/v1/signals and process_rules default/false semantics.
2. Add feed/detail, execution history/detail, rules list/detail/create/update,
   settings read/update, rule/feed contexts and bounded lookups. Map both browser
   aliases to the same authority. Include conditions/actions/reference options,
   Intake prefill, current capabilities and safe agent status without secrets.
   Embedded Client/Contact history remains exact-parent scoped; preserve existing
   Clients API coverage and expose missing Contact context through the domain query.
3. Require explicit token abilities and fresh native rights. Proposed abilities:
   signals.read, signals.rules.read/write, signals.settings.read/write,
   signals.actions.execute, signals.providers.execute and signals.operations.read,
   retaining signals.create. Native mappings: signal.view for feed/detail/history
   and rule reads; signal.rule.manage for rule/settings writes and narrow form
   context; signal.action.execute for retry and API ingest. Token abilities do not
   grant native permissions or access to unrelated actor/client/contact records.
   This deliberately tightens legacy scope-only ingest: document that actors need
   signal.action.execute, rather than auto-granting a role. Keep read-back of one's
   command available only while its original action/object authority still holds.
4. Extract shared query, validation and mutation boundaries from the browser
   controllers where needed; reuse SignalRuleDefinition, SignalSettings,
   RecordSignal, ProcessSignalRules and existing downstream actions. Preserve
   legacy JSON and grouped builder semantics, all ten actions, creator/updater
   audit, stop processing and failure behavior. Do not create an API-only engine.
5. Add explicit retry failed/all and side-effect-free preview for API execution.
   Preview shows selected snapshot positions, permitted capabilities and known
   downstream targets without queueing, invoking AI or mutating customer records.
   Execute rechecks current authority and locked execution state; preview is not
   authorization. Require signals.actions.execute for rule processing in addition
   to signals.create on ingest; process_rules=false remains record-only.
6. API-triggered external/provider effects additionally require
   signals.providers.execute, applicable native/downstream rights and a separate
   default-off Signal API external-execution switch. Apply this at a shared
   execution boundary, including existing ingest, without silently enabling a
   configured rule, agent, provider, worker, or scheduler. Document this compatibility
   tightening and return explicit blocked outcomes. Existing non-API producers keep
   their own supported policy and workflow. A rule manager's ability to configure
   automation never becomes unrestricted impersonation of actor_id or a bypass of
   Storage, Ticket, Task, CustomerPortal or Sales authority. Preserve already
   delegated rule ownership only where the existing domain policy authorizes it.
7. Add actor/token-bound encrypted durable command receipts, canonical request
   fingerprints, unique replay keys, opaque state preconditions and explicit
   operation read-back. Require Idempotency-Key for new mutations and If-Match
   for updates/retry against current state. Preserve optional-key compatibility
   for legacy ingest with an explicit warning that an unkeyed retry can duplicate
   input. Same-key/different-payload and stale state return conflicts, not writes.
   Recheck authorization on replay, redact sensitive payload/action fields and
   lock root retry chains across browser/API entry points so concurrent attempts
   cannot duplicate local effects or reuse an execution number.
8. Keep local transactional results separate from committed outbound intent and
   provider receipt. Reuse the existing webhook outbox. Never report queued as
   delivered, reset unresolved deliveries, or blindly rerun a customer invitation
   after an ambiguous outcome. Keep stable action identities and immutable history.
9. Publish complete request/success/error schemas, enum/reference catalogs,
   abilities, compatibility notes and safe examples. Test exact runtime/Swagger
   operation sets and actual successful HTTP responses for every mapped operation.

## Impact Analysis
Signal owns routes, controllers, queries/actions, resources, validation, history,
settings and command storage. Integration owns scope discovery and Swagger scanning.
Native route permission enforcement remains authoritative for browser operations.
Email, Intake, Marketing and RMM remain producers; Ticket, Task, Sales, CustomerPortal,
Taxonomy and Storage retain their existing action/service and permission boundaries.
Client/Contact queries preserve related-history access. Shared-authority changes
require regression tests for web and API callers, including narrow action-only
operators and revoked users. Risks include legacy token rejection, stale replay,
sensitive payload/URL disclosure, concurrent side effects and async outcome ambiguity.
The implementation must audit existing permission delegation and external action
boundaries before enabling any write path. No unrelated contributor file may be
reverted, committed or promoted as part of this change.

## Data And Migration Plan
Plan one additive Signal-owned encrypted command/lock schema with unique actor/token
replay identity. Reuse existing signals/rules/executions/webhook tables and preserve
stable action keys. No business backfill, existing rule rewrite, role assignment,
provider secret storage or destructive history cleanup. Final migration filename
must be recorded after approved implementation and tested only on Dev, using the
exact migration rather than broad migrate in the dirty checkout. Native MySQL probes
must prove uniqueness, locking, rollback and encrypted storage. Preserve APP_KEY.
Future deployment needs the exact additive migration, regenerated Swagger and normal
cache clear; restart workers only if changed deployed job code requires it. No
frontend build is expected. Disable new entry points to recover while retaining
receipts/history; reconcile external unknown outcomes before another attempt.

## Testing Plan
Baseline Signal tests pass: 40 tests / 211 assertions, 2026-10-27 on Dev. This is
existing behavior, not verification of future endpoints. Add feature coverage for
positive and denied native/token/object authority, revoked users/keys, narrow contexts,
all filters/pagination, grouped/legacy rule validation, all ten action definitions,
Intake prefill, settings normalization, exact execution ancestry, safe retry/full
rerun, current actor delegation, provider guards, local idempotency and stale/concurrent
commands. Verify canonical Email trust for Storage and no cross-customer/provider
bypass. Cover pending/failed/unresolved outbox outcomes without real outbound sends.
Run affected Signal/Email/Ticket/Task/Intake/Marketing/Sales/CustomerPortal/Storage/RMM,
Client/Contact and API catalog/Swagger checks as scoped by changed contracts. Include
syntax/Pint/whitespace, exact route/schema tests, native database contracts and trusted
HTTPS auth/read-back probes. Human scenario review is separate from automated tests.

## Documentation Plan
Update Signal README, Knowledge overview and full API guide, API-management and
relevant related-domain handbook guidance. Publish only affected sources through the
supported BookStack integration; read back actual bodies and employee access without
changing permissions. Record concrete blocked publication/owner/next action in TODO.
Queued sync and pending_push do not prove handbook publication. Keep employee
OpenWebUI retrieval separate. Update coverage/evidence, slices, TODO and human-review
in the same session. Follow the website handoff process for completed public-safe
work with explicit Dev-only/do-not-publish status until production is verified.

## Open Questions
No unresolved product choice is required to prepare full current-browser coverage.
The concrete Level 3 design was approved by Svein Tore on 2026-10-27.

## Approval
Approved by Svein Tore on 2026-10-27 with "Godkjenner" in response to this RFC.
Dev implementation is authorized; human scenario review remains Pending.
Existing ingest and automation approvals are retained without extending their scope
by inference. Main and production authorization remain separate.

## Final Verification
Implemented on authoritative Dev. See ../plans/2026-10-27-signal-api-verification.md.
All 18 browser rows map to 19 typed operations / 42 schemas. Human review is
Reviewed; no commit/push/Main promotion/production deployment is performed by this task.
