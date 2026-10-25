# RFC: Full Data Exchange Browser/API Coverage

Status: Approved
Date: 2026-10-25
Owner: Codex
Change Level: 3
Requested by: Svein Tore; full DataExchange API support requested in conversation.
Implementation: Done On Dev; approved 2026-10-25. HR-2026-10-25-DATA-EXCHANGE-API Reviewed; Main promotion authorized.
Related: [coverage matrix](../plans/2026-10-25-data-exchange-api-gap-matrix.md),
[platform RFC](2026-07-03-data-exchange-platform.md),
[ownership ADR](../adr/2026-07-03-data-exchange-platform-ownership.md),
[Economy API RFC](2026-10-25-economy-full-api-parity.md).

## Context

DataExchange needs complete operational API support. The authoritative Dev working copy has six
HTTP operations for profile listing, export execution, run inspection, file download, import dry-run
and import commit. The admin workspace and Livewire builder expose additional supported workflows.
The approved July platform RFC establishes ownership and the original limited API; it does not
specify the complete management/action contract proposed here.

The 2026-10-25 Economy work is recorded Done On Dev, Reviewed and merged to Main. Its existing
DataExchange controller edits and canonical/custom Economy ownership guards are dependencies to
preserve, not competing changes to revert. Workday/Tripletex customer and time-sync services remain
under their existing RFCs, authority and rollout gates.

## Goals

Deliver one official, typed, documented API for every supported DataExchange read, write and action:
source/field/filter discovery, profile composition, export preview/run/file history, import preview
inspection/commit, schedule management, delivery target management and delivery execution/evidence.
Use shared domain actions in browser, Livewire, API and jobs. Keep actor, token, source, field,
parent and domain-owner rights equivalent across entry points.

A caller must discover available operations, validate inputs, preview consequential actions,
execute with retry protection, and read back the exact saved/result state. Read-only coverage is
not completion. Swagger/OpenAPI and meaningful API/authorization/side-effect tests ship with each
implemented slice. Runtime availability does not implicitly activate provider delivery.

## Non-Goals

- New arbitrary data sources, raw SQL, secret export, direct writes to arbitrary domain tables.
- New Tripletex/PowerOffice connectors, payroll/customer authority changes or provider activation.
- New arbitrary delete, purge, retention cleanup, profile cloning or report-builder workflows.
  Existing supported profile/schedule/target pause and edit operations remain fully covered.
- Implementing automatic import pickup, which the current job explicitly skips. Advertise this
  as unavailable and reject activation consistently in web/API rather than queue a false success.
  Manual supported dry-run and commit remain included.
- Commit/push/merge, Main promotion, production rollout or real customer/provider writes.
- New MCP transport or a separate API business engine; consumers use the official domain API.

## Current Behavior

Verified against Dev source on 2026-10-25:

- Six routes in app/Modules/DataExchange/api.php; admin routes in routes.php and profile writes
  in Livewire/Admin/ProfileBuilder.php. No dedicated typed DataExchange OpenAPI class was found.
- API profile listing has limited summary fields; no profile detail/create/update, source discovery,
  schedule/target management, import preview GET, or general history/discovery endpoints.
- API middleware checks six token abilities. Non-Economy paths lack a uniform fresh-actor plus
  current DataExchange/source permission check. Economy has explicit owner-domain guards.
- Profile save writes profile/source/field/filter/mapping rows sequentially; the API must use a
  shared atomic operation and validate registry-visible sources, fields and filters.
- Import commit checks preview status before its transaction without locking/reloading the preview.
  No durable command replay or profile/preview revision contract exists here.
- Delivery uses configured filesystem disks. A put result is not checked and destination bytes
  are not read back; a stored succeeded status currently does not prove destination content.
- Due schedule planning dispatches jobs without claiming a unique occurrence. Import scheduling
  is accepted in forms but the job records scheduled_import_pickup_not_configured and skips it.
- Dashboard GET initializes profile templates; API discovery should remain free of business writes.
- Sensitive fields are blocked by the source registry. Stored profile fields must also be
  revalidated during use, including profiles created before current rules.

See the matrix for source locations and complete workflow coverage requirements.

## Proposed Change

### Shared typed contract and discovery

