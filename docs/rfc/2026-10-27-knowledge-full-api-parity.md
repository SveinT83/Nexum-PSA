# RFC: Full Knowledge Browser/API Parity

Status: Approved by Svein Tore, 2026-10-27. Implementation: Done On Dev; Merged to Main in PR #331; production unchanged.
Date: 2026-10-27
Owner: Codex. Product/release owner and intended human reviewer: Svein Tore.
Human review: HR-2026-10-27-KNOWLEDGE-API (Reviewed by Svein Tore, 2026-10-27).

## Context
Svein requested 100% API support for Knowledge and Documentation. Fresh authoritative
Dev route discovery finds Knowledge has 37 browser rows (35 staff/admin, 2 portal)
and 20 API route rows / 24 HTTP operations, excluding HEAD. Documentation already
has 30 browser rows mapped to 33 typed operations and 40 schemas; PR #329 is merged
and production deployment is separate. Do not duplicate that completed work.

Knowledge's API supports article and hierarchy CRUD but lacks revision review and
actions, conflict handling, settings, portal reads, form discovery, tag operations,
and the Integration-owned Sync Now action used inside Knowledge. Legacy endpoints
check Sanctum abilities but do not consistently check current actor permissions.
Several hierarchy methods lack OpenAPI declarations and article responses are
untyped or describe direct mutation even though writes now produce proposals.

#266 and #276 are implemented on Dev and closed. Their review gates remain
HR-2026-09-04-001 and HR-2026-09-03-003. Open #265/#261 define future audiences
and AI retrieval; #275 defines future Documentation revisions. They are separate
workstreams and must not be claimed implemented by present-browser parity.
The shared Dev checkout contains existing contributor work; preserve it in place.

## Goals
Expose every currently supported browser read, write, action, administration and
portal workflow through typed official API operations. Require both explicit token
abilities and current native actor/object rights. Include exact result read-back,
validation, replay/concurrency protection, shared side effects, Swagger, meaningful
feature tests, module/handbook sources and actual BookStack publication read-back.
Full support is acceptance-based coverage, not an endpoint-count percentage.

## Non-Goals
Implementing #265 role/team audiences, #261 AI retrieval or #275 Documentation
lifecycle; changing human role grants; publishing real customer content; automatically
approving revisions; enabling provider sync switches; committing/pushing Dev,
deploying production. Svein separately authorized Main merge on 2026-10-27. Existing human scenario reviews remain open; no completed review is inferred.
No general BookStack credential/settings API or generic Taxonomy redesign.

## Current Behavior
Articles are the published projection. StoreArticle/UpdateArticle create immutable
proposals; approval, publication, retry and rollback have separate authorities.
KnowledgeRevisionAccess supports exact linked Ticket AI review without library access.
Repository-owned articles reject ordinary/AI proposals. #276 owns three-way sync,
candidate conflicts, exact outbound revision binding and provider read-back.
The Livewire editor owns field selection/reset/save; the shared tag manager additionally
attaches/removes/creates tags on saved articles. Browser article reads increment views.
Portal reads require current account/contact/membership and expose published public
or own-client-wide articles only. Shelf/book/chapter deletion requires emptiness;
BookStack-owned deletion can perform remote work when the existing policy permits it.

## Proposed Change
1. Put all Knowledge API registrations in Knowledge/routes.php, retaining api.php as a
   compatibility loader. Preserve URLs, supported PUT/PATCH and knowledge.read/create/update.
2. Apply native rights to legacy routes: knowledge.view for browsing;
   knowledge.manage_drafts for article proposals; knowledge.create/update for corresponding
   structure writes; knowledge.delete for deletion. Token scopes never grant these rights.
   Narrow form owners may read only the exact context needed for their authorized action.
3. Add context and bounded lookup endpoints covering defaults, capability flags, hierarchy,
   categories, active clients, tags, parent-child selections and safe provider status.
   Add full article hierarchy reading order, source metadata, revision pointer/history,
   review dates, related labels and view-count side effects matching the browser.
4. Add article revision list and exact detail with full snapshot, concise diff, events and
   permitted actions. Expose approve/reject/publish/retry/rollback through existing actions.
   Use narrow knowledge.revisions.read/approve/publish/rollback token abilities plus current
   native checks. Ticket-only review may access only the exact authorized AI revision;
   it never grants article/library listing, other revisions, rejection or publication.
5. Expose current conflict state and accept-remote/keep-nexum using shared actions with web.
   Accept-remote submits the immutable candidate; it does not approve or publish it.
   Keep-nexum binds the exact published revision for downstream read-back; queued is not synced.
6. Add knowledge.settings.read/write for existing defaults, mapped to
   knowledge.manage_settings, sharing its validation and persistence. No secret settings.
7. Cover tag search, creation/attachment/removal and module pivot semantics; require current
   Knowledge mutation permission plus token ability. Reuse the Taxonomy model and keep
   existing published-content/revision behavior explicit. Harden only the Knowledge branch
   of shared components if necessary; do not introduce a parallel tag register.
8. Expose the library's Sync Now as an explicit pull action, with current Integration-owned
   authorization, knowledge.sync ability and same existing sync action/service. Record sanitized
   summary and precise outcome, not credentials. Provider writes/deletion/conflict outbound
   additionally require knowledge.providers.execute and a new default-off API execution switch;
   availability never enables the integration, grants or worker itself. Do not automatically
   retry an ambiguous provider action; reconcile first. Safe status reads remain usable.
