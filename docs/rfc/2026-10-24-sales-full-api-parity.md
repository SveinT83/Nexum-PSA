# RFC: Full Sales Browser And API Coverage

Status: Approved / Merged To Main / Human Reviewed; production pending.
Date: 2026-10-24
Owner: Codex. Product/design/human-review/release owner: Svein Tore.
Change level: Level 3 - API, authorization, delivery/idempotency and cross-module contracts.
Evidence: ../plans/2026-10-24-sales-api-gap-matrix.md and sales-api-inventory.json.
Human-review checklist: HR-2026-10-24-SALES-API (Reviewed by Svein Tore on 2026-10-25).

## Context

Svein requested "Da fixer vi 100% dekning med API for sales domain" after live Sales gap verification.
The complete target is every currently supported staff/admin, public-token and Customer Portal
browser read, write and action, with context, field behavior, authorization, side effects, typed
read-back, OpenAPI, tests and operational documentation. It includes more than the six initial gaps.

Authoritative Dev is /var/Projects/tdPSA on Dev, with 809 dirty paths at preflight and no Sales changes.
Shared Integration/API loader/TODO/review and related domain work must be preserved. Calendar API is
human-reviewed and merged through PR #310; its production rollout is separately owned by Svein.
Ticket/Task API implementation awaits named human/handbook gates. Sales CPQ and lost/reopen work
are Done/Reviewed, including HR-2026-08-11-004 and the accepted Sales snapshot ADR. No finishable
Sales implementation is being abandoned. This request establishes the next Sales workstream.

Read-only GitHub searches found no open Sales/quote Issue. All 54 current Discussions were listed;
Discussion #170, Sales Quotes / CPQ: structured quoting and customer acceptance, was read and has
no comments. Preserve its broader goal map and the approved 2026-08-11 Sales CPQ RFC/ADR. The old
Domain API Foundation approves foundation conventions, not this complete new Sales API contract.

## Goals

1. All supported Sales browser workflows have complete typed APIs, including writes/deletes/actions,
   customer responses, discovery/context, administration, reporting and exact result read-back.
2. Preserve current domain ownership, business/permission rules, price/cadence behavior and immutable
   sent/accepted quote history. Browser and API use shared authoritative actions.
3. Require current active actor/object/field rights as well as token ability; keep staff, portal
   customer identity and public quote capability separate.
4. Pure reads; strict mutation fields; audited, concurrency-aware, safely replayable commands.
5. Frozen, reviewable outbound communication with truthful operation/delivery evidence.
6. Every registered method and supported browser field/side effect is covered by OpenAPI, meaningful
   tests, Knowledge/BookStack read-back and named human review before claiming full coverage.

## Non-Goals

No new future CPQ concept, accounting engine, CRM platform, pricing calculation policy, automatic
customer messages, role/token expansion, new provider setup or default-on runtime feature. No generic
model dispatcher. No staff impersonation of customer acceptance. No new downstream conversion engine:
Sales plans and existing Ticket/Storage/Commercial/Economy owner actions remain authoritative.
No unsupported destructive opportunity/quote deletion is added merely to inflate CRUD coverage.
Design approval does not include commit/push, Main merge, production deployment or real customer
email, acceptance, stock mutation or provider operation.

## Current Behavior

53 browser routes: 52 controller routes plus one compatibility redirect. 18 API route rows / 19
HTTP method/path operations excluding HEAD. Eight Sales-source OpenAPI operations match; eleven
registered method/path pairs lack a matching source attribute (PUT plus ten template methods).
Current API covers opportunity CRUD without delete, lost/reopen, basic activities/read and templates.
The matrix maps every route and missing/partial behavior, including leads, stakeholder, per-activity
read, email_out/internal-note notification, rich quote/version/acceptance/conversion reads, draft/
details/CPQ lines/template application, approval/send/revision, settings/reporting and customer APIs.
Current list defaults/order and field projections differ. Template routes need complete actor/admin
parity and typed OpenAPI, not just token abilities. GET calls EnsureSalesDefaults and can write
settings/templates/permissions. Customer browser views record viewed/expiry during GET. Quote jobs
resolve the primary recipient at execution time; replay-safe commands must freeze exact send intent.

