# RFC: Full UserManagement Browser/API Parity

Status: Approved by Svein Tore, 2026-10-27. Implementation: Verified on Dev; final broad rerun and Main promotion in progress.
Date: 2026-10-27
Owner: Codex. Product/release owner and intended human reviewer: Svein Tore.
Change level: 3 - API, permissions and authentication integration.
Planned human-review checklist: HR-2026-10-27-USERMANAGEMENT-API (not reviewed).

## Context
Svein requested "UserManagement domain: lag 100% API støtte". Fresh authoritative
Dev discovery finds 49 browser route rows, 12 API route rows and 13 HTTP operations
excluding HEAD. See ../plans/2026-10-27-usermanagement-api-coverage-matrix.md and JSON.
The scope includes reads, writes, actions, administration, public invitation and SSO
protocol surfaces, and embedded role-permission Livewire actions.
No approved full UserManagement parity RFC was found. The older technician-profile
RFC remains Draft and does not authorize new profile concepts or skills ownership.
Current supported behavior and approved SSO/Workday/Vault boundaries take precedence.

## Goals
Expose every supported current UserManagement operation through an official typed API,
with equivalent native actor/object/session rights, validation, shared effects, exact
read-back and meaningful tests. Include context, Swagger, ability discovery and handbook
publication/read-back. API scopes never grant roles, permissions or fresh human assurance.
100% means verified workflow coverage, rather than endpoint counts or scope-only CRUD.

## Non-Goals
New identity systems, personal integrations, generalized skills ownership, automatic SSO
account linking/provisioning, changing MFA policy or existing protected authentication
cutover, granting permissions to humans, real-account tests or unsolicited invitations.
Svein Tore explicitly authorized tested Main promotion on 2026-10-27. Production deployment
and provider/auth/runtime activation remain separate and are not authorized.
Existing Knowledge parity work and Vault Slice 04 remain independent and preserved.

## Current Behavior
User API provides listing/detail/create/profile/status/role assignment/invitation and
own work-plan/calendar display preferences. It lacks native web permission checks on
legacy administrative methods and has incomplete request/success OpenAPI contracts
(including undocumented PUT). Profile uploads, complete own preferences, role/permission
CRUD and permission assignment, security settings and SSO workflows need typed coverage.
User lifecycle is status-based; there is no supported destructive user-delete operation.
New-user creation currently queues an invite for pending accounts. Queued is not delivery.
Protected bearer writes explicitly fail closed when UserSecurity cutover is enforced;
new endpoints must not bypass that check or fabricate trusted session/execution contexts.
SSO links exact issuer/subject, requires local verification for sensitive operations and
retains session-bound state/nonce/PKCE. Provider-owned login MFA does not replace local
step-up for linking/settings or Vault. Existing profile integrations/view pages contain
only placeholder status; they do not authorize a new provider/token system.

## Proposed Change
1. UM01: consolidate official domain registrations in UserManagement/routes.php with
   api.php as a compatibility loader. Preserve current URLs, PUT/PATCH and existing
   users scopes. Add native active-human/tech/object and user.view/create/update/
   manage_roles/invite checks matching each web operation. Provide context/lookup and
   narrow edit forms without exposing broader account/private data. Enforce every
   embedded role assignment's current rights, including roles in create payloads.
2. UM02: add own profile, avatar upload/removal, full preferences/theme and current
   integration/view status. Reuse existing profile/preferences actions. Preserve weekly
   hours, own calendar preferences/revision and employee work-plan contracts. Match
   web field aliases, file limits, timezone validation and related Ticket consumers.
3. UM03: add role and permission list/detail/create/update/delete and role-permission
   membership operations, including discoverable form/search context. Extract shared
   actions for web/API where needed. Preserve current user.manage_roles versus
   user.manage_permissions, guard names, Spatie cache and Email authority invalidation.
   Prefer explicit desired membership over a retry-unsafe toggle; test identical effects.
4. UM04: add own security status and password/TOTP enable/confirm/disable/recovery-code
   operations, plus current MFA enforcement settings. Execute through the same approved
   security boundaries, requiring real fresh local verification/session/flow context
   where web does. Separate narrow token abilities for security/settings from broad
   user updates. Never expose another user's factors or put credentials, setup secrets,
   recovery codes or passwords in receipts/audits/logs/Swagger examples. Self enrollment
   material may be returned only in its authorized no-store response with equivalent
   current web assurance. Add real cutover denial and permitted-flow tests; unresolved
   protected-writer dependency blocks completion, not the security control itself.
5. UM05: add SSO account/provider status/settings, identity link/unlink/logout and typed
   protocol discovery/handoff/results preserving session binding and current provider
   checks. Retain OIDC callbacks/backchannel protocol, throttles, signed logout validation,
   issuer/subject ownership, reauthentication and session revocation. No headless linking
   via an email address or generic bearer token. Redact provider secrets on reads.
