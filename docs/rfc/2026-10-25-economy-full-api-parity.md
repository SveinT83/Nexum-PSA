# RFC: Full Economy Browser And API Coverage

Status: Approved / Merged to Main; HR-2026-10-25-ECONOMY-API Reviewed by Svein Tore, 2026-10-25.
Date: 2026-10-25
Owner: Codex. Product, human-review and release owner: Svein Tore.
Level: 3 - API, financial workflow, authorization and cross-module behavior.
Matrix: ../plans/2026-10-25-economy-api-gap-matrix.md
Evidence: ../plans/2026-10-25-economy-api-inventory.json

## Context

Svein requested: "Economy Domain API Stotte. 100% API stotte" (Norwegian request).
100% means complete supported browser/API parity: reads, writes, actions, administration,
customer views, fields, permissions, exact results and relevant side effects.
Authoritative Dev /var/Projects/tdPSA, branch Dev, was inspected on 2026-10-25.
Runtime inventory finds 14 Economy-owned browser routes and seven staff API routes.
These counts describe entry points, not a coverage percentage. Six additional routes with
Economy in their URL belong to Commercial (Rates/Units) and keep that ownership.

Commercial parity is merged to Main and human-reviewed according to its RFC/TODO evidence.
No Economy-specific parity RFC or active implementation was found. An open GitHub search for
Economy matched Knowledge #265, not an Economy-owned task. No Issue/comment was created.
Existing unrelated work is preserved, especially dirty GenerateOrders.php changes that retain
recorded financial customer allocations after a Ticket move/merge. Re-read that diff before coding.

## Goals

1. Every E01-E14 workflow and every currently supported field is accessible through typed APIs.
2. Browser and API share domain queries/actions, authorization, validation, totals and side effects.
3. Enforce explicit token abilities plus current active actor, domain, object and parent rights.
4. Prevent duplicate billing, stale transitions, source corruption and duplicate notifications.
5. Preserve Data Exchange, Commercial, Ticket, Storage, CustomerPortal and Integration ownership.
6. Deliver complete OpenAPI, meaningful Dev tests, trusted HTTPS read-back, employee Knowledge
   and verified BookStack publication. Claim full coverage only after matrix acceptance evidence.

## Non-Goals

No new invoice/payment/accounting engine, invoice sending, bank reconciliation, provider activation,
new approval/cancellation/exported transitions, arbitrary order/line CRUD, stock reversal,
customer-side order editing or site-level splitting. Existing future billing/invoicing plans remain
separate. These exclusions do not remove any implemented Economy workflow from API scope.
No commit/push, Main promotion or production deployment is authorized by this planning draft.

## Current Behavior

Existing API lists/shows orders, generates source-backed lines, changes draft/ready and deletes
empty orders or draft lines. Missing direct operations: manual-invoiced, portal publication/hiding,
Economy settings and customer-safe Portal list/detail. Data Exchange offers generic profile/run/file
APIs, but Economy has no equivalent of the web export button with its exact profile and actor guards.

Current API ready/draft paths omit web CustomerPortal status notifications. Staff resources omit
portal publication state, effective display totals, statistics/context and typed operation evidence.
Stored totals may differ from web display totals when old lines lack VAT; API returns stored amounts.
GET list calls EnsureEconomyDefaults, which can create/update settings. API middleware shows
Sanctum authentication and token scopes without the web permission middleware; current domain actor
and object enforcement must be tested and made explicit, not inferred from possession of a token.

Generation handles Ticket time/cost, Commercial quick-timebank overuse and CloudFactory billing
periods. Existing source-key deduplication is not proof of concurrent command safety. Summary counters
for firstOrCreate paths need actual-created versus encountered semantics. DeleteOrderLine explicitly
unlocks Ticket sources but must be reconciled and tested for all generated source types.

## Proposed Change

### Complete operational contract

