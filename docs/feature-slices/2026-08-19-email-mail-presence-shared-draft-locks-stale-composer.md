# Feature Slice: Email Presence, Shared Draft Locks, and Stale-Composer Protection

Status: Safety Rework Implemented / Runtime And UI Gated / Human Review Pending
Date: 2026-08-19
Reworked: 2026-08-24
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 9)
Approved contract: `2026-08-16-email-mail-presence-shared-draft-locks-stale-composer.md`
Review ID: `HR-2026-08-16-009`

## Outcome

The beta-critical backend/API safety boundary is implemented without activating collaboration.
Historical migration `2026_08_19_140000_create_email_mail_draft_locks_table.php` remains an inert
marker and is unchanged. New additive forward migration
`2026_08_24_125000_add_email_shared_draft_coordination.php` adds explicit shared-draft scope,
source-context evidence, one durable lock row per draft, monotonic fencing/content versions and
metadata-only transition events. It was not run during implementation and later ran in Dev batch
126 after Order 11; the existing draft remains private and the shared lock/event ledgers are empty.

`EMAIL_LIVE_ENABLED`, `EMAIL_MAIL_COLLABORATION_ENABLED` and
`EMAIL_MAIL_COLLABORATION_UI_ENABLED` all default false. The collaboration gate checks the two
server flags before runtime/schema readiness, so a disabled or pre-migration deployment never asks
the optional `125000` schema. Even with both flags enabled, the API remains unavailable until Order
8's private live runtime reports ready. No Reverb listener, Echo subscription, presence whisper,
Livewire collaboration control, queue, scheduler, provider call or shared configuration was
activated by this rework.

## Implemented Safety Boundary

### Ephemeral presence

- Reading and typing heartbeats use the configured cache store, normally Redis; no SQL presence row
  or durable heartbeat event is created.
- Reading expires after 45 seconds, typing after 25 seconds, and a tab is accepted no faster than
  every 10 seconds. Exact account, conversation and source placement plus a keyed hash of the opaque
  tab token form the cache scope.
- Multi-tab state is aggregated per actor/activity. Names are resolved only from a fresh,
  permission-filtered server snapshot.
- Ordinary grant-sourced View is required for reading; ordinary View plus Send is required for
  typing. Personal owner/delegation, break-glass, inactive/system users, revoked grants, mismatched
  sources and inaccessible accounts fail closed.
- Cache failure makes presence absent. Best-effort leave never replaces TTL expiry.

### Explicit shared drafts and leases

- Only the private creator may explicitly share an active Reply, Reply All or Forward draft for an
  ordinary shared/system mailbox source. Exact repeated share delivery is idempotent; a different
  key/version cannot recover or widen the share.
- Shared read requires current ordinary View. Share, lease, mutation, attachment, rebase, discard
  and send require current ordinary View plus Send for the exact account/conversation/source.
- The one-row lock uses an opaque token hash, 60-second lease, 20-second renewal floor, monotonic
  fencing token, exact draft generation, exact source placement and content version. Expired
  takeover is explicit and increments the fence. A prior tab receives HTTP `423 Locked` for every
  mutation/send boundary.
- Attachments remain in private Email storage and stay bound to the exact draft generation. File
  deletion occurs only after the lifecycle/evidence transaction commits; a rollback keeps both DB
  metadata and files.
- Explicit transition events contain only opaque IDs, actor, safe type/reason, fence/content
  versions and time. They contain no recipients, subject, body, attachment metadata/path/checksum,
  provider response or exception text.

### Stale source, rebase and send

- `EmailComposerSourceContext` binds account/provider version, conversation/source generation,
  active placement identity, threading identity and normalized audience/subject hashes without
  placing message content in coordination evidence.
- A source/provider/audience mismatch blocks save, attachment mutation, preview and send with a
  safe `409` stale response and no provider call. Rebase preview/confirmation recalculates the
  current source, recipients, subject and thread while preserving only the authored body and
  eligible draft attachments.
- Stale drafts may still be explicitly discarded under the exact current lease/fence because
  discard is non-provider cleanup.
- Shared send uses Order 11's same immutable `email_outbound_submissions` ledger. The service
  reauthorizes ordinary Send, lease expiry/token hash, fence, content version and source fingerprint
  under row locks immediately before the durable provider-write marker. Accepted or unresolved
  outcomes cannot be blindly resent.

## API Contract

The versioned Email module owns default-off endpoints for presence snapshot/heartbeat/leave,
private-to-shared conversion, shared read, lease acquire/renew/release, fenced update/attachments,
rebase preview/confirm, send preview/send and discard. Existing token abilities
`email.drafts.read`, `email.drafts.write` and `email.send` are only request ceilings; current
ordinary mailbox authority is always rechecked. Out-of-scope IDs return Not Found, stale/source or
submission conflicts return `409`, and invalid/held/expired leases return `423` with safe current
state but never a lease token/hash or internal generation.

## Verification

Isolated tests use SQLite `:memory:`, array cache, a unique `APP_CONFIG_CACHE`, cache maintenance
mode and `HOME=/tmp`. The focused collaboration suite plus inert-marker test pass 8 tests / 109
assertions. Adjacent Order 11 composer/submission/Sent regression passes 22 / 228. Coverage includes
config-first disabled behavior, private-live readiness, presence permissions/multi-tab filtering,
no SQL presence, exact cross-user isolation, idempotent share, one editor, renewal/takeover fencing,
stale-token `423`, stale/rebase preservation, one outbound submission/provider call, stale discard,
and attachment rollback safety.

The opt-in actual MariaDB contract passes 1 test / 44 assertions against a random disposable schema
on an isolated `/tmp` server. It proves `125000` up, legacy private-draft conversation/sync/content
backfill, named indexes and foreign keys, default-off collaboration, empty down, and refusal for each
non-empty shared-draft, lock and event evidence case. The `finally` cleanup drops the random schema;
an independent `information_schema.schemata` prefix readback returned zero before the temporary
server/datadir was stopped and removed.

## Remaining Activation Work

- The automated disposable MariaDB up/down/index/FK/backfill contract is green. Dev migration
  `125000` is batch 126, but migration deployment does not authorize runtime activation or complete
  `HR-2026-08-16-009`.
- Order 8 remains rework-needed/runtime-disabled. Complete and review its bounded private transport,
  authorization generations, fallback, retention and supervised operations before enabling Order 9.
- Build the accessible Livewire/Alpine presence, lock, stale/rebase and mobile controls only after
  the private transport is stable. Per-user whispers are not a valid coworker transport.
- Perform two-user/two-tab browser, Redis-loss, Reverb-loss, access-revocation, lease-expiry,
  stale-source and one-send human review. Keep every collaboration flag false until named approval.

## Rollback

Operational rollback turns the collaboration/UI flags off first and retains lock/event/source and
outbound evidence. The migration down path refuses when any shared draft, lock or event exists.
Application rollback must therefore precede only an explicitly reviewed empty-schema rollback; it
must never erase evidence to make rollback pass.
