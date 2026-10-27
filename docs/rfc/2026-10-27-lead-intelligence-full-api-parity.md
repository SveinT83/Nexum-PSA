# RFC: Full Lead Intelligence Browser/API Parity

Status: Approved
Date: 2026-10-27
Owner: Codex
Approver and human reviewer: Svein Tore
Change level: 3 (API, authorization, queued actions and cross-domain writes)

## Context

Svein requested 100% API support for LeadIntelligence. Authoritative Dev currently
has 14 browser route rows (one is a redirect), 11 API operations and zero matching
operations in generated Swagger. Existing foundations, promotion, scheduling and
AI-led worker RFCs remain approved history; they do not approve this new contract.
Baseline tests pass: 33 tests / 277 assertions. See the linked coverage matrix.

## Goals

Expose every currently supported read, write, action and settings workflow through
typed official APIs, including existing API-only evaluation and promotion. Preserve
current user, record, field, consent/suppression, AI approval and provider authority.
Provide discoverable form context, exact read-back, strict validation, safe retries,
complete Swagger and meaningful regression tests. A route count alone is not parity.

## Non-Goals

New crawling sources, deeper discovery, manual-review products, email delivery,
new segment/run/ledger deletion or run cancellation, worker/scheduler activation,
role grants, independent NexumMCP implementation and production rollout.
These are not supported browser capabilities or are separate release actions.

## Baseline Behavior Before This RFC

- Settings GET/PATCH, segment list/create/detail/update, run create/detail,
  ledger list, contact eligibility evaluation and candidate promotion exist.
- Segment AI draft, Run Now, run list and form context lack APIs.
- Run evidence API omits the Client/Contact identities displayed by the browser.
- Browser routes require an active internal actor and sales.lead_manage. Existing
  APIs only have Sanctum abilities, without the equivalent domain actor guard.
- Segment nested validation is weaker in API than web. Settings PATCH does not
  enforce the web form's required endpoint when enabled endpoint search is selected.
- Some browser GETs seed/update an Integration AI agent; API reads must stay pure.
- Run Now and queued run creation dispatch ExecuteLeadResearchRunJob; queue acceptance
  is not discovery completion, provider success or evidence-backed promotion.
- Promotion directly inserts Clients with a local number and falls back from company
  email to billing_email. Clients/CreateClientRecord now owns Tripletex authority.
  This mismatch must be resolved through the shared Client boundary, not duplicated.

## Proposed Change

### Official contract and complete discovery

Retain all 11 current paths and operations. Add the following six operations, for
an expected 17 HTTP operations, excluding HEAD (final set must be mechanically verified):

| Method | Path under /api/v1 | Purpose | Ability |
| --- | --- | --- | --- |
| GET | lead-intelligence/context | Defaults, enums, capabilities, accessible Marketing lists, AI availability and provider prerequisites; optional existing segment context | lead-intelligence.read |
| POST | lead-segments/ai-draft | Editable AI suggestion; does not save a segment | lead-intelligence.manage |
| POST | lead-segments/{segment}/run-now | One immediate queued run through the shared worker pipeline | lead-intelligence.run |
| GET | lead-research-runs | Bounded pagination/filtering and exact state/segment summaries | lead-intelligence.read |
| GET | lead-research-runs/{run}/evidence | Bounded source evidence, including authorized Client/Contact identities | lead-intelligence.read |
| GET | lead-intelligence/operations/{command} | Current-actor authorized durable command outcome/read-back | Original operation ability |

Extend existing segment responses with browser-visible research-run count/context;
extend run details with equivalent evidence identities without leaking inaccessible
related objects. Publish concrete request/response/error schemas for all operations,
including known worker summaries, source evidence, eligibility, dry-run versus actual
promotion, pagination, revisions, capabilities and unresolved execution states.
Document intentionally provider-owned variable metadata instead of inventing fields.

### Equivalent authority and input handling

All legacy and new API routes require active authenticated internal actors with
sales.lead_manage AND the required token ability. Reuse native policies/access rules
for referenced Clients, Contacts, Marketing lists, runs and evidence; do not invent a
new admin-only role gate because the Settings navigation badge is informational.
Scope context/lookups to the actual workflow and current actor. A foreign evidence
or unrelated contact/client pair is rejected, including on previews and receipt reads.
Recheck current actor/token/record permissions before replay and queued side effects.

Use shared domain Actions/requests/serializers for web and API mutation semantics,
with separate form normalization and correct PATCH omission handling. Reject unknown
or reserved fields and invalid nested arrays, weekdays, list IDs, budget/schedule
values and endpoint configuration. Preserve and explicitly document supported legacy
JSON column aliases; reject conflicting alias/public-name values. API reads must not
seed agents, mutate settings, call providers or enqueue jobs.

### Durable writes and external effects