- Orders: full authorized list/detail, current filters, bounded pagination, stable sort/context,
  statistics, exact line fields, source-safe references, publication state, actor/timestamp data,
  effective totals and available actions. Include separate bounded line list/detail if needed for
  complete large-order retrieval; never silently truncate lines or imply partial totals are complete.
- Generation: discoverable period defaults, typed read-only preview of eligible sources/clients,
  relevant settings/readiness, explicit generation and durable summary/affected-record read-back.
  Preserve the current installation-wide operation for authorized generators; do not misrepresent
  a client filter as implemented or silently widen a client-scoped actor to all clients.
- State actions: draft -> ready, ready -> draft and ready/approved -> manual_invoiced. Record the
  authenticated actor and preserve notifications/preferences. Manual-invoiced never sets or claims
  external export, provider invoice or payment status. No generic caller-selected state update.
- Visibility: publish/hide an existing client-scoped order, exact publication fields, existing
  CustomerPortal audit and publish notification. Repeating the same command causes no duplicate
  audit/notification. Pure reads never publish or send.
- Deletion: empty draft/ready order only; draft line only, bound to exact parent. Atomically unlock
  the correct source, recalculate totals and remove the last-line empty draft. Preserve original
  recorded financial customer, stock movements and source ownership. For quick-timebank and
  CloudFactory lines, restore eligibility only through the source owner's supported transition;
  no fabricated provider state or deleting source records. Resolve contrary intended behavior
  with Svein before changing billing semantics.
- Settings: read/context/update every current setting, using the same Admin and
  economy.manage_settings policy. Explicit PATCH omission preserves values; false and zero apply;
  documented nullable values/default fallback are distinguished. Browser checkbox submission retains
  its form semantics through an adapter to the shared settings action.
- Export: Economy-specific context/preview/explicit command for the existing Economy Orders profile;
  reuse Data Exchange profile, filter, format, run/file and audit runtime. Return run/file references,
  checksum, actual status and errors. Preserve configured mappings/filters and ready/approved defaults.
  Do not mark orders exported just because a file exists. No external delivery is enabled. Any
  configured delivery behavior is disclosed and gated before execution, never silently introduced.
- Customer Portal: typed list/detail/context through existing authenticated portal identity,
  membership/contact/client and site restrictions. Only explicitly published current-client orders;
  site memberships remain excluded. Use web/session/CSRF separation as existing Sales/Commercial
  Portal API branches do; never mint a customer PAT or let staff impersonate customer context.

Register Economy domain routes in Economy/routes.php with explicit API/Portal branches. Existing
api.php may be a compatibility loader. Shared routes/api.php only loads domain branches.
Controllers use shared Economy actions/queries; no HTTP calls to the web UI, fabricated requests
or duplicate financial business logic. Rates/Units remain Commercial-owned and discoverable by links.

### Authorization and typed data

Use current active Tech/staff identity plus effective browser policy AND explicit Sanctum abilities.
Current browser permissions: economy.order_manage for staff list/detail/status/visibility,
economy.generate_orders for generation, economy.delete_orders for deletions, Admin plus
economy.manage_settings for settings. Export additionally requires economy.view and data_exchange.run;
run/file reads and downloads retain the corresponding Data Exchange actor/object rights and abilities.
Reuse the shared policy's existing Admin/Superuser rules without broadening ordinary roles.

Keep existing working API paths and abilities compatible where safe; add only tested/discoverable
narrow settings/action/export/operation abilities. Current tokens are never widened. Preview requires
the same data rights as the intended action. Reauthorize replay and downloads, including revoked or
inactive actors. Resolve every source/line/reference to its permitted parent and financial client.

Use strict typed allowlists, including nested/query data; reject unsupported/server-owned fields.
Define null/omitted/false/zero/empty behavior and bounded dates, query, status, sort and paging.
No caller-forged actor, amount, totals, source identity, timestamps, allocation, portal author or
provider state. Generate defaults/context must not create settings or billing records on GET.
Expose read-only effective settings fallback; move default persistence to explicit authorized writes.

