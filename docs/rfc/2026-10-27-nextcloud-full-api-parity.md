# RFC: Full Nextcloud Browser/API Parity

Status: Approved / Done On Dev / verified Main candidate
Date: 2026-10-27
Owner: Codex; product, privacy and human-review owner: Svein Tore.
Human-review checklist: HR-2026-10-27-NEXTCLOUD-API (Pending / planned).

## Context

Svein Tore requested 100% API support for the Nextcloud domain. The target is the
complete operational surface currently supported in Nexum: reads, writes, settings
and actions. Authoritative Dev inventory: 19 browser route rows, 14 protected
calendar-only API operations and one independently HMAC-authenticated Talk webhook.
These are separate surfaces, not a percentage. Calendar APIs do not configure
connections, identities/groups/folders, full manual sync or Talk bot settings/tests.

Source: /var/Projects/tdPSA, branch Dev, HEAD
36918edbcaae1ce7b1208ba34f84a3a1719f0e98; preserve shared contributor work and index.
Related GitHub Issue #205 is open; body and zero comments read. It proposes a new
multi-destination service-user Talk notification workflow, not implemented browser
behavior. Knowledge API is a separate active authorized workstream; shared
Integration catalog/Swagger/docs changes must compose without overwriting it.

## Goals

Every supported browser route and embedded workflow has a typed official API,
current native actor/object/field authorization, narrow token grants, shared domain
actions, exact result read-back, tests, Swagger and verified employee handbook.
Cover global/client/site connections, defaults, credential rotation, diagnostics,
all mapping branches, folder navigation/matching and explicit Talk/sync actions.
Preserve all existing calendar contracts, claims, privacy, activation and conflict
semantics. Coverage evidence must connect runtime routes, schemas, rights and tests.

## Non-Goals

No generic proxy to upstream Nextcloud, new SSO/provisioning model, arbitrary
file upload/delete/shares, or exposing every unused method in NextcloudTalkClient.
Issue #205 destination redesign and #115/#122 bot/Communication Hub work remain
separate; this parity effort must not claim they are complete. No production
configuration or pilot expansion, historical cleanup or role grants. Svein Tore
explicitly authorized the scoped candidate commit/push and tested Main promotion.
Production deployment remains outside this authorization.

## Current Behavior

Browser administration uses AdminAccess and EnforceTechRoutePermission. Inspect
the effective full route names: some permission entries are exact prefixes rather
than wildcards, so do not infer native rights from a button name. Connection modes
support global read_only/sync/managed; customer/site connections are forced read_only.
User mapping supports global identities/contact links and scoped customer
skip/map_existing/import with an explicit import site. Group mapping stores role,
client context and sync direction. Folder selection/mapping is local configuration.

Detail pages expose form lookups, live folder navigation, mapping collections and
recent general sync logs. Full Sync now reads capabilities/users/groups/calendars/
files and can import/update contacts and synchronize calendars; calendar-only API
sync is a different operation. Auto match writes local client-folder mappings with
deterministic matching and optional configured AI. Bot tests send a remote message.

Existing calendar APIs cover connections, mappings, attempts, conflicts, preview,
activation, health, queued sync and reconciliation. Calendar owns event mutation;
Nextcloud owns provider operations. Existing leases, current ownership, conditional
ETag/read-back and reviewed activation remain authoritative. Inbound HMAC is separate.
Existing pilot human review remains In Review; new tests cannot satisfy that review.

## Proposed Change

1. Add explicit typed versioned Sanctum routes in Nextcloud/routes.php, preserving
   the api.php loader, existing 14 calendar operations and public signed webhook.
   Use module controllers/requests/resources/actions; no redirect-controller wrappers.
2. Add bounded general connection list/detail/context, scoped choices/action
   availability, mapping collection/exact item reads, sanitized general attempts/logs
   and configured folder metadata navigation. Persisted status reads never start sync.
3. Add connection create/update/delete and defaults through shared validation/actions.
   PATCH preserves omitted fields. Validate client/site ownership and fixed fields.
   Credentials/bot secrets stay write-only/encrypted; return configured markers only.
   Require an additional credentials.write token grant for supplied secrets; exclude
   secrets from responses, errors, logs, receipts and examples. Scope/principal/secret
   changes invalidate stale previews and affected automatic activation consent.
4. Extract web controller mapping/folder/Talk behavior into shared domain actions;
   expose all skip/map/import and group direction/role branches and their deletes.
   Preserve Contact canonical/legacy bridge identity, explicit import site and tenant
   isolation. Use current active native internal actor, effective browser-route,
   record/field rights and cumulative token grants, including replay and execution.
5. Expose health and full manual-sync with preview/status and precise selected
   identity/calendar effects, separate from calendar-only sync. Reuse source-owned
   mutations, durable calendar leases and execution-time authority/configuration
   checks. Queued/accepted is not completed; return success/partial/failed/unknown
   evidence and exact connection-bound general attempt read-back.
