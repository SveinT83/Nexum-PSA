# RFC: Full System Browser/API Parity

Status: Approved
Date: 2026-10-27
Owner: Codex. Product owner and intended reviewer: Svein Tore.
Implementation: Done On Dev / Merged To Main / Human Review Pending; approved by Svein Tore, 2026-10-27.

## Context
Svein requested "System domain, vi trenger 100% API støtte". Authoritative Dev has
13 browser rows (5 read, 2 write, 6 action), zero System API operations and zero
System Swagger paths. The existing version-status JSON requires a browser session.
The complete proposed 19-operation contract and current method/action/middleware
evidence are in ../plans/2026-10-27-system-api-coverage-matrix.md and its JSON file.
This plan does not certify implemented parity.

## Goals
Expose every current System read/write/action: authorized admin discovery, company
profile, all branding/theme fields, three logo slots, presets, reset, unsaved preview,
local/GitHub version status, queue overview, graceful restart request, clear queue,
failed retry one/all and flush. Include current native authority plus token scopes,
strict requests/results/errors, durable exact read-back, meaningful tests, complete
Swagger and verified employee handbook. Read-only endpoints alone do not satisfy this.

## Non-Goals
No generic shell/Artisan API, updater/deployment service, process manager, environment
editor, scheduler activation or new health platform. #262 remains independent runtime
reconciliation. No real backlog retry/deletion, worker restart or customer test send.
Personal theme/avatar/MFA/SSO remain UserManagement-owned; hub links retain destination
domain ownership. This initial request does not authorize commit/push, Main promotion,
production changes or enabling API maintenance.

## Current Behavior
CompanyProfileSettings stores organization/branding together in common_settings
(type company_profile, name branding). Organization validates 11 fields; branding
validates 46, including three uploads and three removal flags. Defaults include
light/dark/system theme, three brand colors and light/dark shell/button surfaces.
Preset changes known colors while retaining company data/logos; reset removes logos
and restores visual defaults without clearing organization data. Public logos use
company-branding/. Consumers include portal/PWA shells, Booking, Commercial PDFs/
delivery fingerprints and Notification SMS.

Web authorization is active internal access plus EnforceTechRoutePermission:
system.view for hub/version, system.settings_manage for profile/branding and
system.queue_manage for queue reads/actions. AdminAccess is permission-based;
a hard-coded Admin role would incorrectly exclude authorized actors. Preserve
permission existence, Superuser and empty-permission legacy Admin fallback rules.
UserManagement owns assurance; reuse its supported token/actor policy without
fabricated sessions or bypassing required enrollment/login assurance.

Queue overview is database-table evidence, not worker/scheduler liveness or Redis/
other-backend counts. Current failed rows include a raw truncated exception line.
Maintenance invokes fixed Laravel commands but ignores exit codes and returns console
text. Clear defaults to configured queue; omitted retry target means all failed jobs.
API results must use safe typed evidence, explicit targets and confirmations.

## Proposed Change
1. Implement all 19 matrix operations under /api/v1/system in System/routes.php with
   safe web/API loading. Keep web URLs stable. System owns controllers, validation,
   resources, actions, queries and tests. Integration owns token catalog/discovery.
   No domain endpoint may be added to Laravel entry route files.
2. Share current settings/maintenance/query actions and extracted validation between
   web/API. Preserve all supported fields/defaults/preset/reset effects. Strict
   per-surface field allowlists prohibit cross-surface mass assignment, arbitrary
   storage paths/disks and executable targets. PUT branding is JSON; POST on the
   same resource provides real multipart uploads. Include uploaded-byte hashes in
   replay identity. Return public logo URLs/safe metadata, never filesystem paths.
   Preview validates/normalizes unsaved visual settings without durable files or
   settings writes. Local file preview may remain client-local.
3. Proposed scopes: system.read, system.settings.read, system.settings.write,
   system.queue.read, system.queue.restart, system.queue.clear, system.queue.retry,
   system.queue.flush and system.operations.read. Add them only with matching
   routes/tests. Independently enforce current native rights, active internal actor,
   live token expiry/revocation and shared permission policy. Settings/queue actors
   must not additionally require unrelated system.view. Token '*' grants no actor rights.
4. Context includes authorized admin destinations and local build metadata without
   GitHub calls. Version status reuses the fixed trusted-HTTPS GitHub client, current
   30-minute cache, failure gate and stale states. No token/raw provider response,
   arbitrary repository/URL/branch or destination authority is exposed or accepted.