Keep stored amounts and effective display amounts explicit and consistent across web/API/Portal.
Preserve current rounding and source snapshots; do not change historical VAT, currency or economics
silently. Use exact decimal representations alongside compatible legacy numeric fields where needed.
Document all currency facts; never claim an unimplemented currency conversion. Customer projections
exclude internal source metadata, private Ticket content, staff identities, provider references,
generation diagnostics, audit internals, storage paths and privileged mutation links.

### Commands, concurrency and side effects

Use Economy-owned durable receipts bound to actor, operation, parent, idempotency key, canonical
payload and relevant revision. New effectful APIs require an idempotency key and stale-write guard;
document compatibility of existing mutations. Same key/payload replays the authorized exact result;
changed payload or stale revision returns 409. Deletion has durable scoped tombstone/result evidence.

Freeze previews for high-impact generation/export/visibility, then check current authority and
freshness at execution. Serialize generation on the same period/source/financial-client boundary
across API, web, jobs, Ticket picking/closing and scheduled catch-up. Lock parent before children
for state/delete/publication races. Reconcile source uniqueness and draft-order creation without
inventing a global unique client-period rule that would prevent existing multiple-state history.

Do not rely on second-resolution updated_at alone. Compute counters from actual created/updated
records and report existing/reused sources honestly. Read-back identifies affected orders/lines,
source status/allocation and actual export run/file results. No duplicate charge, covered allocation,
quick-timebank overuse, provider period or stock consumption after retry/race.

Notifications and audit share the same domain action and execute after committed state with durable
deduplication. Preserve preferences, event types and client audience. Report queued/submitted/failed
separately from actual receipt; publishing an API cannot enable real provider/runtime features.

## Impact Analysis

Economy: routes, queries/actions, controllers/resources, generation/deletion/settings, tests and docs.
Ticket/Storage: source status/allocation, recorded financial identity and pick/close generation callers.
Commercial: quick consumption/timebank semantics, existing Rates/Units APIs and cross-domain regression.
Integration/CloudFactory: confirmed/queued billing-period lifecycle and API catalog/OpenAPI discovery.
DataExchange: Economy profile execution and scoped run/file permissions; harden the needed owner path
rather than creating an unguarded Economy download proxy. CustomerPortal/Notification: current
membership/client/site, explicit visibility, audit and deduplicated preference-aware notifications.
Knowledge/BookStack: verified employee API and operating instructions.

Risks: cross-client exposure, impersonation, stale VAT presentation, duplicate billing, settings
side effects on reads, wrong-source unlock, export data leakage and concurrent notification delivery.
Dirty GenerateOrders financial-customer work is a protected dependency, not a file to replace.
No UI redesign is proposed; shared-action extraction still needs web regression and manual checks.

## Data And Migration Plan

After design approval, define an ADR for additive command receipts/revisions and source/period locking.
Reuse existing domain patterns without sharing another domain's mutable command table.
Inspect current native schema/indexes/data before choosing constraints or migrations. No mass
historical order/allocation/VAT rewrite or invented delivery backfill. Test native locking/uniqueness
as well as isolated feature tests. Record exact additive migrations, preflight, backup and Dev
read-back. Apply additive schema before dependent code; generate Swagger, clear relevant caches and
restart changed workers if required. No frontend build unless assets change.

Rollback retains financial/source, audit and receipt evidence. Disable new runtime consumers before
reverting dependent code; do not resend/rebill unknown operations. Native generation/export and any
customer notification need explicit controlled validation; automated tests use synthetic data.
No scheduler activation. Verify an external every-minute schedule:run runner separately before
claiming automatic catch-up health. Main/production remain separate authorization gates.

## Testing Plan

Baseline on authoritative Dev: EconomyModuleTest passes 17 tests / 105 assertions on 2026-10-25.
This validates existing tests only, not missing API coverage or full authorization safety.

