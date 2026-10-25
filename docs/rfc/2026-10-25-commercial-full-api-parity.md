# RFC: Full Commercial Browser And API Coverage

Status: Approved / Implemented and verified on Dev; human review Reviewed (Svein Tore, 2026-10-25)
Date: 2026-10-25
Owner: Codex. Product/design/human-review/release owner: Svein Tore.
Change level: Level 3 - API, authorization, command/delivery evidence and cross-module contracts.
Evidence: [gap matrix](../plans/2026-10-25-commercial-api-gap-matrix.md) and [inventory](../plans/2026-10-25-commercial-api-inventory.json).
Implementation: In Progress on authoritative Dev; approved by Svein Tore on 2026-10-25.

## Context

Svein requested 100% Commercial API support: everything possible through the web must also be
possible through the API. This includes reads, writes, supported deletes, actions, form context,
calculations, administration, public links and Customer Portal workflows. Existing foundation
endpoints are not evidence or approval of complete operational parity.

Authoritative Dev is `/var/Projects/tdPSA`, branch `Dev`. The baseline contains 73 Commercial
browser route entries, 18 API route entries / 22 HTTP operations excluding HEAD, and six Livewire
components. Counts include forms, aliases, hubs and one dead SLA delete declaration; they cannot
produce a meaningful coverage percentage. The inventory records actual routes and source hashes.
Preserve the shared dirty working copy, especially Commercial export/PDF work and shared API,
Integration, TODO and human-review surfaces.

Work-in-progress reconciliation: Sales API is human-reviewed and merged through PR #311; production
rollout is separate. Commercial document/pricing implementation is Done and its authoritative
`HR-2026-08-24-003` is Reviewed. An older TODO overview still says pending; do not infer a new review
blocker or current production facts from that summary. History export is Done On Dev with
`HR-2026-09-27-WORKLOG` Pending before external activation. Preserve that scope rather than enabling
a workload. Read-only GitHub searches on 2026-10-25 found no open Issue matching Commercial. No
Issue/Discussion/comment was created. This is the next Commercial workstream.

## Goals

1. Every supported web workflow and field has an official typed API, including reads, mutations,
   supported deletes, actions, calculations, lookups and administration.
2. Browser and API share authoritative operations, calculations, validation and authorization,
   producing equivalent persisted results and relevant side effects.
3. Require current active actor, domain/object/parent/field rights and token scope. Keep staff,
   public bearer capability and authenticated customer identity separate.
4. Preserve negotiated economics, integration ownership, immutable sent/accepted evidence, legal
   review, client-only Commercial ownership and safe timebank/Economy behavior.
5. Safe retries/concurrency, explicit communication intent, truthful operation/delivery read-back,
   complete OpenAPI, meaningful Dev tests and verified employee documentation.
6. Claim 100% only after every workflow, field and relevant side effect has evidence; endpoint
   counts or generic success responses do not prove parity.

## Non-Goals

No new pricing/billing engine, arbitrary recurrence, guessed historic identity/evidence, new SLA
delete semantics, unsupported rate/unit deletion, provider provisioning, auto-approval, automatic
email, permission/token widening, Main promotion, production deployment or runtime activation.
Economy orders, Client identity, Ticket/Task time and Integration sync keep their owner APIs.
These boundaries do not exclude any currently supported Commercial workflow from API access.

## Current Behavior

The staff API lists/reads/creates/updates Services, Contracts, SLAs and Time Rates. Contract mutation
covers metadata only. Service mutation omits web cost/legal/time-rate composition. Two distinct
coordinator worklog reads provide pseudonymized consumption and contract links under workload/context
policy; these are not staff balance or quick-registration endpoints.

Web-only capabilities include packages, costs, legal versions, units, quick-timebank policy and
registration, Contract line editing, terms review/refresh/save, PDF, send/resend, manual approval,
legacy attestation, public viewing/acceptance and Portal viewing/acceptance. Livewire adds calculations
and composition beyond controller routes. The matrix maps every registered browser entry and these
component workflows.

SLA delete is registered but `SlaController::destroy` is absent; no delete control was found in SLA
views. This is a defect to resolve explicitly, not implemented deletion. Legacy Admin rate POST/PUT
handlers only redirect. Contract/service settings pages are discovery hubs rather than unimplemented
global settings. Do not manufacture mutations to mirror aliases.

## Proposed Change

### Complete operational surface

Expose versioned APIs for every C01-C18 matrix workflow:

- Contracts: list/filter/sort/detail/create/edit/delete-draft; complete context and readiness;
  lines/read/add/edit/remove; service/offer/rate/SLA lookups and quantity defaults; exact prices,
  discounts, setup fees, cadence/currency, customer text/units and SLA/rate snapshots; preview and
  explicit draft Service billing-interval adoption.
