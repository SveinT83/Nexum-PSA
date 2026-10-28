# RFC: Managed Web Push Setup And Runtime Activation

Status: Approved implementation scope; production/installation attestation remains separate
Date: 2026-10-28
Owner: Codex / Svein Tore
Issue: #258
Authorization: direct user instruction to implement the audited issues and merge tested changes
to Main ("Fix alt og merge til main"). Technical defaults below preserve existing installation
identity and introduce no live activation, key rotation or recipient action by this work.

## Full Target Workflow

Admin > Notification Channels > Web Push offers a permission-protected guided readiness/setup
workspace. It distinguishes deployment support, trusted HTTPS, shared service worker, VAPID
identity, approved asynchronous queue/worker evidence and global activation. An administrator
chooses a stable mailto: or HTTPS contact subject and explicitly initializes a disabled setup.
A supported installation generates keys once into an installation-owned encrypted private store;
existing environment-owned stable keys are detected and retained. No private key can be supplied
or returned through normal forms, API, audit, sessions, queue payloads or exports.

After a safe check and worker read-back, activation is offered only if every required gate passes.
The user then registers their own browser/device and separately authorizes a controlled test.
Administrator setup neither registers a device nor enables business-event preferences. Rotation
and rollback are separate strongly confirmed actions, disable delivery first, retain encrypted
history and revoke old registrations through existing audited lifecycle actions. Users must
subscribe again. Disable retains identity/history. Production and recipient tests remain gated
by the named human-review checklist and are not implied by code/Main authorization.

## Baseline And Existing Owners

Current config/webpush.php reads deployment environment values. WebPushReadiness checks the global
switch, nonempty keys and subject and returns only a ready public key. Existing Profile device
controls use /sw.js. Notification jobs target the default queue and recheck user/device/event/
record access. The vendor WebPushChannel constructs its VAPID transport at resolution time, so
reusing a cached transport after a managed identity/disable change is unsafe.

System owns RuntimeHealthQuery/RuntimeHealthScope and approved queue probes under #262. Their
read-only evidence can be reused for the exact configured default queue; this feature will not
change their scope/configuration, start/restart a worker, clear/replay business jobs or enable
a blocked queue. Existing Notification API scopes/command receipts are reused for new operations.
Vault machine-use/Connection/Agent secret leases remain separate; this installation VAPID identity
is not a general user Vault item and cannot become a reveal/use Tool.

## Supported Installation-Owned Secret Mechanism

The first supported profile is a Linux installation with an operator-provisioned root-owned
read-only capability descriptor and a dedicated application-owned private directory outside the
public web root. The descriptor binds the application/installation, exact private-store directory,
managed-secret format and worker runtime contract. It attests that deployment has upgraded/reloaded
the relevant workers. The deployment runbook provides concrete provisioning/validation steps.
Normal Admin requests never create this descriptor, change OS ownership or write .env. The
capability has no recurring renewal requirement by default; an installation owner may explicitly
choose an expiry. Revocation/runtime-contract mismatch and any chosen expiry fail closed. Unsupported
platforms, symlinks, permissive permissions, mismatched installations, absent worker attestation or
unwritable private storage show a concrete integration/deployment requirement and cannot activate.

The private directory is mode 0700; encrypted identity/history and lock artifacts are 0600 and
owned by the application runtime identity. Identity contents are encrypted with the existing
installation APP_KEY, never persisted as plaintext. Protect/backup that key and private directory
as one installation secret boundary. No new external KMS dependency or key export UI is added.
Atomic writes under a private lock carry opaque revisions and exact action/request digests;
read-back validates the saved encrypted artifact. Encrypted historical versions are retained
for rollback; an ordinary disable never deletes identity. Corruption/decryption/permission failure
fails closed without echoing private payloads. Existing environment key material is never copied
into ordinary database storage or silently replaced; a partial/invalid legacy identity blocks
initialization pending operator correction.

The deployment may forbid managed setup or force Web Push off independently. Before an explicit
managed initialization, existing environment behavior is unchanged. Once a supported installation
is explicitly initialized, the installation-owned managed activation state owns the ordinary
global switch; an emergency deployment force-off remains an upper bound. Existing stable
environment credentials may remain the identity source while the protected managed state owns
subject/enablement. Managed rotation cannot silently replace an environment-owned identity.

## Runtime, Readiness And Safe Checks