5. Require Idempotency-Key for effectful commands, opaque If-Match settings version,
   durable actor/token-bound receipts, canonical input/file fingerprints and fresh
   rights on replay. Changed-input replay conflicts; stale writes stop before effects.
   Lock the shared aggregate across organization/branding and web/API, including
   concurrent first creation. Stage logos; commit settings/receipt before cleaning
   obsolete files. Failure retains previous usable files. Presets retain logos; reset
   preserves company data. Receipt reads require operations.read plus current source
   native permission and original operation scope; no cross-actor/token disclosure.
6. Queue preview identifies fixed action, configured backend, explicit queue/job UUID
   or explicit all, available cohort counts and impact. It returns an expiring bound
   confirmation without queue mutation or provider calls. Execute requires matching
   current confirmation, dedicated action scope/native rights and installation-owned
   default-off API-maintenance switch. Installing API support does not activate it or
   disable supported browser controls. No arbitrary connection/command/shell arguments.
7. Durably claim each maintenance attempt before dispatch and coordinate web/API
   target locks. Recheck backend/cohort immediately before effects and conflict on
   detectable drift. Require explicit confirmation for restart, clear, retry-one/all
   and flush; retry can cause business side effects. Verify adapters for every
   browser-supported backend operation. Unsupported/unverified guarantees remain
   explicitly gated; do not claim 100% on a deployment with an unverified supported
   operation. A post-dispatch crash/timeout is unresolved; replay must read the
   receipt rather than execute again. A receipt DB transaction is not proof that
   an external backend command is atomic. Cross-action/cohort races need tests.
8. Check command exit codes and domain evidence. Return accepted/completed/rejected/
   failed/unresolved with safe target/action/outcome, not raw console/exception/job
   payloads. Restart means signal issued, not observed process restart. Retry means
   re-enqueued, not processed/delivered. Read-after-effect counts are timestamped
   snapshots, not attributable success under concurrent workers. Missing/non-database
   counts are unknown/unavailable. Worker/scheduler health needs independent evidence.
   API tests use fakes or isolated synthetic queues, never live historical backlog.
9. Persist only bounded safe audit/receipt results, no file bytes, raw job/exception
   or credential material. Document every input/result/error, JSON/multipart content
   type, scope/native permission, state/confirmation/replay rule in Swagger. Complete
   runtime/contract/test/read-back evidence for each operation before claiming parity.
   Preserve all non-System Swagger paths/schemas and current destination behavior.

## Impact Analysis
System owns settings, queue operations and new receipt storage. Integration is the
shared token/Swagger boundary. UserManagement owns native permissions/assurance.
Regress Booking, CustomerPortal/PWA, Commercial PDFs/delivery and Notification SMS
where they consume company identity/branding. No UI redesign is planned.
Shared Dev has extensive active contributor work and a pre-existing seven-line
System admin-index addition. Preserve it and reconcile exact shared files before
edits; do not start competing permission/auth implementations. This planning starts
no second application-code implementation. After approval complete slices sequentially.
Risks: lost settings/logo files, duplicate/destructive queue effects, leaked payloads,
false success/health, stale/revoked authority and incomplete driver guarantees.

## Data And Migration Plan
Plan additive System command/preview/attempt storage with unique scoped replay identity,
confirmation expiry, bound target/version, safe result and durable dispatch claim.
Finalize exact migration filename/indexes after approval; execute only that migration
on Dev. Reuse existing common_settings/public files; no business backfill, permission
seed, organization rewrite or backlog mutation. Prove native MySQL uniqueness/locks,
first/existing update races and crash/rollback with isolated synthetic data.
Future deploy: exact additive migration, Swagger regeneration, normal cache clear;
verify public storage link if absent. No new scheduler/worker/frontend build expected
solely for API availability. Actual restart remains separate operational work.
Recovery disables new API effects, retains receipts/audit and reconciles unknown
outcomes before retry. Preserve APP_KEY where receipt encryption is used.

## Testing Plan
Current System baseline: 33 passed, 2 failed, 220 assertions, 41.69 seconds.
Both failing SecurityHeadersTest login responses query missing user_sso_providers
in SQLite :memory: while SSO is enabled. Codex coordinates the active UserManagement
owner to resolve correct test/schema/SSO boundary before green handoff; no approved
deferral exists. Do not hide failures by changing production SSO or silently excluding
tests. Initial transient LeadIntelligence duplicate-import bootstrap error was
independently corrected by its contributor; current runtime route discovery succeeds.