6. UM06: add invitation capability inspection and guarded single-use acceptance via
   existing expiry/activation/password boundary. Keep public routes capability-based
   with equivalent throttling and generic errors. Add explicit invite resend scope,
   policy, duplicate/replay guard and exact local read-back without claiming inbox delivery.
7. UM07: introduce actor/token/operation-bound idempotency and concurrency for eligible
   local writes, with canonical payload fingerprints, opaque preconditions, atomic effects
   and sanitized audit. Durable receipts must never retain passwords/TOTP/recovery/setup
   material. Protected secret-bearing/protocol operations follow their owner-issued
   execution/replay boundaries; do not replay credential material from a general receipt.
   Keep dispatch/provider effects separate; uncertain send/provider outcomes require
   reconciliation. Recheck native authority and current scope on replay/result lookup.
8. UM08: maintain exact route/action-to-operation/scope/schema/test/read-back coverage.
   Type request/success/error responses and every HTTP method including legacy PUT.
   Publish API/employee guidance via supported Knowledge/BookStack integration and read
   back actual content/access. Do not mark human review complete from automated tests.

## Impact Analysis
UserManagement owns actions/controllers/resources/routes/tests, local profiles,
preferences, user lifecycle, roles/permissions and security/SSO integration. Integration
owns API ability discovery and Swagger scanning. Email owns live authority invalidation;
Ticket consumes canonical profiles; Calendar/Workday consume preferences/work plans;
Fortify/UserSecurity/Vault own protected writer/reauthentication boundaries. CustomerPortal
identity remains separate. Notification/API-token settings linked from profile retain
current domain ownership. Shared Integration/Swagger files may overlap active Knowledge
work: reread latest content and make only additive coordinated edits after approval.
Risks: unauthorized legacy tokens must fail correctly; role changes can invalidate live
content; sensitive payloads must never persist; stale/replayed writes must not duplicate
invites or roll back security. Existing dirty contributor work is preserved in place.

## Data And Migration Plan
No business-data migration, role grant or auth-provider switch is planned. Only an
additive domain command-receipt schema if required for eligible non-secret writes;
final filename/schema must be documented and tested before migration. Do not retrofit
protected owner schemas or activate Vault. Test exact migration on disposable native DB
and approved Dev path; never run all pending migrations. Preserve APP_KEY/receipts and
recover via scoped code rollback, not by deleting live audit/history. Regenerate Swagger
and clear appropriate caches after authorized deployment. No new frontend build, queue
worker or scheduler is expected; existing invitation jobs/provider runtime remain gated.

## Testing Plan
Start with existing user API, work-plan and Calendar preference tests. Then sequential
feature suites cover each operation with actual bearer tokens, no token, missing scope,
missing/revoked native authority, disabled/system/portal identities, own versus foreign
records, field validation, secret exclusion, file upload/remove and read-back. Cover
permission cache/Email revocation, protected auth cutover and real valid guarded paths,
password/local MFA and provider-owned SSO regression, PKCE/state/nonce/signed logout,
invite expiry/reuse/rollback/dispatch and duplicate/stale writes. Match successful HTTP
responses to generated Swagger and assert exact runtime method/path/schema inventory.
Verify trusted HTTPS and native DB constraints without real customer mutations.
Run affected cross-module regressions; report failures before claiming completion.

## Documentation Plan
Update module API and employee Knowledge sources, Integration abilities/API guide,
coverage matrix, TODO, RFC/ADR/slice status and human-review checklist. Publish affected
handbook content through supported BookStack integration and verify actual content and
employee access; record concrete publication blockers/owner if any. Dev, Main, production,
provider activation and employee assistant retrieval are separate claims. Update the
public-safe website handoff only after verified implementation, not from planning.

## Sequential Feature Slices
See ../feature-slices/2026-10-27-usermanagement-full-api-parity.md. Complete and verify
UM01 through UM08 sequentially; no parallel agent work is requested or started.

## Open Questions
Approve this concrete scope and security-preserving design for implementation on Dev?
No unresolved new product policy is silently decided. If existing protected-owner APIs
cannot provide equivalent authorized execution, record the exact dependency and resolve
its design before implementation of the affected operation or any full-parity claim.

## Approval
Svein Tore explicitly approved this RFC and tested Main promotion on 2026-10-27:
"Godkjenner og merge til main". This authorizes sequential Dev implementation, verification
and a scoped tested Main merge. It does not mark later human scenario checks Reviewed
or authorize production deployment/provider activation.

## Baseline Verification - 2026-10-27

Existing authoritative Dev tests passed: 17 tests / 143 assertions, zero failures
or skips, 36.93 seconds. Command: HOME=/tmp php artisan test
app/Modules/UserManagement/Tests/Feature/UserManagementApiTest.php
app/Modules/UserManagement/Tests/Feature/UserWorkPlanTest.php.
This proves the current baseline only, including protected-bearer cutover denial;
it does not prove the proposed missing operations or full API support. No runtime,
account, permission or provider setting changed. Application source hashes remained
unchanged during this planning pass. RFC approval and implementation remain pending.