6. Expose root/documents selection, folder mapping and live bounded folder navigation.
   Auto match uses the existing deterministic/AI algorithm and configured AI policy.
   Preview/apply binds exact reviewed client/path pairs to one connection, revision
   and current unmapped state. Do not rerun AI during apply or create remote folders.
   Invalid/duplicate/ambiguous suggestions and AI failures remain explicit.
7. Expose current Talk configuration and explicit bot test for the exact configured
   connection/conversation. New provider mutations require an additional narrow
   providers.execute grant and default-off API execution switch. Existing calendar
   execution grants/consent stay independent; a general flag cannot enable them.
   Persist intent before remote I/O and never blindly retry ambiguous sends/writes.
   Preserve signed inbound webhook and Notification policy/commands.
8. Add narrow ability entries only with implemented routes/tests. Cover connection
   reads/writes/credentials, mappings, folder reads/writes, full sync/health, Talk and
   own operation lookup. Keep current actor rights independent from token visibility.
9. Add actor/token/operation/normalized-payload-bound durable command receipts,
   encrypted safe results and opaque stale preconditions for new mutations. Local
   journal/effects/receipt are atomic; remote intent/result are separate phases.
   Credential fingerprints use keyed digests; do not retain submitted credentials
   or reversible request bodies. Current actor/token/object rights govern replay.
10. Publish static OpenAPI request/success/error schemas, required scopes and examples.
    Assert exact runtime/documented operation sets, preserve legacy contracts and
    validate each successful HTTP result against its typed schema. Update the matrix
    with test IDs and exact local/provider read-back before any full-coverage claim.

## Impact Analysis

Nextcloud retains connection/configuration/mapping/provider ownership. Calendar keeps
visibility/events/claims; Contact/Client keep canonical contacts and bridge IDs.
Integration owns ability discovery, AI and Swagger; Notification owns global delivery.
UserManagement remains authoritative for active users/roles/permissions. No UI redesign
or translation change; shared-file edits must preserve concurrent work. Risks include
secret disclosure, wrong-client import, role escalation, stale activation, duplicate
messages/calendar events and incorrect AI mappings. Allowlists, shared actions,
preconditions, receipts, current authority and exact target gates address these.
TLS validation is mandatory; no caller-controlled arbitrary upstream proxy is introduced.

## Data And Migration Plan

Plan one additive Nextcloud API command schema following current domain receipts:
unique actor/token replay identity, operation, payload digest, target identifiers,
state/version and encrypted safe result. Final filename/schema must be checked against
concurrent migrations after approval. No contact/event backfill or permission grants.
Run only the exact approved Dev migration; verify native MySQL uniqueness/locking,
rollback and encrypted read-back. Reuse existing Calendar command/lease authority.

Later deployment requires the exact additive migration, generated Swagger and normal
cache clearing. Restart affected workers only if job code changes. No new cron/global
scheduler or frontend build anticipated. Rollback disables new entry points and keeps
receipts, APP_KEY, existing mappings/claims/history; never run broad pending migrations
in this shared checkout. Deployment and live target activation require separate scope.

## Testing Plan

Sequential authoritative Dev tests use fake provider/AI HTTP and prevent stray calls.
Cover active/native/token rights/revocation, tenant isolation, all connection/mapping
branches, contact bridge/import site, safe PATCH/defaults/secret rotation/redaction,
bounded folder reads, stale/replayed auto-match proposals, AI errors, full sync side
effects/partial failures, real OCS-shaped bot outcomes/unknown sends, HMAC and Notification
regressions, legacy Calendar ownership/claims/activation/ETag/conflict/recurrence,
receipt replay/concurrency/encryption/audit rollback and typed Swagger HTTP success/errors.
Run affected Nextcloud feature/unit, Calendar, Client/Contact, Notification/Talk,
Integration catalog/contract/AI suites plus native MySQL and trusted HTTPS checks.
Known test failures must be resolved or explicitly reported. Controlled live provider
writes need separate exact-target authorization; no real customer mutations in tests.

## Documentation Plan

Update module README, affected Nextcloud Knowledge sources, full API guide and related
API Management/Calendar/Notification guidance where behavior changes. Publish affected
verified source content through supported BookStack integration; read actual body and
employee access scope. pending_push/queue success is not publication; employee OpenWebUI
retrieval is separate. Track blockers/owner in TODO. Keep Dev/Main/production/provider/
human-review states separate. Public website handoff only follows verified completion
and stays do not publish until production availability is verified. Reconcile matrix,
RFC/ADR/slices/TODO/human-review with evidence in the same session.

## Open Questions

No product decision remains for current-browser parity. Native AdminAccess now allows
active delegated internal actors with roles/permissions; follow its current authority
rather than imposing an additional hard-coded Admin/Superuser role.

## Approval

Svein Tore explicitly approved this RFC and tested Main promotion on 2026-10-27:
Godkjenner og merge til main. This authorizes implementation on authoritative Dev,
required exact additive Dev migration/tests, scoped candidate commit/push and Main
merge after verification. No production deployment, provider activation or extra
human scenario execution is inferred. HR-2026-10-27-NEXTCLOUD-API remains Pending;
Main promotion is authorized with its scenarios pending. Production release/activation
still requires named review or a separately explicit scoped decision.
