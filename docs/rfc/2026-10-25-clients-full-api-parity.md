# RFC: Full Clients Browser/API Coverage

Status: Approved / Implemented and verified on Dev / Human Reviewed / Main promotion authorized
Date: 2026-10-25
Owner: Codex
Product/design approver and human reviewer: Svein Tore
Change level: 3 - API, integration boundaries and cross-module behavior
Human-review checklist: HR-2026-10-25-CLIENTS-API (Reviewed)
Coverage: ../plans/2026-10-25-clients-api-gap-matrix.md
Baseline: ../plans/2026-10-25-clients-api-route-baseline.json

## Context

Svein requested: "Clients domain. Vi trenger 100% API støtte".
The target is complete official API support for every currently supported Clients
browser read, write, action, form context, administration and customer workflow.
Read-only endpoints or a route count do not meet this target.

Authoritative Dev is /var/Projects/tdPSA, branch Dev. The runtime route inventory
contains 37 browser route rows and nine Clients API rows representing 11 HTTP
operations excluding HEAD. Client Notes autosave is an additional Livewire action.
Aliases, forms and owner-domain links are not separate business operations.
The baseline records exact method/path/controller ownership without customer data.

Existing Clients files contain shared uncommitted Tripletex and allocation work.
The earlier Sales, Commercial, Economy and DataExchange API deliveries have their
own completed workstreams and review records. Preserve their actions and contracts.
Open Issue #254 concerns Contact creation and portal role/invitation selection;
it is a separate product change and is not implicitly approved by this RFC.

## Goals

- Every workflow in C01-C20 has an official typed API mapping, including supported
  creation, editing, Site deletion, source time corrections, provider operations
  and customer licence ordering, with exact result and operation read-back.
- Expose form options, constraints, supported actions and permitted references.
- Apply the current active actor, token, object, parent, field and membership
  authorization to every request, including replay and cross-domain projections.
- Share authoritative Actions/Queries with browser and Livewire entry points.
- Publish complete request/response/error schemas and operation IDs in Swagger.
- Cover the complete matrix with meaningful success, denial, concurrency,
  rollback and side-effect tests on Dev.
- Update source Knowledge, actual BookStack articles and API Management guidance;
  verify content and employee access without expanding permissions.

## Non-Goals

- Client archive, hard delete, restore or retention policy: the separate
  2026-06-05-client-deletion-retention-policy RFC is Draft and no such browser
  workflow currently exists. Deactivation through active=false is included.
- A Clients-to-Client module rename, new CRM system, portal role model,
  automatic invitations, historical client_users deletion or identity migration.
- New provider products, provider activation, wider staff/token permissions,
  bulk imports, arbitrary provider proxying, or rewriting completed domain APIs.
- Production deployment or real customer/provider writes during implementation.
- A new external MCP server. The official API/OpenAPI is the reusable contract;
  publication into an external adapter/catalogue requires separate verification.

## Baseline Behavior (before implementation)

- Clients API supports list/show/create/update, Client Assets lookup, Site list,
  Site creation and Site update. Contact-owned client relations and Commercial
  timebank APIs already exist and must be reused.
- List supports q, active and custom-field filters. Browser format, contract and
  RMM filters and sortable columns lack equivalent Client API inputs.
- ClientResource omits notes, website, format and other editable fields, so a
  successful write cannot be fully confirmed through current Client read-back.
- Browser creation transactionally creates Client, default Site, canonical
  Contact, relations and legacy bridge, with optional N-able creation.
  Current API creation creates Client/default Site but has no equivalent
  primary-contact or optional RMM creation command.
- Settings owns RMM mapping and uses client.manage_settings. Notes autosave uses
  client.update. These distinct authorities must not collapse into one scope.
- There is no Site detail API or Site deletion API.
- Custom-field APIs use SyncCustomFieldValues and actor-aware presentation.
- Time correction is Clients-owned orchestration across quick/Ticket/Task sources;
  existing guards reject ordered entries and independently edited coupled Task
  billing. Commercial owns quick registration and timebank balances.
- Clients owns staff and portal licence surfaces. Integration owns CloudFactory
  configuration, provider records, transport and operation ledger. Portal writes
  require client-wide Customer admin membership and recorded legal acceptance.