Add encrypted, actor-bound command receipts with request hashes, stable keys,
revision/If-Match validation and concurrency locking. Apply to existing and new writes.
Same key/same input returns the original outcome without duplicate rows, jobs, AI
charges or Marketing members. Changed input/key reuse and stale writes return typed
conflicts. Revoked authorization blocks receipt access. Settings/segment changes,
run creation and evaluation have transactional local persistence; dispatch occurs
only after durable local acceptance. Provide a recoverable dispatch state when queue
submission fails. Freeze validated promotion input and target IDs before side effects.

Keep one shared worker path and recheck initiating API actor/rights at execution;
keep scheduled/system runs explicitly distinct. Do not turn an API request into
unrestricted system authority. AI draft invokes the existing Integration action,
resolver, telemetry and external-AI approval. New API-origin external AI/discovery or
provider execution remains default-off until explicitly enabled. Unknown provider
outcomes are retained for reconciliation and never blindly replayed.

Route new Client creation through Clients/CreateClientRecord, retaining local mode
when no Tripletex connection is active. In provider mode persist one UUID request key,
require tripletex_number_mode=true, omit caller client_number, and reconcile unknown
attempts before retry. Keep shared worker and direct promotion behavior consistent.
Provider calls must not be hidden inside an automatic database retry. Preserve
ContactRelation/legacy bridge, source evidence and Marketing list exclusions. When
org_no is blank, only explicit company.billing_email can become billing_email; never
infer an invoice address from company/contact email. Existing source/customer data is
not backfilled. Preview must be read-only and describe possible effects truthfully.

## Impact Analysis

LeadIntelligence owns routes, context, commands, validation, resources and tests.
Integration owns token catalogs, Swagger scanning, AI resolution, telemetry/approval.
Clients/DataExchange retain customer creation and Tripletex numbering authority.
Contact retains identity/relation/bridge writes. Marketing retains membership,
exclusions and suppression rules. Existing five dirty LeadIntelligence views are
Jonathan's closed #280 badge work and must be preserved byte-for-byte. #154 is closed;
retain the verified every-minute planner wiring and regression coverage.
The initial planning snapshot found separate Knowledge/Signal work. Those workstreams
and their later Main promotions were retained; this implementation remained scoped.
If shared Integration/Knowledge files overlap at implementation time, re-audit and
apply only the scoped additive change without overwriting contributor work.

## Data And Migration Plan

Plan one additive lead_intelligence_api_commands table and locking/dispatch metadata;
final migration filename/schema must be recorded and native-MySQL verified before use.
No business-row backfill, blanket migration run, token-grant migration or data deletion.
On Dev run only the approved exact migration, regenerate Swagger and clear affected
caches. Use normal existing workers; do not start workers/scheduler or change live
policy as a side effect of implementation. Verify an external every-minute scheduler
runner separately before claiming scheduled jobs healthy. Preserve APP_KEY and durable
receipts during recovery; disable new entry points and reconcile unresolved effects.
Production migration and rollout require separate authorization. Main source promotion
was explicitly authorized and is complete in PR #343.

## Testing Plan

Run meaningful regression tests for every matrix row and exact runtime/Swagger set.
Cover actor+ability denials, inactive/portal actors, foreign parents/records, field
validation, pure reads, defaults, PATCH preservation, evidence labels, draft/queued
runs, one-job Run Now, queue failure/recovery, AI unavailable/denied/error/unknown,
idempotency across restart/concurrency, stale revisions, revocation and safe receipts.
Cover suppression/do_not_email/private-address policy, list exclusions, contact bridge,
read-only dry run, exact IDs and duplicate prevention; fake all real provider effects.
Test local and Tripletex customer-number mode and explicit invoice email handling.
Run LeadIntelligence plus affected Marketing, Contact, Clients, Integration/API access,
DataExchange numbering and production Swagger tests on authoritative Dev. Verify native
MySQL lock/unique/encryption contracts and trusted HTTPS/Swagger reads using minimal
revoked-after-use test tokens. No live customer/provider writes as routine tests.

## Documentation Plan

Update module README, API guide, authoritative Knowledge source and API Management
catalog. Publish/read back actual affected employee BookStack articles with existing
access scope and truthful Dev/Main/production status; pending_push alone is insufficient.
If blocked, record exact blocker, owner and next action in TODO. Employee OpenWebUI
retrieval remains a separate check. Maintain matrix, verification evidence, TODO and
HR-2026-10-27-LEAD-INTELLIGENCE-API. Add public-safe website handoff only after verified
implementation, with explicit publication gates.

## Sequential Feature Slices

See ../feature-slices/2026-10-27-lead-intelligence-full-api-parity.md. Execute one slice
at a time after this RFC is approved; final completion requires all coverage and docs.

## Open Questions

No blocking product questions remain. Svein Tore approved this concrete contract.

## Approval

Svein Tore approved the concrete RFC on 2026-10-27: "Godkjenner og merge til main".
Dev implementation, verification, scoped commit/push and tested Main promotion are
authorized. Production deployment/provider activation remains separately gated.
Human-review scenarios remain Pending; design approval is not witnessed scenario review.

## Implementation Status

All six slices are complete on Dev and merged to Main in PR #343. See the exact
verification and Main-promotion records; production and human scenarios remain separate.
