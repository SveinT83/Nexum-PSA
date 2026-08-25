# Feature Slice: Email Mail Presence, Shared Draft Locks, And Stale Composer Protection

Status: Backend/API Safety Rework Implemented / Runtime And UI Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `../adr/2026-08-16-email-private-presence-and-shared-draft-coordination.md`
Owner: Svein / Codex
Human Review: `HR-2026-08-16-009`

## Goal

Give ordinary shared-mailbox members private, expiring reading/typing coordination and one safe
shared-draft editor at a time. Prevent an open composer from sending with stale conversation,
recipient, threading, provider-binding, or authorization context.

## Dependencies

- Orders 2, 3, 5, 6, 7, and 8 in the Mail completion index must be stable.
- Reuse order 8's private Reverb/Echo transport and current-authority channel fan-out, but keep
  presence outside its durable projection stream/outbox.
- Re-read the final mailbox access resolver, unread/opened actions, canonical source resolver,
  provider binding resolver, reconciliation projectors, composer draft service, unified outbound
  action, Sent reconciliation, Livewire workspace, and revocation/offboarding actions before shared
  edits.

## User-Visible Behavior

- In an authorized shared conversation, Mail may show compact `X is reading` and `Y is writing`
  indicators. They disappear on navigation, disconnect, access loss, or short timeout.
- Reading presence does not lock anything or change `Unread for me`, provider Seen, or opened-by.
- A personal draft stays private unless the author explicitly chooses `Share draft` on a shared or
  system account. Active Send-authorized ordinary members of that exact conversation can then open
  it.
- The first editor holds a renewable shared-draft lease. Another member sees who currently holds it
  and may read the draft but cannot edit, upload/remove attachments, rebase, discard, or send it.
- When a lease expires, another authorized member may explicitly take it over. The prior tab becomes
  read-only and cannot save with its old token.
- New inbound mail, another sent reply, changed source placement, recipient/thread context,
  provider-binding change, or access change marks an open composer stale. Send is blocked until the
  user previews and confirms a rebase or discards the draft.
- Rebase preserves only the user's authored body and eligible draft attachments. The source,
  recipients, subject, threading headers, quoted context, and send preview are recalculated.
- If Redis/Reverb is unavailable, presence indicators simply disappear. Durable draft locking and
  stale-send protection continue to work and never claim a user is still present.
- The responsive Mail surface retains the same actions on mobile; touch views do not create a
  separate draft or presence contract.

## Scope

### Ephemeral presence

Implement an Email-owned presence service and heartbeat endpoint over Redis/Reverb:

- reading TTL 45 seconds, typing TTL 25 seconds, visible-tab heartbeats no faster than every 10
  seconds, and bounded server-time expiry;
- keys scoped to exact account, conversation, source placement where applicable, actor, activity,
  and a hash of an opaque per-tab token;
- one user's presence remains active while at least one currently authorized tab is alive;
- ordinary View is required for reading; ordinary View plus Send is required for typing;
- personal-account non-owner, break-glass-only, inactive, disabled, revoked, expired, or mismatched
  context fails closed;
- navigation/unmount sends best-effort leave, while TTL remains the correctness cleanup;
- private event payload contains schema, opaque IDs, activity, and expiry only;
- collaborator names/avatars are resolved in the permission-filtered server query, never carried as
  trusted broadcast content;
- no SQL heartbeat rows, Telescope/request content, analytics, performance metrics, Notification,
  or durable access-event entries.

### Shared draft and fencing

The reviewed forward implementation reserves
`2026_08_24_125000_add_email_shared_draft_coordination.php`. It follows Order 11's `110000`
draft/submission schema, which later ran in Dev batch 124, and leaves the historical inert Order 9
`140000` marker unchanged.

Add compatible fields to `email_composer_drafts`:

- nullable conversation FK and exact source placement/message generation;
- `collaboration_scope` (`personal` or `shared`), immutable nullable shared scope key, creator, and
  share/revoke timestamps;
- unsigned `content_version`, source-context schema/fingerprint/captured time, stale reason/time,
  and latest confirmed rebase time;
- keep existing `user_id` as creator/legacy personal owner and preserve existing personal unique
  keys; add a separate nullable unique shared-scope constraint rather than weakening personal
  isolation.

Create `email_shared_draft_locks`:

- one row per shared draft, exact account/conversation/source and current holder;
- unsigned monotonically increasing fencing token;
- acquired, renewed, lease-expiry, released, and safe release-reason fields;
- 60-second lease, renewed no faster than every 20 seconds;
- database row lock for acquire/renew/release/save/rebase/discard/send;
- an expired lease can be taken over only by an explicit authorized action that increments the
  token; no stale token can mutate content.

Create `email_shared_draft_events` for bounded metadata-only explicit transitions:

- draft, actor, event type (`shared`, `acquired`, `released`, `expired_takeover`, `rebased`, `stale`,
  `discarded`, `sent`), fencing/content versions, safe reason, occurred time, and idempotency key;
- no recipients, subject, body, attachment name/path/checksum, quoted content, provider response, or
  exception text;
- routine lease renewals and presence heartbeats are not recorded.

Shared draft attachments stay in private Email storage, remain draft-bound, and are read only after
current account/conversation authorization. Sharing never copies them into a public or Ticket-owned
record. Existing provider Draft APPEND reservation/outcome evidence remains immutable and cannot be
reset by a lock transition.

### Source context and stale protection

Add a versioned `EmailComposerSourceContext` service. Its fingerprint includes only normalized,
authoritative identifiers and hashes needed to prove:

- account, conversation, selected source placement/message and current canonical/source mapping;
- latest visible conversation generation and relevant placement sync version;
- sender account/provider binding and exact reply identity;
- normalized To/Cc preview, subject/threading headers, and quoted-source identity;
- ordinary View/Send authorization generation and draft collaboration generation.