1. Resolve current effective identity/switch at each registration/readiness and each transport
   send. A module-owned channel adapter constructs a fresh vendor transport at the final send
   boundary; cached channel/worker objects cannot retain a managed activation/old key. Existing
   user/record/audience/consent guards remain in the owning jobs/services.
2. Read-only readiness uses System-owned evidence for the exact asynchronous default queue and
   current runtime scope. Missing, blocked, stale or unavailable evidence is distinct and blocks
   activation. This module does not turn on System probing or infer health from queue length.
3. An explicit check validates trusted HTTPS and the same installation's /sw.js with normal TLS
   validation and no arbitrary caller-supplied URL. A login/HTML/error response is not a worker.
4. A bounded internal canary, only on an operator-approved already-healthy queue, verifies that
   the worker loaded the managed Notification runtime/channel contract and sees the exact
   identity revision. The queue payload contains only opaque references, never key material.
   A durable shared safe result proves worker read-back, not browser delivery. No worker/backlog
   restart is performed. The installation descriptor accounts for the whole worker deployment;
   a single canary is not represented as proof that every process was restarted.
5. Enable requires current native authority, an exact fresh revision, explicit confirmation,
   trusted origin/service worker, a valid stable identity, current owned queue evidence and
   matching fresh worker read-back. Disable remains available when readiness is unhealthy.
6. Rotation/rollback disable first and require a typed confirmation that old registrations must
   be renewed. Retain exact encrypted history and audit opaque identities/counts only. Failed
   cleanup/publication remains disabled and visible; no automatic retry can rotate twice.

HTTP/queue acceptance, an attestation file and provider/browser receipt are separate claims.
Installation operator attestation is explicit and is never fabricated from passing tests or Main.

## Ownership, Permissions And API

Notification owns secret-store adapter, settings actions, safe readiness DTO, worker canary,
transport adapter, wizard, guide and runbook. New controllers/views/routes remain in that module.
A module service provider is registered in bootstrap/providers.php; no domain route enters routes/.
System health is consumed read-only. No System/Vault schema or permission migration is introduced.

Current notification.manage_channels is required for the wizard and every action, with fresh
actor checks and CSRF for browser writes. Mirror native read/setup/check/activation/rotation/
rollback operations through the official Notification API with existing channels read/write/test
abilities, safe typed OpenAPI schemas and durable command receipts. Non-DB secret effects commit
a command claim before the effect; ambiguous outcomes remain unknown and require reconciliation.
No endpoint accepts arbitrary private-key fields, OS paths, executable commands or push message
bodies. Managed API mutations additionally retain a separate default-off installation execution
switch; the harmless read-only/canary check never sends a notification. Idempotency/precondition identities bind actor, exact action, revision and safe payload.

## Registration / Rotation Concurrency

One additive Notification-owned mutex table serializes device registration with the disable-first
rotation/rollback registration cleanup. Supported managed installations use the same subscription
and audit/database connection. The existing shared native registration controller/API path takes
that mutex, rechecks current readiness/actor, and requires the browser subscription's actual
application-server public key to match the current identity before persistence. The browser
includes its own subscription.options.applicationServerKey; an explicit Enable action replaces
an obsolete local subscription rather than silently registering it against another VAPID key.
Legacy registration without a managed identity retains compatibility; once the additive mutex
is installed it also participates in serialization. No private material enters this metadata table.

The file settings revision is the exact write/precondition boundary. A separate immutable identity
version binds key/subject and worker evidence across enable/disable changes. Rotation/rollback
changes the identity version and invalidates the old proof. A global switch change does not pretend
the key changed; activation still needs the current settings revision and a fresh matching worker
identity/origin/queue proof. Generating a new identity while unexplained old registrations exist is
blocked pending operator reconciliation.

## Slices

### WP1 - Installation Store And Effective Runtime

Status: Done on Dev / verified Main. Owner: Codex. Parent: this RFC / #258.
Scope: supported descriptor/private-store validation, encryption/atomic read-back/revisions,
legacy identity detection, default-off managed setup and fresh final transport identity.
Done: positive/negative filesystem/identity/secret-canary tests and legacy Web Push regressions
pass on Dev; no real identity generation or activation on native Dev.

### WP2 - Guided Readiness, Lifecycle And Worker Evidence

