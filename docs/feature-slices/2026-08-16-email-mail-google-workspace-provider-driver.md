# Feature Slice: Google Workspace Gmail API Provider Driver

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-16-email-google-gmail-api-provider-driver.md`
Owners: Integration / Email / Security Operations
Human Review: `HR-2026-08-16-028`

## Goal

Implement Gmail as a first-class OAuth/API provider with stable message identity, exact many-to-many
label state, resumable mailbox history, authenticated Pub/Sub latency hints, drafts/send/Sent and all
supported normalized Mail actions. Provide a single-writer, parity-checked IMAP migration and guarded
rollback without broad default scopes.

## Dependencies

Orders 5-12 and 21 must be stable. The driver consumes secure credential/binding lifecycle, canonical
source/placements, provider reconciliation, private invalidation, shared drafts/outbound and clean
attachments. Permanent delete and Calendar are separate capability slices and receive no implicit
scope here.

## Additive Data Model

Reserve migrations `2026_08_16_168000` and `169000`.

Add Integration/Email Gmail state:

- connection mode, Google customer/domain/subject/client/service-account identity hashes, consent/
  scope version, restricted-scope readiness and exact delegated-subject policy evidence;
- account current history ID/generation and stable start/end/rebaseline evidence;
- message provider ID/thread ID and exact label projection; label provider ID/type/name/color/role,
  hierarchy presentation and lifecycle state;
- draft container ID/current message ID/version; watch/project/topic/subscription identity hashes,
  OIDC audience, returned history/expiry, renewal/status and safe events;
- bounded IMAP-to-Gmail migration runs/items with source UID namespace, Gmail identity/labels, parity,
  ambiguity, activation and rollback evidence.

Tokens, client/service-account/Pub/Sub secrets, mailbox addresses, raw history/page URLs and message
content remain encrypted/private outside Email rows/jobs/audit. All provider jobs carry durable IDs
and expected positive binding/config/scope versions. Down refuses while accounts/history/labels/
drafts/watches/migration evidence depend on schema. Migrations perform no Google call.

## Integration Lifecycle

Integration UI offers delegated OAuth and separately permissioned Workspace domain-wide delegation.
OAuth uses state/PKCE/nonce/offline access and binds the exact subject/customer/mailbox. Domain-wide
mode requires typed admin preview, exact service-account/customer/subject cohort and proof that no
arbitrary subject can be selected by ordinary users.

Request the minimum current scopes and display safe capability names/verification status, never raw
scope/token/client/service-account/mailbox identity. `gmail.modify` is the baseline full-client scope
only after restricted-scope readiness; broader full-mail scope is rejected in this slice. Scope or
identity drift creates a new binding/rebaseline. Revoke stops watches/queued hints and provider I/O
under lifecycle/account locks.

## Driver And History Reconciliation

Implement bounded provider-neutral capability/folder-label/metadata/MIME/attachment/draft/send/
operation contracts. API calls use fixed Google hosts, exact `users/me` or locked delegated subject,
page/byte/deadline/quota bounds and current binding under shared account lock.

Initial sync freezes start history ID, walks bounded message IDs and exact metadata/raw as needed,
then applies history through a stable end cursor. Incremental jobs apply every paginated history
record and exact label/message state before advancing the cursor. Unknown/malformed events, cursor
404, scope/binding drift or start/end instability fail closed into explicit rebaseline.

Order-7 projectors import exact new messages with provider mutation disabled where required, reconcile
labels/read/star/trash/spam and deletion evidence, preserve personal unread, and surface conflicts with
pending local operations. Negative absence/deletion is accepted only after a complete stable history/
inventory cycle, never from a missing notification alone.

## Pub/Sub Watch

Provisioning is explicit and reviewed: expected Google Cloud project/topic/subscription, Gmail
publisher permission and HTTPS push OIDC service identity/audience. The route validates signature,
issuer/audience/project/topic/subscription, request bounds and replay/coalescing before queueing only
account/current-history hints. No Mail content is accepted or returned.

Renew watch daily and before provider expiry. Scheduled history catch-up runs independently and health
shows last watch/renew/push/history/complete cycle safely. Lost, duplicate, reordered, late, forged or
revoked push cannot advance state. Pub/Sub outage degrades to polling without changing correctness.

## Labels, Drafts, Send And Operations

- Maintain exact message-label membership and explicit system-label roles. User label rename/delete
  updates presentation/state without changing message identity; label name collisions do not merge.
- Message read/star/trash/spam/label operations submit exact add/remove label IDs. Thread-wide methods
  are used only by an explicitly previewed thread-wide action, never as a message shortcut.
- Draft create/update tracks stable draft container plus replaceable message ID and current lock/
  provider version. Conflict/stale remote draft remains visible rather than overwritten.
- Send reuses the unified outbound reservation. Reconcile the new returned/observed Sent message and
  delete/retire draft projection once; response loss never causes second send.
- Exact MIME/attachment reads are bounded and pass quarantine/security before ordinary consumers.

## Migration And Rollback

Preview resolves the same mailbox through old IMAP and Gmail API, proves authorization/binding/scopes,
full label/folder/message/draft/Sent parity and whole-account durable attestation, and reports every
ambiguous UID/Gmail identity. Apply pauses/drains old work, activates one binding generation and maps
existing canonical messages/placements to Gmail identity/labels without provider writes or personal-
unread/Ticket/rule effects.

Rollback requires current old binding, fresh reverse preview and no Gmail-only provider write/send/
history ambiguity. Otherwise reconcile/rebaseline; never dual write or replay queued old jobs. The
old IMAP projection/evidence is preserved according to lifecycle policy, not treated as Gmail truth.

## Permissions And Privacy

Dedicated Integration permissions govern OAuth/client/domain delegation/Pub/Sub. Email account and
mailbox-sync permissions govern cutover. Mail UI/API exposes only normalized capabilities and exact
authorized messages/labels; provider IDs, subject/customer/mailbox existence, scopes and push health
remain non-enumerating. Configuration-only operators see safe health, not content/counts.

## Tests

- OAuth state/PKCE/nonce/offline, exact subject/customer, scope/restricted-verification, delegated vs
  domain-wide cohort, arbitrary-subject denial, rotation/reconsent/revoke and telemetry/session safety.
- Message/thread stability, label many-to-many/system/user rename/delete/collision, history pagination/
  ordering/duplicate/gap/404/rebaseline and complete negative evidence; personal unread isolation.
- Pub/Sub provisioning/OIDC/audience/project/topic/subscription/replay/request cap, daily renewal/
  expiry/outage, lost/reordered/forged hints and scheduled catch-up correctness.
- Exact MIME/attachments, stable draft container/replaceable message/update conflict/send/new Sent,
  response loss no replay, exact message vs explicit thread label operations, trash/untrash/spam/star.
- Quota/429/Retry-After/deadline/byte/page bounds, revocation/binding drift, no broad full-mail scope or
  permanent-delete capability.
- IMAP migration whole parity/ambiguity/apply/rollback/single writer and unresolved operation/draft/
  outbound/reconciliation blockers; canonical/Ticket/rule/Signal/notification safety.
- Personal/shared/system authorization, non-enumerating UI/API, migrations/down guards, workers/
  scheduler/health and affected Email/Integration tests.

## Documentation And Operations

Update Email/Integration/UserManagement Knowledge, Google consent/restricted-scope/domain-delegation/
Pub/Sub/migration/incident runbooks, API/OpenAPI, TODO, completion index and `docs/human-review.md`.
Configure a Dev OAuth client and expendable mailbox; optional domain delegation and Pub/Sub need their
own exact test cohort/project/topic/subscription. Deploy additive schema/permissions with `umask 0002`,
clear caches, rebuild group-writable views and restart workers. No deploy creates consent/watch/cutover.

`HR-2026-08-16-028` remains Pending until a named reviewer validates real OAuth and optional domain
delegation, scopes, labels/history/404, push renewal/outage, drafts/send/Sent, every mutation,
migration/rollback, privacy and safe health. Activation is explicit after review.

## Done Criteria

- [ ] Gmail identity/history/labels are modeled natively without fabricated UID/folder truth and
  scheduled reconciliation remains correct through push loss/cursor expiry.
- [ ] OAuth/domain delegation/Pub/Sub are exact, least-privilege, revocable and non-disclosing, with
  no broad permanent-delete scope by default.
- [ ] Draft/send/Sent and label operations reuse provider-neutral ledgers and remain idempotent/
  conflict-visible without personal-unread coupling.
- [ ] Tests, migrations, UI/API, external-service operations, docs/runbooks and
  `HR-2026-08-16-028` are complete while named human review/activation remain Pending.