Existing Dev baseline: HOME=/tmp php artisan test
app/Modules/Sales/Tests/Feature/SalesModuleTest.php - 29 passed / 453 assertions, 45.37 seconds.
This verifies existing behavior only, not the proposed implementation.

## Proposed Change

### Ownership and registration

Sales owns opportunities, Client-backed leads/classification, quote/version/CPQ, template/activity,
approval, acceptance snapshot and conversion-plan status/reference. Client/Contact own creation,
canonical identity/legacy bridge and provider-authoritative numbering. Commercial/Storage own source
catalogs and delivery. Email owns provider binding/transports; CustomerPortal owns identity/audit;
Notification owns current portal notifications; Calendar owns follow-ups; Ticket owns accepted Ticket
fulfilment/void; Integration owns ability discovery and OpenAPI publication.

Use explicit staff/public/portal API branches in app/Modules/Sales/routes.php. Existing api.php may
remain a compatibility loader without duplicate registration. routes/api.php only loads module
routes, including narrow public/portal branches outside staff Sanctum. New code stays in owning
modules. Extract business operations/validation from browser controllers into shared actions/queries;
both transports call those actions, not redirect controllers or HTML scraping.

### Complete endpoint families

Add context/dashboard; leads/context/detail/classification/start; guarded quick-create bridges;
opportunity context/stakeholders/paginated activities; quote/version list/detail/customer-preview/PDF,
acceptance snapshot/conversion plans; draft/details/CPQ lines/template/revise/approval;
send-preview/send; activity email preview/send; operation/delivery reads; approval-policy settings,
reporting and complete existing template maintenance. The matrix is the completeness gate; endpoint
counts are not a cap. Preserve existing paths/names and meaningful compatibility.

Customer APIs use /api/v1/sales/public/quotes/{token} and /sales/portal/quotes. Include customer-safe
JSON, existing PDF surface, viewed lifecycle, accept/decline/questions and exact receipt/read-back.
Portal APIs reuse existing authenticated portal session/context and normal CSRF protection; do not
invent customer PAT issuance or use staff tokens to choose a customer. Preserve company/site
restrictions, current membership/contact identity and portal audit/notifications. Public access is
only the existing exact quote capability, with bounded validation/rate limiting and generic unknown
capability errors. Staff scope never substitutes for customer identity.

Make GET/context/PDF pure: report effective expiry/readiness without seeding roles/settings/templates
or marking views. Supported explicit setup/lifecycle commands and existing expiry processing record
those changes. Browser views retain lifecycle through shared commands. Staff/customer PDF reuse
current presentation/snapshot behavior; no new historical reconstruction or rounding policy.

### Authorization and input

Staff routes require Sanctum ability AND active current actor/module/object/field rights. Settings/
templates also require actual current administrative policy. Enforce contact/line/version/plan parent
membership and source catalog visibility. No token widening or permission seeding in reads. Add
ability catalog entries only with implemented routes and allow/deny tests; use narrow quote read/
manage/send/approve, lead, email, settings/report and operation-read abilities as needed.

Current browser guards: sales.quote_manage for quote operations; sales.quote.approve for decisions;
sales.lead_manage for leads; sales.opportunity_manage for opportunity changes; sales.view for other
Sales routes; Admin plus sales.manage_settings for settings. Seeded sales.quote.send/sales.email_send
names alone do not prove a different browser guard. Preserve effective authority in a shared policy;
do not silently reinterpret or grant it. Resolve any material intended/implemented mismatch before
changing the business rule.

Whitelist typed mutation fields, including nested arrays, and reject unsupported fields with 422.
Document omitted/null/false/empty-array behavior. Callers cannot set identities/role grants/provider
credentials/tokens/calculated totals/source snapshots/approval actors/acceptance or delivery evidence.
Customer resources exclude cost/margin/supplier/private notes/approval internals/raw metadata/storage
paths and unrelated customers. Staff fields are explicit safe projections too.

### Commands, concurrency and communication

