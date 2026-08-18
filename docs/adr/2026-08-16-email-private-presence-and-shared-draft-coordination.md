# ADR: Private Email Presence And Shared Draft Coordination

Status: Accepted
Date: 2026-08-16
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Depends On: `2026-08-16-email-private-live-invalidation-transport.md`
Feature Slice: `../feature-slices/2026-08-16-email-mail-presence-shared-draft-locks-stale-composer.md`

## Context

Authorized technicians working in one shared mailbox need to see that another technician is reading
or preparing a reply. They also need an enforceable single-editor boundary for a shared draft and a
clear warning when new mail, another sent reply, changed recipients, or revoked access makes an open
composer stale.

Presence and draft ownership have different correctness requirements. Reading and typing indicators
must disappear quickly and must not become employee-monitoring history. A draft lock, content
version, and send precondition must survive missed WebSocket events, browser crashes, retries, and
concurrent requests. Reverb is only an opaque hint transport and cannot be the authority for either
mailbox access or draft mutation.

## Decision

Use two deliberately separate coordination mechanisms:

1. short-lived reading and typing presence in installation-controlled Redis, delivered through the
   private Reverb channels selected by the live-invalidation ADR; and
2. a durable database lease with a monotonically increasing fencing token for each shared draft.

Presence heartbeats contain only account, conversation, source-placement, actor, activity, tab-token
hash, and expiry identifiers. They contain no subject, recipient, snippet, body, attachment,
provider, Ticket, or draft content. Presence expires without a cleanup job, is never copied into the
durable invalidation outbox, logs, analytics, opened receipts, or access history, and is unavailable
rather than displayed as stale when Redis/Reverb is unhealthy.

Shared draft content remains in Email's existing private draft storage. Sharing is explicit and is
available only for a shared or system account to active ordinary members who currently have both
mailbox View and Send. Personal drafts remain private by default. Break-glass content access does not
join presence, expose collaborator identity, or grant shared-draft participation.

One database lease row identifies the current editor, lease expiry, and fencing token. Acquisition,
renewal, save, attachment mutation, rebase, discard, and send reauthorize the actor and compare the
token and draft content version under a row lock. An expired lease may be acquired with a new token;
requests carrying an earlier token can never write even if they arrive later. Heartbeat renewal is
not audit history, while explicit share, acquire, expired takeover, release, rebase, discard, and send
transitions receive bounded metadata-only events.

When a composer opens, Email captures an immutable source-context fingerprint from the authorized
account, conversation, selected source placement/message, latest visible conversation generation,
reply identity and threading headers, normalized recipient preview, provider binding, and relevant
authorization generation. A changed fingerprint marks the draft stale and blocks send. Rebase is an
explicit preview-and-confirm action: it may preserve the technician's authored body and safe draft
attachments, but it recomputes recipients, subject, source, and threading context and never silently
merges another reply or quoted history.

Reverb events are coordination hints only. Every rendered collaborator, lock action, draft read, and
send path uses current ordinary mailbox authorization and the durable database state. Provider
draft sync and outbound send reconciliation retain their existing authority and idempotency ledgers.

## Rationale

- Redis TTLs make ephemeral presence naturally self-cleaning and avoid surveillance history.
- Database fencing protects draft integrity even during push loss, tab suspension, or concurrent
  requests.
- Explicit sharing preserves personal-mail and personal-draft privacy.
- A source fingerprint turns concurrent replies and new inbound mail into a reviewable stale state
  instead of silently sending with old recipients or thread headers.
- Reusing the private Reverb transport avoids another browser runtime while keeping correctness in
  database actions.

## Consequences

Positive:

- Shared-mailbox users receive useful coordination without a chat system or permanent heartbeat
  record.
- At most one valid fencing token can mutate one shared draft generation.
- A stale composer cannot send until its source and audience are reviewed again.
- Reconnect, polling fallback, and transport outage do not weaken draft correctness.

Negative:

- Redis availability is required for presence, though not for reading, drafting, locking, or sending.
- Shared-draft saves require row locks, version checks, and explicit conflict UI.
- Existing per-user draft rows and attachment paths need an additive compatibility migration.
- Ticket-originated collaboration cannot use the shared surface until its later multi-conversation UI
  slice supplies the exact Email account/conversation/source identity.

## Alternatives Considered

- **Store all presence in SQL.** Rejected because it creates avoidable retention and surveillance
  risk and high-frequency writes.
- **Use Redis locks as draft authority.** Rejected because eviction, failover, or lost renewal could
  permit stale writers without durable evidence.
- **Allow simultaneous last-write-wins editing.** Rejected because recipients, threading headers,
  attachments, and send intent cannot be safely merged that way.
- **Implement a CRDT collaborative editor.** Rejected for this slice because it adds a large content
  transport and conflict model without improving the required single-reply coordination boundary.
- **Automatically update an open composer after new mail.** Rejected because it can silently change
  audience, source, or quoted context.

## Follow-Up

- Implement the related slice only after private live invalidation and provider reconciliation are
  stable.
- The later Ticket multi-conversation UI must call the same Email-owned presence, lease, and stale
  composer actions with exact source identity; it must not create a Ticket-owned draft lock.
- Advanced collaboration may add explicit review/handoff workflows later, but cannot weaken the
  fencing, privacy, or no-content-event contracts decided here.
- Keep `HR-2026-08-16-009` Pending until a named human reviews real multi-tab, multi-user, revocation,
  outage, mobile, and stale-send behavior.