Status: Done on Dev / verified Main. Owner: Codex. Parent: this RFC / #258.
Scope: safe trusted-origin/worker checks, read-only System queue evidence, bounded no-message
worker canary, guarded setup/enable/disable/rotation/rollback and audit/read-back.
Done: denied/stale/unsupported/failure/replay/rotation/rollback tests pass, registered-device
consent and unrelated queue scopes preserved. All actual operator/runtime activation stays separate.

### WP3 - Admin, Official API, Documentation And Main

Status: Done on Dev / verified Main / handbook read back. Owner: Codex / Svein Tore. Parent: this RFC / #258.
Scope: Bootstrap wizard, equivalent scoped API/Swagger, source/actual handbook and deployment
runbook, exact latest-Main candidate, relevant regressions and independent merged-tree read-back.
Done: every visible control implements a tested honest action; no private input/output; source
and real handbook read-back/access match; Main merge recorded and remaining installation/device
criteria kept explicit. HR checklist remains Pending until named human verification.

## Risks, Recovery And Human Review

Risks: private-store ownership/shared-volume mismatch, APP_KEY backup/rotation, partial writes,
legacy-key ambiguity, stale worker process/config, capability drift, queue availability, deployment
force-off, device invalidation and filesystem/database cross-resource failures. Disable before
identity replacement, immutable encrypted history, safe unknown outcomes and exact read-back are
required. Never drop private history or reveal a key to resolve an error. No new package is needed;
use the installed Minishlink VAPID generator and existing encrypted Laravel primitives.

HR-2026-10-28-WEBPUSH-SETUP gates installation attestation, production rollout/activation, real
browser/device receipt and whole-worker-cohort review. No production/shared runtime settings are
changed by implementation tests. The Issue's implementation criteria are verified on Dev; the named human-review checklist
retains the separate installation/device and production checks required by the Issue.


## Native Verification Boundary (2026-10-28)

The exact additive mutex migration was applied alone on native Dev after an encrypted 0600
preflight/read-back. A rollback-only native MySQL probe verified registration plus audited removal
under the same mutex; synthetic rows were rolled back and actual identity/activation preserved.
The actual pre-existing environment Web Push switch remains enabled and unmanaged. No native
managed identity, real VAPID generation/rotation, root capability or activation was performed.

A temporary root-owned Linux fixture verified the real metadata CLI and app-UID reader, denial
for non-root callers, encrypted synthetic read-back/replay, restrictive permissions, explicit expiry,
symlink rejection and lost-profile fail-closed behavior. Temporary artifacts were removed and
default /etc/nexum descriptors remained unchanged. This is not a live operator/worker attestation.

System's RuntimeHealth implementation is independent active work under #262 and is absent from
the current Main baseline. The read-only adapter resolves it only when installed; otherwise the
wizard/API returns an explicit integration requirement and blocks activation. Its unmerged provider,
scopes and worker controls are excluded from this Main package. Existing environment-only Web Push
continues through its prior path. No hidden fallback health claim is introduced.


## Completion And Main Read-Back

Status: Done On Dev / Merged To Main / Issue Closed Completed. Owner: Codex / Svein Tore.
PR #370; merge 815d342794f59049626099a9de16cb70864dbe3c; independent tree read-back matches
tested 0647ace051ef2bf6e347dbe300678947592e6651. Completion comment 6460655348 read back.
All 158 Notification tests are covered/passing on Dev in completed batches; exact isolated Main
158 tests / 7295 assertions. Thirty-six owned PHP files pass Pint and syntax checks.
Only the additive mutex migration was applied on native Dev. Temporary Linux metadata/store and
rollback-only native MySQL registration/audit probes passed; actual environment identity/activation
was preserved. No actual root profile, VAPID generation/rotation, worker restart or real push occurred.
BookStack 383/12 and 516/7 exact Main sections/access read back; native Knowledge 64/63 revisions
213/212 match source/access. Native sync remains pending_push; direct BookStack content is verified.
RFC/ADR 2026-10-28-managed-webpush-setup / installation-owned-managed-webpush; WP1-WP3 Done.
Runbook: docs/deployment/managed-webpush-setup-and-recovery.md.
System #262 integration is consumed read-only and is absent from this Main baseline; the wizard
explains that requirement and blocks activation rather than importing another owner's unfinished
provider/scopes. Existing environment-only behavior remains available.
HR-2026-10-28-WEBPUSH-SETUP Pending before production installation/whole-worker attestation,
activation and real-browser proof. Main merge does not claim or authorize those checks.