It must not make raw/body/attachment content part of an opaque event. Save and send recompute the
fingerprint under current authorization. A mismatch records a safe stale code and performs no send,
provider draft write, attachment mutation, or silent merge.

Explicit rebase presents old versus proposed sender, To, Cc, subject, source, and thread identity
without showing any newly unauthorized content. Confirmation locks and rechecks the same preview
fingerprint, preserves authored content/eligible attachments, updates source context/content version,
and requires a fresh send preview.

The unified outbound action accepts shared draft ID, content version, fencing token, source
fingerprint, and existing send idempotency key. It reauthorizes and atomically reserves send before
marking the draft sent/releasing the lock. A timeout or ambiguous SMTP result continues through the
existing outbound/Sent reconciliation ledger and is never blindly retried.

## Out Of Scope

- Chat, permanent presence history, productivity scoring, employee monitoring, online-status
  reporting, or a presence API for other modules.
- Simultaneous character-level editing, CRDT/OT merge, last-write-wins conflict handling, or automatic
  recipient/thread/quoted-history merge.
- Break-glass collaboration, personal-account delegation changes, provider flag/Seen mutation, or
  provider Draft APPEND redesign.
- Ticket-originated UI. Order 18 may reuse the Email actions after it supplies exact Email source
  identity and current Mail authority.
- Review/approval workflow, assignments/comments, snooze/follow-up, or reporting remain later
  slices. Order 11 compose/send API parity is an implemented dependency reused here.

## Permissions

- Existing ordinary mailbox View permits reading presence and read-only access to a shared draft.
- Existing ordinary View plus Send permits typing presence, share/acquire/renew/release, draft
  mutation, rebase, discard, and send.
- Personal owner behavior remains unchanged. Direct grants on personal accounts and break-glass-only
  access never enter collaboration.
- Every heartbeat, query, transition, and send checks active human user, account, conversation,
  source placement, current ordinary authority, lock token, and content version. A Ticket link grants
  none of these rights.
- No new broad Admin permission is added; mailbox content access remains the controlling boundary.

## Routes And UI

Add Email-module routes for bounded heartbeat/leave, presence snapshot, share/acquire/renew/release,
stale preview/rebase, and shared-draft state. Session routes use `web`, `auth`, `tech`, `2fa.required`,
CSRF, exact throttles, and module permission exemptions only where ordinary mailbox authority is
evaluated inside the action. Order 11 API parity is now reused by the default-off collaboration API;
its token abilities remain ceilings and never replace current ordinary mailbox authority.

Use the existing Livewire/Alpine runtime and order 8 client module; do not import Alpine again.
Presence indicators are compact, accessible text plus status, not color only. Lock and stale banners
identify the next action. Controls are absent when unavailable or unauthorized and never remain as
non-working placeholders.

## Tests

- Reading versus typing permission, ordinary shared/system membership, personal/direct-grant and
  break-glass exclusion, disabled/revoked/expired user/account/delegation denial.
- Redis TTL, multi-tab aggregation, leave/navigation, lost disconnect, server expiry, duplicate and
  out-of-order heartbeat, no SQL heartbeat history, and no content in cache keys/events/logs.
- Private-channel authorization and revocation; Reverb outage makes presence absent while lock/send
  correctness remains.
- One active lease, atomic acquire, renewal bounds, expiry takeover, monotonically increasing fencing,
  stale-token save/upload/remove/rebase/discard/send denial, and redelivery idempotency.
- Personal drafts remain private; explicit sharing and shared-scope uniqueness; shared content and
  attachment access cannot cross account/conversation/source.
- New inbound, external Sent/reconciled reply, move/delete/reappearance, source/canonical drift,
  provider-binding rotation, recipient/thread change, and access change all stale the composer.
- Rebase preview expiry/drift, body/eligible-attachment preservation, recipient/thread recomputation,
  no automatic quoted merge, and current authorization recheck.
- Concurrent reply and send races prove one outbound reservation, no stale send, no blind retry, and
  existing provider Draft/Sent evidence remains intact.
- Mobile/desktop Livewire behavior, accessible indicator/lock/stale controls, no duplicate Alpine,
  order 8 fallback, Email API negative exposure, and affected Ticket/Notification/UserManagement/
  retention/offboarding regressions.

## Documentation And Operations

Update Email README and Knowledge with the difference between opened-by, reading presence, typing
presence, personal unread, provider Seen, personal/shared draft, lease, and stale composer. Update
UserManagement/offboarding docs for immediate presence and lock revocation. Document Redis/Reverb
health and that presence is intentionally unavailable during transport/cache failure.

Deploy additive schema with `umask 0002`, rebuild assets/caches, restart Reverb plus `email-live`,
Email, and default workers, and verify scheduler/expiry health. Do not migrate personal drafts into
shared scope. Operational rollback disables presence first and retains database lock/source evidence;
application rollback precedes any guarded schema rollback.

## Done Criteria

- [ ] Order 8 and all listed dependencies are stable before shared integration starts.
- [x] Ephemeral private presence expires and contains no content or permanent heartbeat history in
  the backend/API safety suite; browser/Reverb operation remains gated by Order 8.
- [x] Shared drafts are explicit, account/conversation scoped, and protected by durable fencing in
  the unactivated backend/API boundary.
- [x] Stale source/audience/provider/access evidence blocks shared submission; explicit rebase and
  the Order 11 once-only ledger are implemented.
- [ ] Focused, affected-module, asset, route, worker, Reverb/Redis outage, and responsive tests pass.
- [x] Docs/TODO/index/Knowledge and `HR-2026-08-16-009` are updated; human review remains Pending.