Add domain-owned context and permission-filtered source/field/filter metadata. Expose bounded,
paginated profile/run/file/import-preview/schedule/target/attempt/audit lists and exact details,
with parent ownership and consistent documented filters/errors. Return available actions and
supported capabilities, including disabled automatic import pickup. GET discovery does not seed
profiles, dispatch jobs, generate exports or commit imports. Downloads retain their documented
download audit side effect. Initialization remains an explicit authorized operation if required.

Use the established official module route-loading convention, keep domain declarations owned by
DataExchange, and resolve the legacy api.php/routes.php split without adding Laravel domain route
files or changing other modules' routes. Preserve the six existing URLs and response fields;
additive fields are allowed. Publish precise schemas for success, errors, uploads and binary files.

### Authorization and profile composition

Use current active actor plus explicit Sanctum token abilities and current user permissions on
every path, including replay and background execution. Add only route-backed, tested management,
schedule and delivery abilities; never broaden existing tokens. Source discovery and execution
also enforce the source's owning-domain rights; Economy canonical/custom profiles retain
EconomyDataExchangeAccess and EconomyExportWorkflow semantics.

Extract profile composition into a shared atomic domain action used by Livewire/API. Validate the
complete supported builder state, source/direction, allowed fields/operators, output-key uniqueness,
mapping, profile key normalization/uniqueness and strict unknown-field rejection. Reject raw SQL,
blocked fields, caller credentials and unsupported settings. Preserve fields not owned by the
supported editing contract. Return saved source/field/filter/mapping configuration with a strong
revision; refuse stale edits before any write.

### Export and imports

Add export preview and exact run/file/checksum/history read-back. Generic export execution binds
profile revision and approved options to a durable idempotency record. Preserve canonical Economy
export preview/command/replay behavior and never silently run another profile or customer scope.
CSV/XLSX/JSON continue through the existing domain-owned registry and format runtime.

Import dry-run has typed multipart input, row errors and a readable, bounded preview projection.
Bind commit to reviewed normalized rows, source/profile revision, input checksum and current rights.
Reload/lock preview inside the transaction; revalidate stale source data through the owning import
target before write. Concurrent/repeated commits cannot apply domain writes twice. Return exact
commit/run/result identity; durable replay survives ordinary audit cleanup. Use identical guards
for browser and API, including invalid rows, changed mappings and provider-authoritative Clients.

### Schedules and delivery

Expose exact schedule/target list/detail/create/update including pause/reactivation. Validate
direction, active profile, target/profile association, frequency/timezone/weekdays and approved
filesystem/credential references. The API accepts references, not credentials or arbitrary server
filesystem access. Unknown/incompatible targets and unavailable import pickup fail validation.

Make scheduler occurrence claims and command intent durable to prevent duplicate runs after
concurrent dispatch/retries. Recheck effective actor/source/target rights at execution, and define
schedules as attributable approved operations rather than anonymous permission bypasses.
Keep existing enabled schedules compatible through explicit migration/preflight assessment.

Expose delivery preview/dispatch, attempt status/history and guarded retry for an existing file and
approved target. Bind intent to exact file checksum and frozen target configuration. Check actual
write results and destination read-back; distinguish queued, writing, verified success, failed and
unknown outcome. Unknown remote outcomes require reconciliation before retry. Local/fake disks
test behavior without real external transfers. New external API delivery remains default-off until
separate controlled activation; existing runtime activation settings are preserved.

### Audit and operational integrity

Audit configuration and execution without credentials, raw filesystem configuration or unbounded
customer content. Distinguish accepted/queued commands from completed domain writes and verified
destination bytes. Recheck scope on historical runs/files/previews even if profiles were paused or
soft-deleted. Preserve immutable run/import/delivery/command evidence on edits and rollback.

## Impact Analysis

- DataExchange: controllers, shared actions/queries, builder, registry validation, models, jobs,
  tests and official typed API contracts. Preserve existing format/retention semantics.
- Integration: only tested ability catalog/API documentation and shared authorization conventions;
  credential ownership stays in Integration.
- Economy: preserve canonical/custom ownership and existing durable export path; regression tests.
- Client: supported import target validation/permissions and provider authority; regression tests.
- Workday/Tripletex: preserve existing mappings, sync history, credentials and paused/enabled state.
- Data: additive command receipts, revisions/snapshot identities and schedule occurrence evidence
  as required by the final schema review. No destructive backfill or automatic import is approved.