- Each E01-E14 row/field: allowed/denied actor/token/domain/object/Admin, inactive/revoked actor,
  cross-client/parent/source, real bearer scopes and exact persisted result read-back.
- Complete API/browser effective totals including missing VAT, zero/default settings, decimals,
  currencies, filters/stats/pagination/context and pure GET without settings/default writes.
- Generation all four source types, covered/partial time, chronological/annual boundaries,
  source identity after Ticket moves, repeat/concurrent generation and truthful counters.
- All three transitions, publication/hide, audit/preferences and duplicate/failure notification cases.
- Every line source deletion/regeneration, cross-parent rejection, last-line removal and stock
  movement preservation; state/delete/generation races with native database lock/unique checks.
- Settings omission/false/zero/null, strict fields, unauthorized auto-pick setting, no implicit pick.
- Economy export default/custom profile, exact output/run/checksum, wrong profile/run/file denial,
  missing file/failure/retry and no false exported/provider/invoice claim.
- Portal publication/current client/site/membership denial and customer-safe field allowlist.
- Economy plus affected Ticket/Storage/Commercial/CloudFactory/DataExchange/CustomerPortal/Notification
  and Integration regressions, PHP syntax/Pint, complete generated Swagger and trusted HTTPS reads.
  No real customer billing, stock, provider, send or publication in automated checks.

## Documentation Plan

Update Economy module/API/orders/settings Knowledge and relevant Integration API Management,
DataExchange and Portal articles. Document exact routes/scopes, actor rights, fields/errors,
replay/revision/preview rules, source unlock, totals/VAT, export file versus invoicing, current menu
and button names, prerequisites, numbered steps and troubleshooting. Keep new copy in English.
Publish through supported Knowledge/BookStack integration and independently read actual articles
and existing employee scope without widening permissions. OpenWebUI retrieval is a separate claim.
Record concrete publication blockers/owner/next action in TODO. Draft planning requires no handbook
publication because implemented behavior has not changed.

After approval, create complete Feature Slices for foundation/contracts, reads/settings,
generation/deletion, status/publication/Portal, export, and final coverage/docs/verification.
Track one parent workstream in TODO and a stable HR checklist at implementation start. Named human
review remains required before Main/deploy/release as specified by that checklist. Consider a
public-safe website handoff only after verified implementation, with an explicit publication gate.

## Open Questions

The complete target was approved by Svein Tore and implemented. No product decision blocks Dev
completion. Named human review and any Main/production action remain separate gates.

## Approval

Approved by Svein Tore in chat on 2026-10-25: "Godkjent. Kjør på" (original Norwegian approval). Full E01-E14 implementation, additive receipts/locking and documentation are authorized on Dev. Main promotion/production remain separate.

## Implementation Evidence

All six slices and E01-E14 are Done On Dev. 23 HTTP operations / 34 typed schemas, current actor
plus scope checks, shared financial/source/state/settings/Portal/export behavior and durable replay
are verified. Final 60 / 640 Economy/DataExchange, 5 / 313 API admin, 384 / 4324 broad and 47 / 558
production Swagger/Economy pass. Forty PHP syntax/Pint pass; migration batch 24, native locking/
uniqueness, trusted HTTP and six BookStack content/employee-scope read-backs pass.
See ../plans/2026-10-25-economy-api-verification.md for exact boundaries, release commands and risks.
HR-2026-10-25-ECONOMY-API stays Pending. No commit/push/Main/production or real customer operation.

Main implementation merge verified 2026-10-25: [PR #315](https://github.com/SveinT83/Nexum-PSA/pull/315),
commit 33eac1a3cba47ffe57f2253847dbaaf8f9b6d4e9. Tested candidate tree matches Main. See
../plans/2026-10-25-economy-api-main-promotion.md for evidence. Production is not deployed.