Sales-owned durable receipts bind current actor/capability, operation, parent/version, idempotency key
and canonical payload fingerprint. Require keys on new effectful operations; existing legacy paths
retain documented compatibility, while supplied keys enforce replay. Changed payload/key reuse gives
409. Reauthorize replay; return exact authorized result/receipt. Use revision preconditions and row
locks; second-precision updated_at is insufficient. Concurrent revise/send/accept/decline/edit cannot
overwrite immutable state or duplicate downstream effects. Deletions have exact receipt/tombstone.

Preview freezes quote version/content revision, recipients/CC, provider binding and reviewable content.
Execution rechecks rights/approval/expiry/freshness. Send/resend has its own explicit intent/key.
Persist a Sales delivery attempt/outbox linked to the command, dispatch after commit, and deduplicate
HTTP/job execution. Quote/activity jobs execute frozen content/recipient rather than a changed primary
contact. A deliberate resend needs a new intent. Ambiguous provider outcomes require reconciliation;
never blindly retry. Recovery is bounded/deduplicated. If scheduled recovery is needed, verify the
external every-minute scheduler separately before reporting operational health.

Business and delivery status are separate: marked_sent, no_recipient/not_queued, queued, processing,
SMTP accepted, failed or unknown as appropriate. Preserve current mark-sent/no-recipient business
behavior but never call it delivered. SMTP acceptance does not prove inbox receipt. Retain Email
provider binding protection, approval invalidation/requirements and current notification settings.
No real sending or default-on runtime change is performed by exposing these APIs.

Customer acceptance reuses AcceptSalesQuote/BuildSalesQuoteAcceptanceSnapshot, current portal audit/
notifications and Ticket fulfilment. Preserve selected/declined lines, quantities, acknowledgements,
totals, shown text and verified request identity in immutable snapshots. Staff cannot choose portal
identity or manually accept as a customer through this API. Existing Ticket manual acceptance/void
remain Ticket-owned. Conversion status/reference does not prove/create downstream fulfilment.

## Impact Analysis

- Sales: routes/actions/queries/validation/resources/controllers/jobs/tests/Knowledge.
- Shared dirty surfaces: API loader, Integration ability/OpenAPI catalog, TODO/review. Protect with
  baseline hashes and additive edits; preserve other contributors' work.
- Related contracts: Client/Contact/Tripletex unknown-outcome reconciliation and numbering; Taxonomy
  tags; Commercial/Storage catalog visibility; Calendar follow-up; Email provider/jobs/evidence;
  Notification/CustomerPortal audit; Ticket accepted quote processing and immutable history.
- No visual redesign; browser controllers use shared operations. Existing Sales business data plus
  additive receipt/delivery metadata; no bulk quote/snapshot/role/customer-number rewrites.
- Main risks: cross-customer/parent data, stale approval/recipient/content, duplicated or unknown
  delivery, CPQ field drift, historical evidence mutation and unintended owner-domain side effects.

## Data And Migration Plan

The first approved slice defines Sales receipt and delivery-attempt/outbox schemas in an ADR, with
unique replay/send identity, fingerprints/revisions/state, scoped actor/capability and sanitized
result references. Replay identity must survive activity-log cleanup. No package or pricing-schema
replacement; no backfill fabricates delivery. Validate isolated SQLite and native DB uniqueness/
concurrency before guarded Dev migration, backup and read-back. Record exact migration names.

Release sequence: additive migrations/compatible code, affected cache clear, Swagger generation,
worker restart and queue/scheduler verification if used. No frontend build expected. Exact deploy
commands belong in the implementation report; this draft requires none. Rollback disables new entry
points/dispatch but retains receipts, attempts, acceptance and already-sent evidence. Do not drop
operational evidence or resend unknown outcomes as rollback. Main/production remain separately gated.

## Testing Plan

- Freeze browser/method/field/Swagger contract tests, including PUT and templates; reject silently
  ignored fields. Every supported route/view capability must map to implemented behavior.
- Active/inactive actor, read-only/missing token, role/admin/approver, cross-client/parent/catalog,
  portal membership/site, public capability and replay-after-permission-revocation cases.
- Complete lead -> opportunity/stakeholder -> draft/CPQ/options/acknowledgements -> approval ->
  send-preview/send -> customer question/decline/accept -> snapshot/conversion read-back.