- Legacy Client User routes redirect/delegate to canonical Contact workflows;
  their DELETE alias currently removes no historical data.

## Approved Change (implemented on Dev)

### Official contract and discovery

Keep existing URLs, methods, valid payloads and response envelopes compatible.
Register domain APIs in app/Modules/Clients/routes.php via the existing API loader;
api.php may become a loader shim, matching current migrated domain patterns.
Static context/settings routes must precede dynamic Client/Site bindings.

Add typed context/lookup and workspace endpoints, extend Client and Site resources,
and expose all supported writes/actions in the matrix. Validate unknown fields
rather than silently discarding requested actions; any previously accepted
unsupported input is a documented tightening with regression coverage.
Preserve omission, null, false and zero distinctions. Bound page sizes, stable
pagination, ordering and completeness metadata; do not expose unauthorized totals.

GET/context performs no local writes, session-context mutation, lazy migration,
integration creation, provider refresh/provisioning, queue dispatch or audit
cleanup. Missing/disabled provider setup returns an explicit capability state.
Provider-backed number/customer discovery is a distinct authorized, bounded
read-only lookup; never a reservation or proof of creation permission.

### Authorization

Use Sanctum abilities plus the current active actor's effective domain rights.
Existing Client scopes remain clients.read/create/update, with finer settings,
Site-delete and licence abilities added only with matching operations and tests.
Do not widen existing tokens automatically. Resolve actor and record scope again
on every request/replay; fail closed on unavailable authorization state.

Core reads require client.view; settings requires client.manage_settings;
Notes uses client.update; Site create/update/delete follows current client
permissions. Custom fields retain definition-level view/edit restrictions.
For the atomic browser-equivalent Client/default Site/primary Contact workflow,
retain the existing client.create authority and Contact-owned action. It cannot
edit arbitrary existing Contacts; canonical independent Contact API actions keep
their own permissions and token abilities. Contact details in read responses
remain subject to contact.view. No new general Contact authority is granted.

Workspace discovery only advertises resources the actor may use. Reading Client
data does not grant Contact, Ticket, Asset, Task, Commercial, Signal or Risk access.
Projected fields/counts must use each owner domain's authorization and query.
Time corrections require client.view plus current source ownership/permission
and a matching API action ability. Higher permissions are not inferred from
client.update. Staff licence writes retain integration.cloudfactory_write.
Portal APIs resolve current CustomerPortalContext and client-wide Customer admin
membership; caller-supplied client/site/role identifiers cannot grant access.

### Shared local commands and read-back

Extract shared Actions/Queries for settings, Client/Site writes, Notes and time
corrections where current logic lives in controllers. Keep browser redirects and
English UI copy intact; use the same validations, fields and side effects.

Offer optional primary_contact and explicit RMM creation input on Client creation,
preserving the existing API's Client/default Site-only mode for compatibility.
Use canonical Contact deduplication, relations and stable bridge identities in one
local transaction. A failed local validation must leave no partial local graph.
Never copy contact email into Billing Email automatically.

Site APIs enforce exact parent ownership, default-Site transitions and current
foreign-key/reference restrictions. Site delete must report the actual outcome;
do not cascade historical records or silently reassign provider-bound addresses.
Any policy decision beyond the currently supported delete semantics requires an
RFC amendment before implementation.

Return persisted fields, authorized relationships, strong revision/conflict data,
read-back links and truthful command/side-effect status. Require expected revisions
for commands vulnerable to stale overwrites, including time correction and
provider changes. Preserve established legacy update compatibility explicitly;
do not impose undocumented required fields on existing Client PATCH consumers.

Persist command identity before effects for retry-sensitive operations. Bind
durable idempotency to actor/token context, operation, parent, payload and revision.
Same key with another payload conflicts; current denial must still defeat replay.
Use shared database locking with Economy source ordering so a time entry cannot
be corrected while simultaneously being allocated to an order.

### Tripletex, RMM and CloudFactory boundaries