Add positive/negative tests for every operation/field, native and token authority,
revocation/expiry/inactive actors, direct permissions/fallback, strict unknown input,
JSON/multipart file lifecycle, exact bytes/fallback, preconditions, replay/receipt
isolation and native settings concurrency. Verify all maintenance actions, single/all
retry, exit codes, driver support, confirmation tampering/expiry/drift, disabled switch,
claim/cohort races and unresolved/no-repeat behavior. Verify safe outcome wording.
Run System and affected UserManagement/branding consumer/Integration regressions,
precise syntax/Pint/whitespace checks, native concurrency probes and trusted HTTPS/
real multipart access. Exact runtime/Swagger method/path/schema sets must match matrix.
Manual UI/worker/recipient observations remain separate from automated success.

## Documentation Plan
Update company-profile/branding, queues/workers, version-status and admin-navigation
Knowledge sources and complete API guide. Correct stale branding defaults against
verified code. Publish via supported BookStack integration and read back actual
article content and employee access. If blocked, record blocker/owner/next action
in TODO; queued publication is insufficient. Planning alone requires no publication.
Keep RFC/ADR/slices/matrix/TODO current. HR-2026-10-27-SYSTEM-API is a planned Pending
human-review checklist; its remaining checks gate production deployment/
release until named approval. Existing HR-2026-07-16-001 stays Reviewed. Website
handoff after implementation must be public-safe, Dev-only and do not publish until
availability is independently verified. OpenWebUI retrieval is a separate claim.

## Open Questions
No additional product decision is needed to propose full current workflow parity.
RFC approval is recorded below and all four implementation slices are verified on Dev.
The baseline failures are resolved; manual review and native BookStack mapping follow-up
remain separate from complete API implementation.

## Approval
Approved by Svein Tore in conversation, 2026-10-27: "Godkjent".
Authorization covers full RFC implementation and verification on authoritative Dev.
Svein Tore subsequently authorized tested scoped Main promotion on 2026-10-27:
"Godkjenner og merge". Production deployment and API-maintenance activation remain separate.


## Implemented Result, 2026-10-27

All 13 browser rows map to 19 supported API operations with nine scopes and 44 typed
schemas. Four slices are Done On Dev. Exact additive migration
2026_10_27_160000_create_system_api_commands ran in Dev batch 43 with schema/read-back.
Final affected verification: 121 passed / 1529 assertions, zero failures/skips; all
19 actual HTTP responses validated against Swagger, native MySQL contention/first
creation/monotonic versions/receipt uniqueness/encryption/rollback, trusted HTTPS and
real multipart input validation passed. Source and shared PHP pass 24-file syntax/Pint.

BookStack pages 366/367/438/546 have exact source/section read-back and existing employee
access verified through the supported connector. Complete API instructions are a
section in 366 because connector creation has a validation bug. Six native Knowledge
records remain pending_push as Dev has no BookStack integration. Svein Tore/Integration
owns configuration/mapping reconciliation before future native push; no sync activation.
No raw backend errors are reported through the System API or its controller dispatch.
No maintenance, backlog release, real worker restart or customer send ran on shared Dev.
Production remains unchanged. HR-2026-10-27-SYSTEM-API is Pending and gates
production deployment/release; scoped Main promotion is explicitly authorized below. No manual check is inferred complete.

## Main Promotion Authorization

Svein Tore explicitly authorized merge on 2026-10-27: "Godkjenner og merge".
Build and verify only the scoped System candidate from current origin/main, preserve
shared Dev work and all Main-owned navigation/permissions/catalog content. Manual
HR-2026-10-27-SYSTEM-API stays Pending for production scenarios; no scenario execution
or production/runtime activation is inferred from merge approval.

## Verified Main Promotion

Main promotion verified on 2026-10-28: PR #361,
https://github.com/SveinT83/Nexum-PSA/pull/361, merge
893ab45e00c65472c6691b9917a726b5fccee285. Merge tree
406e1c13437a38b208deee442c09a6e82b17231b exactly matches the tested candidate;
19 System operations / 44 schemas are present. The additional upstream integration
run passes 44 tests / 1420 assertions; earlier 169-test evidence is a separate run.
Production is not deployed, its migration has not run, API maintenance stays default-off.
HR-2026-10-27-SYSTEM-API remains Pending; all unchecked manual scenarios gate
production deployment/release. Native Knowledge mapping remains owned by Svein Tore /
Integration; existing BookStack publication remains independently verified.