- Catalog snapshots, cadence totals, sent/accepted immutability, approval reset, stale revision,
  superseded/expired links, required/default/selected lines, quantity/acknowledgement bounds.
- Email/CC/internal notification and one/all read; frozen content/recipient/provider; repeated HTTP/
  job/recovery, failed/unknown delivery, explicit resend; transactional side effects and rollback.
- Pure GET/context/PDF with absent defaults, bounded reads, customer-safe projections, portal audit/
  notifications and no staff customer impersonation. Existing supported PDF/snapshots remain intact.
- Ticket acceptance/void/planned lines, Storage delivery, Calendar follow-up, Client/Contact provider
  authority/reconciliation; meaningful affected-module regression on authoritative Dev.
- Isolated native DB concurrency where needed; no real customer email/acceptance/stock/provider
  write in automated tests. Trusted HTTPS/OpenAPI/read-back and named human review are separate.

## Documentation Plan

Update Sales sales-api.md/sales-overview.md, Integration API Management, developer contracts, matrix
and verification manifest. Explain exact menus/buttons/prerequisites/scopes, typed payload/error/
result examples, retry/409, receipts/delivery/recovery and explicit Dev/Main/production availability.
Publish only affected employee articles through the supported integration, then independently read
actual BookStack content and inherited employee view access. Assistant retrieval is a separate claim.
If blocked, TODO records exact owner/action rather than claiming publication. Preserve existing
review history. Public-safe website handoff is considered after verified implementation, not now.

## Implementation Sequence After Approval

This full target is the decision requested. After approval create independently testable Feature
Slices: (1) query/context/auth/input/route and receipt foundation; (2) leads/opportunity/client/contact/
stakeholder/activity parity; (3) quote/version/CPQ/template parity; (4) approval/frozen communication/
delivery read-back; (5) public/portal customer response/snapshot and owner regressions;
(6) administration/reporting/full OpenAPI/handbook/acceptance. Each updates tests/docs/TODO; the final
slice requires every matrix row to pass. Create/accept the command/delivery ADR in the first slice.
All application implementation occurs directly on authoritative Dev, preserving shared changes.

## Open Questions

No new product decision beyond approval of the full target and preserved permission/identity/domain
boundaries. Technical paths can be finalized in the approved contract slice. Approve this RFC for
full Dev implementation, including additive durable receipt/delivery metadata and all supported
staff/admin, public-token and Customer Portal workflows?

## Approval

Approved by Svein Tore on 2026-10-24 in chat: "Godkjenner". The complete target, additive receipt/delivery metadata and all current staff/admin/public/portal workflows are approved for Dev implementation. Main/production and final human acceptance remain separate.

Svein Tore requested the work on 2026-10-24: "Da fixer vi 100% dekning med API for sales domain".
RFC design approval is now recorded above. Implementation proceeds through the linked Feature Slices. HR-2026-10-24-SALES-API remains Pending; design approval
does not mark final human acceptance Reviewed or authorize Main/production/customer operations.

## Implementation outcome (2026-10-24)

All six approved slices are implemented on Dev. The frozen contract contains 75 HTTP
operations and 95 typed schemas, mapping all 53 browser routes. Native additive
migration, HTTPS/API/Swagger and BookStack read-back are verified.
See [verification](../plans/2026-10-24-sales-api-verification.md) for tests, files,
commands and operational limits. HR-2026-10-24-SALES-API remains In Review; design
approval does not imply final human acceptance or Main/production authorization.

## Human acceptance (2026-10-25)

Svein Tore approved the delivered scope and Main merge in chat: "Godkjent. Merg til main".
HR-2026-10-24-SALES-API is Reviewed. The earlier pending-review wording records the
2026-10-24 implementation handoff; this acceptance clears the Main merge gate.
Production deployment, provider activation and real message delivery remain separate.

Main implementation merge independently verified on 2026-10-25: [PR #311](https://github.com/SveinT83/Nexum-PSA/pull/311),
commit `81812c5b8989dcb3522245b87e69390fb57a2141`. GitHub and origin/main agree; the complete merge tree
equals the tested final candidate e5d4c989abc8bf64a39235d83672bcce696f0f17.
Dev HEAD/index and unrelated work are preserved. Production deployment and activation
were not performed.