- Contract terms: source/version/snapshot discovery, exact text/fingerprints/readiness, explicit
  refresh and manual save. Reads never persist generated wording or mark it reviewed.
- Contract actions: send quote/agreement, resend, manual approve, named legacy attestation, existing
  document/PDF output and exact command/delivery evidence.
- Services: complete fields/filter/sort/context and supported CRUD; costs/legal/rate composition,
  add-on/audience selections and pricing previews, preserving active source Integration locks.
- Packages: CRUD/context, service/term composition, inherited terms, supported per-unit prices and
  aggregate cost/sale/margin previews. Preview calculations remain read-only.
- Costs and Legal Terms/versions: full supported fields/filter/sort/context/CRUD, version creation,
  source/provider/in-use guards and existing references.
- SLA/defaults and Time Rates: all supported fields, context/enums, default selection, application,
  active/visibility/sort behavior and consumer regressions. No invented deletion.
- Units: actual Admin read/create/update. Settings: actual quick-timebank policy read/update and
  typed discovery of implemented hubs/canonical capabilities.
- Client timebank: eligible Contract lines/rates, balances/period/context, quick registration with
  policy/limits/note/active-status/user/overuse checks and exact rate/period/entry snapshots. Reuse
  supported Client/Ticket time-correction and Economy owner APIs; preserve separate actual time,
  Commercial quick consumption and billing-allocation semantics.
- Public and Portal workflows: typed customer-safe views/context and explicit acceptance with the
  existing immutable evidence, legal/audit and notification effects.

Register domain routes in `Commercial/routes.php`; existing `Commercial/api.php` can remain a
compatible loader shim. Shared `routes/api.php` may load explicit domain/public/portal branches only.
No domain business logic in shared entry files, new route files or packages. Controllers/Livewire
delegate to shared Commercial actions/queries rather than calling the web over HTTP or fabricating
controller requests. Reuse the existing tested workflow helpers.

### Authorization and typed contract

Require Sanctum ability AND current active staff/tech actor and effective web domain/object rights.
The web map currently uses `commercial.contract_manage` (also Legal), `service_manage`,
`package_manage`, `cost_manage`, `sla_manage` and `rate_manage`; hubs use `commercial.view`.
Timebank policy requires Admin plus contract_manage; Units require Admin plus
`economy.manage_settings`. Consumption and overuse retain `commercial.timebank.quick-consume` and
`commercial.timebank.overconsume`. Preserve current superuser/admin behavior through shared policy.
A seeded permission name alone is not a grant.

Keep working paths/abilities compatible where safe, while closing actor/object bypasses. Add narrow
action/delete/settings/operation abilities only alongside routes/tests/docs. Never widen tokens,
seed permissions on reads or bypass existing installation/workload/context policy. Bind lines,
rates, terms and offers to the exact parent/source and allowed Client. Reject inactive/revoked
actors and reauthorize retries.

Use typed allowlists including nested input; reject unsupported/server-owned fields with 422.
Specify omitted/null/false/empty-list behavior and bounded paging/filter/sort values. Caller input
cannot forge authenticated actors, approval identity, ownership, calculated totals, snapshots,
acceptance evidence, tokens or provider bindings. If a web form permits a privileged field, provide
the same authorized operation explicitly. Document safe compatibility for legacy `created_by` input;
it cannot become the authenticated command/approval actor.

GET/context/PDF never sends, accepts, marks viewed, refreshes terms or creates defaults/records.
Public web GET currently writes viewed metadata: preserve that browser event through a shared
explicit viewed command while JSON reads stay pure. Aliases expose canonical capabilities.

Public authorization is the exact existing Contract capability and state checks, with bounded,
rate-limited validation and generic unknown-capability errors. Portal APIs use existing portal
session/CSRF and current account/membership/contact/client/site policy. No new customer PAT issuance
or caller-selected customer identity. Staff authority never substitutes for customer acceptance.
Customer projections exclude cost/margin, private metadata, internal rates/SLA implementation,
unrelated identities and storage paths. Bearer values never enter audit logs or receipt URLs.

### Immutable economics and evidence

Reuse `ContractPricing`/`ContractCustomerDocument` and the accepted 2026-08-24 ADR. Keep exact Brick
Math/minor-unit values, separate monthly/annual/one-time/legacy-quarterly totals, authoritative
Service/offer currency and the current NOK-only Contract boundary. Preserve current negotiated
line/default/SLA/rate visibility and legal metadata-v2 semantics; never calculate accepted history
from mutable catalogue values.