Preserve approved Tripletex number/profile authority: when customer sync is active,
require tripletex_number_mode=true and one stable tripletex_request_key UUID; omit
caller-supplied client_number in the normal provider-authoritative command.
An unknown attempt must be reconciled before any retry. Paused mode preserves
linked identifiers. Billing Email and the explicitly bound Site address retain
the existing reconciliation baseline/conflict rules.

RMM lookup/link/unlink and optional explicit create use the verified Integration
connection, not caller-provided URLs/credentials. Persist/reconcile uncertain
creation so retry cannot create a duplicate provider customer. A local creation
with failed provider creation is a truthful partial result, not rolled-back
external success or implicit success. No connection is enabled as a side effect.

Staff licences support current issue, quantity, renewal and Microsoft status.
Portal supports only its current issue, quantity and renewal actions, with frozen
terms/contract/offer/quantity/name/confirmation evidence via RecordLegalAcceptance.
Reuse CloudFactoryLicenceService and its ledger; add only missing durable request
binding, revision and read-back around it, not a second provider truth.

Honor accepted CloudFactory write safety: active eligible contract, provider
capability, saved write switches and existing pilot/client restrictions. Submitted
is not confirmed. Unknown outcomes require reconciliation, never blind repeated
POST. Queue only the existing appropriate post-write sync when newly authorized,
and do not repeat that dispatch on replay. A dedicated API provider-write switch
is default-off, independent from existing browser behavior and provider settings.

## Impact Analysis

Clients: routes, API resources/requests/guards, Actions/Queries, controller
delegation, Notes action and tests. Existing module namespace remains Clients.

Contact: canonical primary creation/relations, bridge preservation and related
reads. Issue #254 remains separate. CustomField owns definitions and field policy.
Commercial: timebank query/registration, rate/contract ownership and legal evidence.
Ticket/Task: time-entry policy, coupled billing projections and allocation.
Economy: ordered-source protection, correction/ordering locks and totals.
Integration/DataExchange: token ability catalogue, API documentation, RMM and
CloudFactory operations, approved Tripletex numbers/profiles and import authority.
CustomerPortal: active membership/client/site context for licence projection.
Asset/Risk/Signal/Task: authorized workspace references; no new write ownership.

Main risks are actor/token divergence, fields missing from read-back, stale
overwrites, duplicate paid provider writes, false provider confirmation, conflicting
time correction/order allocation, cross-client joins and shared dirty-file edits.
Before each slice, re-read its exact files and diff; do not reset shared work.

No new business UI, queue consumer, recurring job, global configuration dump or
provider connection is required. Existing post-write sync and approved Tripletex
scheduler behavior remain owned by their existing services.

## Data And Migration Plan

Reuse current Client/Site/Contact/provider schema. Add only minimal command receipt
and locking evidence if existing owner-domain ledgers cannot supply the contract.
Do not introduce soft deletes, renumbering, destructive backfills or data imports.
The exact additive migration and deployment order must be documented with its slice
before execution. No migration or native customer write occurs during drafting.

Use local transactions and native unique/row-lock constraints for local effects;
provider transport cannot be part of an atomic local transaction. Persist intent
before transport and preserve outcome/reconciliation evidence across local failure.
Keep secrets/raw provider payloads out of ordinary resources/logs. Encrypt sensitive
receipt data according to existing approved domain command patterns.

Deployment after review: apply only required additive migrations, clear relevant
Laravel caches, regenerate Swagger and verify trusted HTTPS read-back. Refresh
workers only if changed queued classes require it, after assessing current work.
Do not run broad migrate/queue restart/scheduler activation as a convenience.
Rollback preserves receipts, provider IDs, legal acceptance, encryption keys and
source financial evidence; disabling new API writes is the first recovery step.

## Testing Plan

For every C01-C20 mapping, specify the exact operation/schema/test and side-effect
assertions before marking the row complete. Use provider fakes and synthetic data.

- Correct actor+token, wrong/missing scope, revoked/inactive actor, field denial,
  foreign parent, unauthorized referenced record and changing membership.
- List filters/sorting/pages/completeness; resource read-back of every editable
  field; authorized create/edit context; no GET/session/provider write side effects.
- Local create with/without Contact, Contact deduplication, rollback of related
  records, local allocation races/exhaustion and provider mode/replay/reconciliation.
