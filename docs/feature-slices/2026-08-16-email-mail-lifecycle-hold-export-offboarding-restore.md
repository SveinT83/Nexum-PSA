# Feature Slice: Email Lifecycle, Legal Hold, DSAR, Offboarding And Restore

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-16-email-lifecycle-hold-export-offboarding-restore.md`
Owners: Email / Integration / UserManagement / Ticket / Storage
Human Review: `HR-2026-08-16-023`

## Goal

Provide explicit, bounded and auditable legal hold, privacy/DSAR export, user/account offboarding and
backup-restore quarantine for every Email-owned content and derived state. Keep Ticket evidence,
provider credentials, user sessions and backups under their owning domains. Never replay uncertain
sends/provider mutations, expose shared-mailbox content by grant history, or claim physical backup/
provider erasure without evidence.

## Dependencies And Lifecycle Inventory

Orders 5-22 must be stable. Before implementation, build a checked lifecycle registry covering:

- source messages/canonical projections/placements/raw/attachments and provider reconciliation;
- drafts/draft attachments, outbound submissions/EmailLog/Sent and remote operations;
- personal unread/opened state, grants/delegation/break-glass/presence;
- rules/attempts, Smart Inbox/AI artifacts, search documents/extractions/caches;
- Ticket relationships versus separately captured Ticket evidence;
- Notifications, Signals and cross-domain references;
- private files, unreferenced/orphan inventory, exports and backups; and
- Integration connection/credential/binding plus queue/scheduler/listener state.

Every registered class declares owner, hold behavior, export class, revoke/offboard behavior,
purge dependency and restore quarantine behavior. An unknown class/future schema version fails
preview/apply closed rather than being silently omitted.

## Additive Data Model

Reserve migrations after order 22, currently `2026_08_16_155000` through `159000`.

### Holds

Add `email_legal_holds`, targets and append-only events:

- opaque UUID, authority/legal basis, bounded reason, creator/approver, status `draft`, `previewed`,
  `active`, `review_due`, `released`, `expired`, `cancelled` or `failed`;
- effective/review/release dates, policy/schema version, exact account/conversation/message/date/content
  classes and explicit future-arrival mode;
- frozen preview fingerprint/count/bytes, activation version and timestamps;
- target source type/ID/fingerprint, retained content classes/files/derived artifacts, status and
  release outcome. No ordinary mail content or private paths in list/audit rows.

### Privacy exports

Add export runs/items/artifacts/download grants:

- requester, data subject, legal/privacy actor, scope classes, basis/reason, exact account/message/
  date/user filters, preview fingerprint/count/bytes, approval, expiry and status;
- items with source owner/type/ID, inclusion/redaction/denial/missing status and safe reason;
- encrypted artifact identity, manifest/content hash, bytes, encryption/KDF/KEK version, wrapped data
  key, expiry/deletion state and no plaintext key/token;
- hashed single-use download token, actor/session binding, attempts/use/expiry/revocation.

### Offboarding and restore

Add `email_offboarding_runs/items` and `email_restore_quarantine_runs/items`:

- exact user/account/provider-binding/session/job/content scopes, requested disposition, actor,
  approvals, preview fingerprint, status/progress, lease/cancellation and safe errors;
- per-domain action, before/after version, blocker/ambiguity, owner action reference, result;
- restore incident/backup identifiers as safe hashes, restored-at boundary, schema/code version,
  quarantined runtime classes and explicit account resume/reconciliation evidence.

All ledgers are append-preserving/idempotent with strict down guards. Migrations do not hold/export/
revoke/delete/pause/resume/provider-call or alter lifecycle state.

## Legal Hold Workflow

Require dedicated `email.legal_hold_manage` and a separate approver for activation/release unless an
explicit documented emergency policy applies. Preview is metadata-first and shows exact scope,
estimated local bytes, missing/provider-only evidence, derived classes, Ticket-owned exclusions,
future-arrival choice, retention conflicts and storage readiness.

Apply locks and reauthorizes the hold/scope, verifies a 15-minute fingerprint and creates exact
targets without provider calls/read-state changes. Held Email source files/rows and selected search/
AI/rule evidence are protected by one shared lifecycle decision used by every purge/cleanup. A
future-arrival monitor uses exact account/conversation/policy versions and captures target references
after durable ingest; it does not create Ticket evidence or import provider history.

Provider deletion remains visible: a held local source can outlive its provider placement with
provenance. A hold cannot claim missing/uncached bytes were preserved. Ticket receives a separately
authorized child-hold request for captured evidence; failure/denial is shown, not bypassed.

Release requires preview, current authority, reason and optional second approval. It disables future
targeting and marks targets released; normal retention resumes asynchronously after other holds/
policies are checked. Release never immediately deletes or rewrites audit/history.

## DSAR And Privacy Export

Scope classes are explicit:

- `personal_mailbox_content`: Email accounts currently/authoritatively owned by the subject;
- `actor_derived_state`: unread/opened, drafts, personal rules/preferences, suggestions/feedback and
  access/delegation events about the subject;
- `participant_correspondence`: shared-mail messages where the subject is a normalized participant,
  requiring an explicit legal basis and third-party/privacy review;
- `shared_access_metadata`: grants/delegations/use metadata without shared content by default; and
- separately requested Ticket/other-domain child exports under those domains.

Having or once having a shared grant never exports the mailbox. BCC/raw/security/other-user state is
excluded unless exact lawful scope and current authority explicitly include it. Preview reports
inclusions/exclusions/missing/redactions without exposing content to a configuration-only operator.

Apply freezes items and generates a deterministic manifest plus safe JSON/text/HTML, available raw
EML and clean attachments with checksums/provenance. Missing, truncated, unscanned, provider-only or
non-authoritative source is labelled; no provider fetch or fabricated MIME occurs. Attachment bytes
require order-21 clean evidence or a separately approved legal incident export unavailable to the
ordinary requester path.

Stream-build and libsodium-encrypt within byte/time/file caps; never write plaintext to public/temp.
Store wrapped keys only. Download requires current export permission plus the intended recipient,
short single-use token and audit-before-stream; use forced attachment, no-store/nosniff and rate
limits. Expiry revokes/deletes wrapped key then artifact idempotently. Logs/notifications never carry
token/content/address/filename/private path.

## User And Account Offboarding

Provide previewed disposition plans with required owners/approvers and exact blockers. First commit
the immediate safety boundary through owning domains:

- UserManagement disables/revokes sessions/API tokens and active actor identity;
- Email removes/ends grants, delegation/break-glass, presence and personal UI access;
- Integration pauses account provider runtime and stops new poll/IDLE/SMTP/APPEND operations;
- queue actions cancel only pre-provider claims and quarantine accepted/unresolved/ambiguous work;
- live invalidation revokes channels and current pages; and
- search/result caches and AI access are invalidated.

Then apply an explicit content disposition:

- shared/system account: retain for remaining authorized owners/managers; subject state/drafts/rules
  transfer only when individually previewed and authorized;
- personal account: transfer owner, convert to shared with explicit grants, retain disconnected under
  policy/hold, or schedule Email-local purge after retention/hold review;
- credentials: retain/revoke/replace only through Integration, never copied to a new user; and
- Ticket captured evidence: unchanged under Ticket ownership and never grants mailbox access.

Drafts, signatures, personal rules, suggestions, unread/opened state and queued actions default to no
transfer. A selected transfer records provenance and recalculates access epochs without marking old
mail unread/read. Unreferenced files are never deleted merely because their user/account was
offboarded; inventory, hold, outbound/Ticket/backup provenance and retention must prove eligibility.

## Restore Quarantine And Resume

Expose an operator command/action that records a restore incident before normal Email workers run.
It freezes restored schema/code/DB/file/provider-binding/high-water evidence, then:

- pauses every Email account/provider binding and disables poll/IDLE/reconciliation/auto-reply;
- quarantines pending/reserved/sending/unresolved SMTP, APPEND, remote operations, cleanup, rule
  external actions and actor-bound jobs without marking them failed/cancelled as provider facts;
- invalidates sessions/tokens/presence/live channels through owning domains;
- marks search/AI/derived projections stale and verifies private files/ACL/checksums;
- blocks provider writes and outbound controls until exact account resume; and
- requires current credential verification plus historical rebaseline/provider reconciliation
  preview against live provider state.

Each account resumes separately after blockers are resolved, accepted/unresolved operations are
reconciled/superseded with evidence, folders/UIDVALIDITY are current, new live-start boundary is
approved, storage/index/queues are healthy and current actors/permissions are revalidated. No old
queue payload is released wholesale. Restore cannot reactivate revoked credentials or send a
pre-backup draft/submission.

## Authorization, Bounds And Privacy

- Dedicated permissions: `email.legal_hold_manage`, `email.privacy_export_manage`,
  `email.offboarding_manage`, `email.restore_manage`; conservative role defaults and optional
  two-person approval for hold/export/destructive disposition/resume.
- Domain child actions require their own permissions and Work Context. Break-glass is content-use
  only and cannot manage lifecycle/export/offboarding/restore.
- Opaque IDs, non-enumerating routes and audit-before-content/download. Configuration-only views show
  safe counts/statuses, not account/content/participant names outside normal authority.
- Preview default 100/hard 500 items; larger scopes use durable paginated attestations/items, never
  truncation. Claims process at most 25 items/about ten seconds; export has installation-lowered
  byte/file/time caps and cap-plus-one denial.
- Row/file compare-and-set, tokenized leases and current schema/policy registry make every run
  resumable/idempotent. Cancellation stops new work and never pretends to undo exported/downloaded,
  held/released or provider-side outcomes.

## Out Of Scope

- Permanent provider deletion (order 24), external eDiscovery/archive provider, legal advice,
  immediate backup erasure, automatic personal-mail transfer, silently reading provider history, or
  purging Ticket-owned evidence from Email.

## Tests

- Holds by message/conversation/account/date/content class, future arrivals, overlapping holds,
  release/review/expiry, provider deletion, purge protection and separate Ticket child hold.
- DSAR scope classes, personal/shared former grant/participant/BCC/third-party/redaction, missing raw/
  attachment states, deterministic manifest and no provider fetch/fabrication.
- Secretstream encryption, key version/rotation/missing key, no plaintext temp, token/session/single-use/
  expiry/rate/audit, interrupted stream and artifact/key cleanup.
- Shared/personal user/account offboarding dispositions, transfer/conversion/retention/purge preview,
  access epochs, drafts/rules/signatures/state default no-transfer and Ticket evidence isolation.
- Immediate session/token/grant/presence/live/search/AI revocation; pre-provider cancel versus accepted/
  unresolved quarantine; no credential transfer/provider call.
- Restore drill with stale jobs, accepted/unresolved sends, Draft APPEND, remote operations, old
  UIDVALIDITY, revoked credentials/users, private-file mismatch, index rebuild and per-account resume.
- Concurrency, stale fingerprint, approvals, caps/pagination/resume/cancel, audit failure, disabled
  actor and non-enumerating web/API/CLI.
- Migration/down guards, registry unknown-class fail-close, workers/scheduler/health, sanitized logs,
  backup-expiry wording and affected Email/Integration/User/Ticket/Storage/Portal tests.

## Documentation And Operations

Update Email/Integration/User/Ticket/Storage Knowledge, privacy/legal/offboarding/restore/incident and
key runbooks, API/OpenAPI, TODO, completion index and `docs/human-review.md`. Provision versioned
export KEK and private artifact storage, deploy migrations with `umask 0002`, clear caches/rebuild
views and restart only after restore-mode checks. No migration activates a hold, exports, offboards,
purges or resumes.

`HR-2026-08-16-023` remains Pending until named reviewers perform hold/release, encrypted DSAR,
personal/shared offboarding and isolated backup-restore drills, verify domain boundaries, ambiguous
work quarantine, key/token/storage/worker behavior, no provider replay and honest backup evidence.

## Done Criteria

- [ ] One lifecycle registry and exact durable runs/items cover Email holds, exports, offboarding and
  restore without crossing Ticket/Integration/User/backup ownership.
- [ ] Exports are scoped, itemized, envelope-encrypted, single-use and honest about missing/provider/
  backup evidence; shared access history never grants content export.
- [ ] Offboarding/restore immediately revoke/pause and quarantine uncertain external work; resume is
  per-account, reverified and cannot replay pre-backup sends/mutations.
- [ ] Tests, migrations, permissions, CLI/UI/API, keys/runbooks and `HR-2026-08-16-023` are complete
  while human review remains Pending.
