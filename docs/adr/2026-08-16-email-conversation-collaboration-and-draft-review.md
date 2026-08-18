# ADR: Email Conversation Collaboration And Draft Review

Status: Accepted
Date: 2026-08-16
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Depends On:
- `2026-08-16-email-private-live-invalidation-transport.md`
- `2026-08-16-email-private-presence-and-shared-draft-coordination.md`
Feature Slice: `../feature-slices/2026-08-16-email-mail-advanced-collaboration.md`

## Context

Presence and a single-editor draft lease prevent accidental simultaneous replies, but they do not
answer who owns the next response, who should be kept informed, why a handoff happened, or whether a
specific shared draft was reviewed. Teams currently have to communicate outside the mailbox, which
separates operational intent from the exact account-scoped Email conversation.

Mail collaboration must not become public chat, employee-performance telemetry, an alternative
Ticket timeline, or an authorization shortcut. Personal-mail privacy, current mailbox membership,
recipient/thread safety, provider authority, and outbound idempotency remain unchanged.

## Decision

Email owns bounded collaboration state at the exact account-conversation boundary:

- one optional primary responder assignment;
- explicit watchers;
- immutable internal collaboration notes with typed corrections/redactions instead of destructive
  edit history;
- current-member mentions that create private canonical notifications; and
- version-bound shared-draft review requests with approve or return-for-changes decisions.

Assignment, watch, note, mention, and review never grant mailbox access. Every read and mutation first
requires current ordinary mailbox authority for the same account and conversation. Break-glass-only
access may not participate. A Ticket link does not widen access and Ticket assignments, internal
notes, audience, workflow, and portal publication remain Ticket-owned.

The primary responder is a coordination signal, not an exclusive content lock and not permission to
send. The assignee must already be an active ordinary mailbox member. Assignment may be claimed,
handed off, or cleared by an actor with current ordinary Organize authority; a member may claim or
release their own assignment under the same mailbox policy. Every transition is append-only and
emits an opaque durable collaboration invalidation after commit.

Internal collaboration notes are Email-private content. They are not sent, synchronized to the
provider, copied into a Ticket, exposed to the customer portal, or included in presence/broadcast
payloads. Notes are immutable; an author or authorized organizer can append a correction, while a
separately authorized redaction replaces presentation with a tombstone but retains metadata and a
content hash for audit/lifecycle policy. Mentions are allowed only for current ordinary viewers and
are rechecked before notification delivery and note display.

A draft-review request freezes the exact shared draft ID, fencing token generation, content version,
source-context fingerprint, attachment manifest hash, requester, reviewer set, and expiry. Approval
never sends mail and never grants Send. Any content, attachment, source, audience, authorization, or
provider-binding change invalidates the decision. The eventual sender must independently hold current
View/Send, acquire the draft lease, pass stale-composer validation, and execute the normal unified
outbound action. Mailbox policy may require one current approval for designated shared accounts only
after explicit configuration and human review; optional review is the default.

Notifications carry only a safe account label, collaboration type, actor label, and opaque relative
route. The recipient must reauthorize before seeing conversation subject, note, draft, or message
content. Durable collaboration events contain bounded identifiers, versions, safe reason codes, and
hashes, not message/draft/note content.

## Rationale

- Account-conversation scope matches Email's existing authorization and prevents cross-mailbox
  leakage.
- Explicit ownership and handoff reduce duplicate responses without turning assignment into a send
  privilege.
- Immutable notes and version-bound review preserve useful intent and audit while avoiding hidden
  edits.
- Existing private invalidation and draft fencing provide the correct delivery and concurrency
  foundations.
- Keeping Ticket collaboration separate prevents accidental portal or case-record publication.

## Consequences

Positive:

- Authorized shared-mailbox teams can coordinate responsibility and draft review in the same
  conversation surface.
- Every review is tied to exactly what was reviewed and becomes stale on any meaningful change.
- Notification and live-update payloads remain content-free and permission-filtered.
- Assignment and notes cannot widen mailbox or Ticket access.

Negative:

- Collaboration notes become lifecycle-, search-, hold-, export-, offboarding-, and erasure-relevant
  Email content.
- Assignment and notification delivery require current-member reconciliation after every access
  change.
- Optional required-review policy adds a send precondition that must fail closed without stranding
  already accepted SMTP outcomes.

## Alternatives Considered

- **Reuse Ticket assignments and internal notes.** Rejected because many Email conversations are not
  Tickets and a Ticket link never grants Mail authority.
- **Use Tasks for every handoff.** Rejected because responder ownership is mailbox coordination, not
  automatically a separate work item.
- **Store collaboration in chat.** Rejected because the exact Email conversation, authorization,
  retention, and outbound version must remain authoritative.
- **Approval by any administrator.** Rejected because configuration authority is not mailbox content
  or Send authority.
- **Approval remains valid after edits.** Rejected because the reviewer would no longer have approved
  the actual recipients, content, attachments, or source context.

## Follow-Up

- Implement the related slice after orders 8, 9, 11, 18, 21, 22, and 23 expose stable transport,
  shared draft, outbound, Ticket-boundary, malware, search, and lifecycle contracts.
- Reporting may consume permission-filtered aggregate collaboration facts but may not create
  individual performance rankings or retain presence heartbeats.
- Keep `HR-2026-08-16-031` Pending until named reviewers verify assignment, handoff, mention,
  notification, review invalidation, privacy, Ticket isolation, lifecycle, search, and responsive UI.