- Site detail/create/update/delete, address/zip preservation, default transitions,
  referenced/bound Site rejection and field-definition authority.
- Notes-only patch and settings/RMM rights; field omission/null/false semantics;
  stale revisions and key/payload conflicts.
- Quick/Ticket/Task time correction, ordered source denial, rate/allocation
  recalculation, coupled Task billing refusal and native concurrent order locking.
- Staff and portal licence issue/quantity/renewal/status as supported, contract,
  terms/legal acceptance, disabled settings, uncertain outcome/reconciliation,
  read-back and repeated/concurrent requests with exactly-once intent/effects.
- Legacy aliases preserve canonical Contact mapping and historical bridge IDs.
- Production-mode Swagger includes every method and typed schema; real trusted
  HTTPS verifies correct authentication, safe read-back and denial behavior.

Run narrow Clients/CustomField/Contact tests first, then affected Commercial,
Economy, Ticket/Task, CustomerPortal, Integration CloudFactory/Tripletex and API
administration regressions. Run relevant commands on authoritative Dev with
umask 0002 and HOME=/tmp. Check syntax/Pint for changed PHP.
A failure must be resolved or explicitly reported; existing failures are not green.
Real provider writes require a separately authorized controlled scenario.

## Documentation Plan

Update Clients overview and add Clients API guidance with operation examples,
abilities, actor rights, context, revisions, replay, disabled/partial/unknown states
and exact read-back. Update related source articles only where behavior changes.
Publish scoped Knowledge changes through the supported BookStack integration;
verify actual article bodies and inherited employee access. Employee OpenWebUI
retrieval remains a separate claim. Record blocked publication with owner/next
action in TODO. A draft RFC does not change the employee handbook yet.

Maintain the gap matrix, typed operation catalogue, verification evidence,
TODO and HR-2026-10-25-CLIENTS-API throughout implementation.
Only verified customer-safe completed functionality goes to the website handoff;
retain do-not-publish gates until release/production acceptance.

## Open Questions

Design approval received from Svein Tore on 2026-10-25. No unresolved product decision. There is no request to approve Client deletion,
provider activation, a production change, or a Main merge.

All six Feature Slices are implemented and verified on Dev. The complete C01-C20
mapping, 51-operation/74-schema contract and exact test/native/handbook evidence
are in ../plans/2026-10-25-clients-api-verification.md and its JSON companion.
Human-review checklist HR-2026-10-25-CLIENTS-API is Reviewed by Svein Tore. Main promotion,
production rollout, provider activation and external MCP publication are separate.

## Approval

Approved by Svein Tore in conversation on 2026-10-25: "Ja", in direct response
to the presented RFC and request to implement on Dev. All C01-C20 are approved.
This is design/Dev implementation approval, not human acceptance or Main/production authorization.

Implementation clarification: current AdminAccess is active-account plus route permissions,
not a hard-coded Admin role check. Client Formats API follows that existing policy.


Implementation clarification: the approved non-cascading Site delete requirement
also protects legacy cascade/null-on-delete references. Native schema inspection
confirmed Client User/Documentation cascades and Site-scoped portal membership
nulling. Shared deletion refuses these referenced Sites rather than deleting history
or silently expanding portal scope. RMM intent creation and deletion use the same
Client/Site lock order. Move/review linked records through their owner workflows first.

## Implementation evidence

Completed on authoritative Dev, with the exact additive migration applied at batch 26.
See ../plans/2026-10-25-clients-api-verification.md and .json for changed files,
operation-to-workflow tests, corrected regression failures, native lock/unique
checks, trusted HTTPS/Swagger and actual BookStack publication/read-back.
Provider API writes remain default-off; no real provider writes, Main or production changes.

Promotion authorization: Svein Tore explicitly accepted the completed delivery on
2026-10-25: "Godkjent. Merge til main". HR-2026-10-25-CLIENTS-API is Reviewed.
The review/checklist statements above describe the preceding Dev handoff; Main
promotion evidence is recorded in 2026-10-25-clients-api-main-promotion.md.
Production deployment and provider activation are not authorized by this approval.