Sent/approved/won snapshots remain immutable. Invalid non-null evidence fails closed; missing legacy
JSON requires existing named original-evidence attestation, note, confirmation, explicit document
type and fresh stable preview hash. Never infer offer/agreement type from won status or reconstruct
historic parties/economics implicitly. Keep token rotation on new send, stable token on explicit
resend and dormant-token clearing on unsent manual approval. No implicit missing-token repair.
Collections isolate unavailable rows with truthful readiness; detail failures stay explicit.

### Commands, concurrency, timebank and delivery

Use Commercial-owned durable receipts bound to actor/capability, operation, parent, idempotency key,
canonical payload and relevant revision/fingerprint. Require keys for new effectful operations;
document legacy compatibility while enforcing supplied keys. Replay returns an exact authorized
result; changed payload/key reuse or stale revision gives 409. Use parent-before-child locks and
durable revisions, not only second-resolution timestamps. Cover send/approve/accept/attest/line/term
races. Timebank mutations serialize on the same authoritative balance boundary and deduplicate
consumption/overuse/billing effects. Deletes return scoped receipts/tombstones. Replay identity
survives generic audit retention.

Sending is explicit and reviewable. Freeze billing recipient, CC, subject/body, document/token
reference, terms/content revision and ready Email provider binding, then recheck execution authority
and freshness. Persist protected intent/attempt evidence with the command; dispatch after commit,
deduplicate HTTP/job retries and require a new intent/key for deliberate resend. Ambiguous provider
outcomes require bounded reconciliation, never blind retry. Reuse provider-binding protection; no
fallback SMTP transport or credentials in receipts. Sensitive frozen content is protected at rest
and only safe authorized projections are returned.

Contract status and communication outcome remain separate. Preserve current mark-sent business
behavior without a billing recipient, with explicit no-recipient/not-submitted result. Track
pending/processing, SMTP accepted, failed and unknown outcomes honestly. SMTP acceptance never proves
inbox receipt. Preserve current Portal notifications/preferences, audit and legal-acceptance effects
and deduplicate them. Publishing APIs never activates real sends. If scheduled recovery is required,
independently verify the external every-minute scheduler before claiming it healthy.

### Dead-route disposition

D01 is explicitly unimplemented. Verify views/tests/callers and remove the unused SLA delete route
with regression evidence. Do not invent SLA deletion or cascading. If a supported caller or contrary
intended behavior is found, obtain Svein's decision on dependencies/default/history before changing
that behavior. All implemented SLA read/create/update/default workflows remain fully in scope.

## Impact Analysis

Commercial owns actions/queries/routes/controllers/Livewire/validation/resources/tests and Knowledge.
Integration owns ability/OpenAPI discovery and provider lifecycle. Dependencies: Client/Contact
context/billing identity; CustomerPortal membership/legal/audit/notifications; Email provider/delivery;
CloudFactory Service/Cost ownership and offers; Ticket/Task SLA/rates/time; Economy overuse/order
generation; Taxonomy and Documentation Vendor lookup; Report/worklog semantics. Preserve runtime gates.

Risks: cross-Client/parent access, forged source/actor/evidence, composition drift, stale terms or
recipients, acceptance/edit races, duplicate delivery/consumption/billing, unknown SMTP outcome,
mutable history and unsupported deletion. No visual redesign is proposed; shared workflow extraction
still requires browser regression and named human review.

## Data And Migration Plan

After approval define additive receipt/revision and protected delivery-attempt storage in an ADR.
Reuse established receipt patterns with Commercial ownership and durable uniqueness. No bulk
Contract/term/price/customer snapshot rewrite or fabricated historical delivery backfill.

Validate isolated tests plus native DB locking/uniqueness before scoped Dev migration; record actual
migration names, preflight, backup and read-back. Apply additive schema before dependent code,
generate Swagger, clear affected caches and restart changed workers. No frontend build is expected
unless assets change. Retain immutable receipt/delivery/snapshot evidence during rollback; reconcile
unknown attempts before resending. Main/production require separate authorization/review. Exact
deployment commands follow the implemented diff rather than being guessed now.

## Testing Plan

- Every matrix workflow/field: allowed and denied actor/token/object cases, exact persisted read-back,
  real bearer scope, inactive/revoked actor, cross-Client/parent/source and Admin/settings denial.
- Strict fields/nesting and omitted/null/false/list behavior; bounded paging/filter/sort equivalence,
  context/enums/defaults, customer-safe output and pure reads.
- Catalogue composition, pricing previews, provider/in-use guards, Legal versions, SLA defaults,
  related rate/SLA consumers and dead-route disposition.