9. Add portal list/detail under a distinct knowledge.portal.read ability, supporting bearer
   and existing portal session auth. Resolve the current caller's active membership explicitly;
   reject foreign/revoked membership. Reuse PortalKnowledgeAccess and return only portal fields.
10. Add actor/token-bound encrypted durable local command receipts with a unique replay key,
    canonical payload fingerprint and opaque state preconditions. Require Idempotency-Key on
    new writes; preserve legacy optional-key compatibility with documented safe retry limits.
    Recheck original authority on replay and operation lookup. Keep local effects/receipt atomic,
    provider phases separate and uncertain outcomes visible. Revision guards remain authoritative.
11. Publish complete typed request/success/error schemas, scopes and examples. Validate every
    successful HTTP method against Swagger and assert exact runtime/documented operation sets.

## Impact Analysis
Knowledge owns domain actions, controllers, resources, queries, routes, validation and tests.
Integration owns API ability discovery and existing BookStack jobs/provider boundary; no credentials
are returned. Taxonomy owns tag rows/pivots. CustomerPortal owns account and membership resolution.
Ticket's existing exact linked AI review boundary must retain its existing tests and behavior.
Documentation/Storage APIs share legacy knowledge scopes and require regression checks; changing
Knowledge must not widen or break their authorization. No UI redesign or translation is planned.
Risks: scope-only legacy tokens will correctly fail when their actor lacks native rights;
replay must not disclose revoked data; concurrent publication must preserve immutable history;
remote deletion must never be replayed after an uncertain result. Broad shared Dev changes are
not this task's release manifest. Existing #265 unresolved audience behavior is not silently changed.

## Data And Migration Plan
Plan one additive Knowledge-owned command/lock schema, encrypted result storage and replay-key
uniqueness, following the existing Asset/Storage/Documentation patterns. No article backfill,
content rewrite or role grant. Verify exact migration on Dev only after RFC approval; run native
MySQL lock/unique/rollback/encryption probes. Preserve APP_KEY and history. Deployment later
requires the exact additive migration, generated Swagger, normal optimize/cache clear and worker
restart only if deployed job code changes. No frontend build is anticipated. Do not run a broad
migrate command in the shared checkout or turn on a scheduler/provider/worker as an incidental step.
Rollback disables new API/provider entry points and preserves all receipts/revision history.

## Testing Plan
Sequential feature suites cover fresh actor plus token rights, revocation, unsupported input,
bounded filtering, structure mutation/deletion, hierarchy mismatch, proposal/publication separation,
exact Ticket-only review and negative isolation, stale/conflicting approvals, local publication,
provider read-back/failure/retry/ambiguity, rollback, repository authority, tags, defaults,
portal membership/client isolation, encrypted replay and stale preconditions. Include current
Knowledge, BookStackRevisionSafety, Ticket review, CustomerPortal, Documentation contract/parity,
API catalog/Swagger and scoped Taxonomy regressions. No real customer/provider writes in tests.
Run scoped Pint/syntax/whitespace, native database probes and trusted HTTPS auth/schema checks.
A complete domain claim requires all coverage rows and actual typed success/error tests.
Human checks remain separate and no Pending checklist becomes Reviewed from automated results.

## Documentation Plan
Update Knowledge README, overview/settings and a full API guide, API-management and relevant
BookStack/portal guidance. Sync only affected source articles; read actual BookStack bodies and
employee access without changing permissions. Record any connector/sync blockers with owner and
next action in TODO. Do not call handbook complete from pending_push or queue acceptance.
Update matrix, verification evidence, Feature Slices, TODO and human-review in the same session.
Use the website handoff process only for verified customer-safe completed work; mark Dev-only
content do not publish until production availability is confirmed.

## Open Questions
No unresolved product choice remains for present-browser parity. RFC approved before implementation.

## Approval
Svein Tore explicitly replied "Ja og merge til main" on 2026-10-27. This approves the concrete
RFC and authorizes implementation, scoped commit/PR and tested Main merge. It does not mark
manual scenarios Reviewed or authorize production rollout/provider activation. Main promotion
is explicitly authorized while the historical and new human scenario reviews remain Pending;
production/release gates remain in effect.

## Verified Main Merge (2026-10-27)

https://github.com/SveinT83/Nexum-PSA/pull/331 is merged at 5dab62d56c7663368de11d0f5961e08d7e269bd3.
The merged tree equals the tested candidate: c134e03cc5cb5c1be5193f15993b5906d62f4102.
No Main implementation action remains. Human scenarios and production gates remain separate.
This status-only follow-up does not change runtime code; application tests were not rerun.

## Named Human Acceptance (2026-10-27)

Svein Tore explicitly accepted the completed Knowledge API handoff with
"Godkjenner og merge til main". HR-2026-10-27-KNOWLEDGE-API is Reviewed.
This records the named reviewer's acceptance of the presented scope; individual
scenario executions were not separately reported and are not invented. Main was
already merged in PR #331, with merge evidence reconciled in PR #332.
The older HR-2026-09-04-001 and HR-2026-09-03-003 reviews remain independent and
Pending. This acceptance does not authorize production deployment or provider activation.
This follow-up changes documentation/acceptance status only; application tests were
not rerun because executable code, configuration and OpenAPI are unchanged.