- Queues/scheduler: locking, attributable execution and honest delivery state. Actual external
  scheduler runner and worker behavior must be checked before claiming live scheduled health.
- UI: only shared-action parity, validation and honest capability handling; no unrelated redesign.
- Documentation: module/API/Knowledge/BookStack, TODO, ADR, verification and human review.

## Data And Migration Plan

Inspect current native schema and existing records before final additive migration design.
Document exact migration, columns/indexes, unique replay/occurrence keys and enabled-schedule
compatibility in the implementation ADR. No migration runs at draft stage. Native concurrency
checks must complement SQLite tests. No ambiguous retry or destructive historical rewrite.

Apply only reviewed, additive Dev migration paths after backup/preflight. Generate OpenAPI, refresh
required route/config caches and deliberately restart workers only when implementation requires it.
No frontend build expected unless the actual changed assets require one. Document exact commands
after implementation. Rollback disables new entry points while retaining durable evidence and
existing file/encryption/credential references.

## Testing Plan

- API success and denial for every matrix row; missing/wrong token, inactive/revoked actor,
  removed permission, foreign parent/source, unknown fields and secret-field rejection.
- Shared browser/Livewire/API profile validation, atomic rollback and stale-revision conflicts.
- CSV/XLSX/JSON exact content, checksum and failure results; pure reads and bounded pagination.
- Import parse/invalid rows, complete preview, stale mapping/source/provider authority, concurrent
  commit and durable replay with no doubled Client writes.
- Schedule occurrence concurrency, actor revocation, pause/update, incompatible targets and honest
  automatic-import rejection; no real provider activation.
- Delivery write-false, exception, checksum/read-back failure, target mutation, unknown outcome,
  retry/reconciliation and duplicate-command guards using controlled fake/local disks.
- Economy export canonical/custom, Client import, Integration abilities/Swagger and relevant
  Workday/Tripletex regression sets on Dev. Native lock/unique probes and trusted HTTPS read-back.
- Scoped syntax/Pint and typed OpenAPI method/schema inventory; no unresolved failing test is
  silently deferred. Record baseline separately from verification of future implementation.

### Baseline Verification (Before Implementation)

On 2026-10-25, authoritative Dev command
`umask 0002 && HOME=/tmp php artisan test app/Modules/DataExchange/Tests/Feature`
passed: 11 tests / 52 assertions, 34.85 seconds. These are existing foundation/runtime tests;
they do not verify the proposed missing API operations or concurrency guarantees.

## Documentation Plan

Update module README and data-exchange-overview Knowledge source; explain exact menu/button names,
API prerequisites/scopes, profile/import/schedule/delivery steps and truthful unsupported behavior.
Update API Management and related Economy/Client guidance only where changed.
Publish/sync through the supported Knowledge integration and read back actual BookStack content
and inherited employee access. Record publication blockers with owner/next action in TODO; employee
OpenWebUI retrieval is a separate verification claim.

Create implementation ADR and HR-2026-10-25-DATA-EXCHANGE-API before completion handoff. The human
review entry must cover browser/API equivalence, imports, schedule/target scope, delivery evidence,
retry/concurrency, Swagger and handbook. Only named human approval marks it Reviewed; it blocks
Main promotion/release. Provide public-safe website handoff with explicit publication gates.

## Feature Slice Sequence After Approval

1. Foundation: DX01 context/source discovery, fresh actor/domain authorization, typed contract,
   pure reads and baseline compatibility.
2. Profiles: DX02-DX03 atomic shared builder, detail/create/update/revisions and registry validation.
3. Runs/files: DX04-DX05 export preview/command/replay, history and exact authorized downloads.
4. Imports: DX06-DX07 readable previews, reviewed atomic commit/replay and domain validation.
5. Operations: DX08-DX10 schedule/target management, occurrence claims and delivery/reconciliation.
6. Acceptance: DX11-DX12 audit/cross-domain checks, native/HTTP/OpenAPI/handbook verification and HR.

Create concrete Feature Slice documents after target approval; each must be independently testable,
registered under this one primary TODO workstream, and keep shared dirty work intact.

## Open Questions

None blocking. Svein Tore approved the complete contract, including honest unavailability of automatic import pickup.

## Approval

Approved by Svein Tore in conversation on 2026-10-25: "Ja godkjenner".
This approves implementation and verification on Dev; human acceptance, Main and production remain separate.