- Exact document/PDF/pricing/NOK behavior; immutable snapshots, terms-v2 fingerprints, legacy
  attestation freshness/type, draft cadence adoption and deletion.
- Revision/parent-lock races, retries/conflicts, simultaneous timebank registrations, period
  boundaries, multiple active Contracts, overuse and Economy no-double-billing.
- Frozen recipient/CC/content/provider, missing recipient/provider, queue/SMTP failure/unknown,
  deliberate resend, current preferences and notification/audit deduplication.
- Exact public capability and Portal membership/contact/CSRF separation, pure reads, legal audit
  and no staff customer impersonation; preserve coordinator pseudonymization/binding/truncation.
- Existing Commercial/CustomerPortal/Client/Ticket/Economy/Integration/Email regressions on Dev,
  syntax/Pint, complete generated OpenAPI and trusted HTTPS read-back, synthetic browser comparison.
  No real customer sends/acceptance/provider/stock/billing writes in automated tests.

## Documentation Plan

Update Commercial API/Contract/Service/Package/Cost/SLA/Rate and timebank Knowledge, Integration API
Management and examples. Explain actual menu/button names, prerequisites/scopes/actor rights,
request/error/result schemas, paging, revision/idempotency, delivery/reconciliation and precise
Dev/Main/production availability. Avoid duplicate title headings and new ad hoc localized UI labels.

Publish verified employee articles through the supported Knowledge/BookStack integration, then
independently read actual content and employee view scope without widening access. Employee OpenWebUI
retrieval is a separate claim. This unimplemented planning draft requires no handbook publication.
Record blocked publication with owner/next action in TODO. Add/update a scoped human-review checklist
when implementation starts; existing Reviewed history stays Reviewed. Named final acceptance gates
Main/deploy/release as applicable and is separate from design approval. Consider the public-safe
website handoff after verified implementation with do-not-publish until release evidence exists.

## Implementation Sequence After Approval

Approve the full target first, then create Feature Slices:

1. Freeze endpoint/field/auth matrix; shared query/context/policy/input and route foundation;
   define/accept command/delivery ADR, additive persistence and meaningful authorization regressions.
2. Complete catalogue composition, actual administration, calculations, ownership/version guards
   and dead-route disposition.
3. Complete Contract metadata/lines/terms/pricing/readiness/PDF/legacy attestation with shared
   browser operations, immutable evidence and concurrency regressions.
4. Complete send/resend/manual approval and protected delivery/operation read-back, retry/unknown
   reconciliation and notification evidence.
5. Complete public/Portal view/acceptance and Client timebank/context/balances/registration, keeping
   owner APIs and controlled history export distinct.
6. Reconcile every matrix row/field, full Swagger, affected regression/browser QA, handbook
   publication/read-back and concrete final human checklist. No partial slice is called 100%.

Each slice updates TODO, verification, docs and the same human-review entry. Implement directly on
Dev while preserving concurrent work. Design approval alone authorizes no commit/push/Main/deploy.

## Open Questions

Approve the complete target for Dev implementation, including all supported staff/Admin/public/
Portal workflows and additive durable command/delivery evidence, while preserving current
permission, identity, legal/pricing, Integration and domain ownership rules?

## Approval

Approved by Svein Tore in chat on 2026-10-25: "Godkjenner. Kjor pa" (original Norwegian approval in conversation).
The entire target including additive receipts/delivery evidence is approved for Dev implementation.
Human review HR-2026-10-25-COMMERCIAL-API is Reviewed: Svein Tore accepted the completed delivery with "Godkjent" on 2026-10-25. No Main/production action was requested or performed.


## Implementation Evidence - 25 October 2026

The approved full target is implemented and verified on Dev through six complete slices.
103 HTTP operations include two pre-existing separately controlled exports. Shared actions and
meaningful automated regressions cover staff/customer/catalogue/Contract/settings/timebank scope.
129 Commercial/portal tests / 1898 assertions and 113 broader affected / 1104 pass.
68 scoped PHP files pass syntax/Pint. Exact migration batch 23, native lock/unique probes,
seven authenticated HTTPS reads, exact Swagger and eight internal Knowledge source matches pass.
Eight BookStack pages/sections read back with existing staff access. Earlier DB outage resolved.
Both delivery switches remain default-off. HR-2026-10-25-COMMERCIAL-API is Reviewed by Svein Tore on 2026-10-25.
Separate promotion/deployment and controlled activation remain with Svein Tore. No real send or production deployment is claimed.
See ../plans/2026-10-25-commercial-api-verification.md and frozen contract/evidence artifacts.
