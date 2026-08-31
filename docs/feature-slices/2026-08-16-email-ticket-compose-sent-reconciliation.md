# Feature Slice: Email/Ticket Compose And Sent Reconciliation

Status: Core Implemented On Dev / Shared Collaboration UI Gated / Human Review Pending
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-conversations-as-ticket-communication-channels.md`
Owners: Email / Ticket
Human Review: `HR-2026-08-16-016`


## 2026-09-01 Dev Implementation Outcome

The selected-conversation customer path is implemented on authoritative Dev. A Ticket relationship
prepares one existing Email-owned private draft for the exact account/source placement and redirects
the technician to that same draft in Mail. Reply and Reply all use only the selected source, preserve
external subject tokens, add the current `TD-...` key, and freeze recipient, thread, subject, source,
relationship, Ticket and provider-binding evidence before Email reserves SMTP.

`ticket_email_outbound_communications` and append-only events bind the Ticket intent to the Email
draft, outbound submission, Ticket message and exact Sent reconciliation. Accepted and unresolved
provider outcomes project one Ticket message through the normal Ticket action with the legacy Ticket
SMTP job suppressed. Replays cannot create another SMTP call or Ticket message. A matching exact
Sent occurrence updates the same communication and relationship capture idempotently. Deterministic
pre-provider drift remains a usable Mail draft but records a durable stale communication.

The migration `2026_09_01_090000_create_ticket_email_outbound_communications.php` is applied on Dev
and refuses rollback while communication evidence exists. Focused Ticket/Mail coverage passes 6
tests / 44 assertions and adjacent Email compose/submission/Sent coverage passes 5 / 81. No real
provider account, provider write, production migration or production test was used.

Order 9's optional multi-user shared-draft UI remains runtime-gated. The Ticket flow does not create
a second draft or SMTP path: it uses the exact private Mail draft today, while an explicitly shared
draft is still subject to Order 9's lease/fence boundary. Two-user/two-tab shared editing therefore
remains part of `HR-2026-08-16-009` and is not claimed by this implementation outcome.

## Goal

Make a Ticket reply through one selected Email conversation use the same Email-owned draft, outbound
reservation, provider identity, send, ambiguity and Sent-reconciliation boundary as Mail. The Ticket
adds case context and an additive Nexum key; it does not choose a different default sender, merge
separate recipient histories, bypass mailbox authority, or create a second SMTP path.

## Current Defect And Dependencies

The current Ticket customer-reply job resolves the account configured for the `tickets` scope and
sends a Ticket template independently of the selected provider conversation. It cannot prove that
the From identity, To/CC set, threading headers or Sent copy belong to the exact relationship shown
on the Ticket. A Ticket linked to several conversations can therefore not safely reply in-thread.

Implementation starts only after orders 6, 9, 11 and 13-15 are stable: Integration-owned provider
binding, shared draft locks/stale-composer protection, the unified Email outbound submission, and
first-class Ticket relationships/captures/correlation. Re-read the current Ticket reply API/UI,
`SendTicketReplyEmail`, Email composer/send/Sent services, relationship audience, Ticket workflow
guards, templates, attachments, Notification and portal paths before editing.

## Additive Data Model

Reserve migrations after order 15, currently `2026_08_16_143000` and `144000`.

Add `ticket_email_outbound_communications` as the durable cross-domain link, not a second send log:

- Ticket, relationship, Email account/conversation, selected source message/placement and stable
  public UUID;
- Ticket message, Email composer draft and order-11 outbound submission identifiers;
- immutable operation kind (`reply`, `reply_all`, or later separately guarded `new_thread`), effective
  audience, normalized recipient/thread/attachment/signature/source fingerprints and provider
  binding version;
- state `draft`, `reserved`, `sending`, `accepted`, `unresolved`, `reconciled`, `failed_pre_send`,
  `cancelled`, or `stale`, safe reason, idempotency key, actor, optimistic version and timestamps;
- nullable reconciled Sent source message/placement and resulting relationship capture; and
- uniqueness for one Ticket intent/idempotency key and one Email outbound submission.

Add append-only `ticket_email_outbound_events` for preview, draft creation, stale context, reservation,
provider acceptance/unresolved outcome, Sent correlation, cancellation and safe failure metadata.
Events contain identifiers and hashes, never body, recipient values, attachment names, Message-IDs,
private paths, provider responses or credentials.

Migration creates no drafts, Ticket messages, Email logs or provider calls. Down refuses while any
communication/event points at an active draft/submission/Sent capture.

## Selected-Conversation Context

Every Ticket compose starts from one active relationship and one exact authorized source placement.
The preview reauthorizes both domains and freezes:

- Ticket, relationship, account/conversation and selected source identities;
- active Mail Send plus ordinary View for the source, current Ticket reply authority and Work Context;
- From account/address, To, CC and BCC policy result;
- selected source Message-ID, bounded References chain and subject;
- effective internal/customer audience, attachment IDs and signature version; and
- conversation/relationship/source/provider-binding versions.

`Reply` derives To from the selected message's effective sender. `Reply all` derives To/CC from that
same selected message only, removes the chosen sender identity and actor aliases, deduplicates
normalized addresses, and applies the recipient policy. It never inherits CC from an older message,
another relationship, a Ticket Contact field or another linked conversation.

Thread headers use the selected source's verified Message-ID and bounded References chain. Missing or
unsafe headers produce an explicit new-thread proposal or denial; they are never fabricated from a
mutable subject. Subject handling preserves external provider keys/tokens and adds the current
`TD-...` key only when absent, without replacing an existing foreign ticket reference.

The normal current Ticket customer-contact reply remains available only through its established
Published, active same-Client Contact and CC policy. Third-party/manual-recipient behavior belongs to
order 17 and cannot be smuggled through this compose surface.

## Draft And Send Boundary

Ticket calls Email-owned reusable actions for draft creation/update/attachment handling and outbound
submission. A draft records its Ticket relationship source but remains an Email draft governed by
order 9 locks and order 11 fencing. Mail and Ticket show the same draft/lock state to currently
authorized users; neither surface creates a copy.

Before submission, one transaction:

1. locks the communication, Ticket message intent, Email draft and outbound reservation;
2. reauthorizes current Ticket and Mail access plus recipient/audience policy;
3. revalidates source/thread, provider binding, draft lock, body, attachments and signature;
4. reserves the Ticket public message and Email outbound submission under one idempotency identity;
5. commits before SMTP; and
6. dispatches only the Email-owned send action with frozen IDs and provider binding.

A deterministic pre-SMTP failure leaves a retryable draft and no claimed delivery. Once provider
delivery may have begun, the state is `unresolved` and all retries are blocked until the same Email
submission is reconciled. Ticket never sends through Laravel's system mailer, decrypts a provider
secret, or dispatches its legacy SMTP job for this path.

## Sent Reconciliation And Timeline

Email remains the owner of provider acceptance and Sent APPEND/import. When the exact submission is
accepted/reconciled, an idempotent projector links the resulting Sent source occurrence to the
communication and primary relationship, creates or links one Ticket message/capture, and appends one
event. It does not copy provider content into a second Email record.

The projector requires exact stable Message-ID/submission evidence, provider binding, account and
conversation. A matching reply sent from another IMAP client enters normal order-7/order-14
correlation; it may attach to the primary Ticket only when deterministic header/relationship evidence
agrees. It is never matched only by recipients, subject or time proximity.

Ticket and Mail present the same accepted/unresolved/reconciled state. Portal Notification and first
response/workflow side effects run once from the Ticket message transition after confirmed local
reservation according to existing policy; retries cannot duplicate them. An unresolved SMTP result
must never be displayed as failed/not sent or invite another send.

## Authorization And Privacy

- Ticket View/Reply never grants Mail View/Send; ordinary Mail access is rechecked at preview, draft,
  submit and reconciliation.
- Break-glass cannot draft, send, create Ticket communication or expose recipient/source context.
- Route/API binding uses opaque communication IDs and exact Ticket+relationship+account scope;
  inaccessible resources return non-enumerating denials.
- Internal relationships remain internal. This slice never publishes a Ticket message or attachment
  to Customer Portal merely because delivery was external.
- API uses order-11 Email abilities plus Ticket reply ability and exact account/Work Context binding;
  it calls the same action and idempotency contract as web.

## Bounds And Failure Semantics

- At most 100 normalized recipients, 100 References entries and the lower installation-configured
  limits; overflow is denied rather than truncated.
- Attachment/body/signature limits come from the existing Email and Ticket policies. Attachment
  content additionally requires order-21 clean evidence when that slice is active.
- Preview expires after 15 minutes and cannot survive source, relationship, audience, recipient,
  attachment, provider binding or draft-lock drift.
- Job payloads contain IDs, versions and hashes only. Provider and database exception messages are
  sanitized; recipient/content values are absent from ordinary logs/events.
- Cancellation is pre-provider only. Accepted/unresolved work is reconciled, never cancelled or
  blindly replayed.

## Out Of Scope

- Third-party/manual-recipient new threads (order 17), multi-conversation Ticket reader (order 18),
  portal publication (order 19), closed-Ticket policy (order 20), auto-replies, send-as/on-behalf
  expansion, or changing provider message state.
- Treating Ticket templates/default account as authority for an existing Email conversation.
- Combining recipient histories or attachments across relationships.

## Tests

- Exact selected conversation/account/source From, To/CC, headers and additive Ticket key; no older
  CC, other conversation, external token, history or attachment bleed.
- Reply/reply-all dedupe, aliases, missing/unsafe Message-ID, References/recipient caps and stale
  source/relationship/provider binding.
- Shared Mail/Ticket draft identity, lock/presence behavior, two concurrent responders, stale composer,
  attachment/signature parity and revocation at every step.
- One outbound reservation/SMTP call/Ticket message for web/API/retry; deterministic pre-send failure,
  accepted response, lost response/unresolved block and reconciliation race.
- Sent APPEND/import and external-client Sent correlation project once into both views; ambiguous
  evidence creates conflict and no capture.
- Published/same-Client customer policy, Ticket/Mail permissions, Work Context, personal/shared,
  delegation/revocation, break-glass denial and non-enumerating routes/API.
- Internal/portal audience unchanged, no duplicate Notification/workflow/first-response, and no
  provider Seen/move/delete, rule/AI/Signal or contact creation.
- Migration/down guards, queue serialization, sanitized evidence, route/cache/view and affected
  Email/Ticket/Portal/Notification/API regressions.

## Documentation And Operations

Update Email and Ticket README/Knowledge, API/OpenAPI, send/reconciliation runbook, TODO, completion
index and `docs/human-review.md`. Deploy migrations/permissions with `umask 0002`, clear caches,
rebuild group-writable views and restart Email/default workers. Deployment creates or sends nothing;
first Dev validation uses a controlled internal conversation after current notification settings are
confirmed.

`HR-2026-08-16-016` remains Pending until a named reviewer verifies selected-thread recipients and
headers, draft locking, idempotent send/unresolved recovery, Sent projection, multi-conversation
isolation, permissions, portal status, queues and sanitized evidence.

## Done Criteria

- [ ] Ticket and Mail use one selected-conversation draft/outbound submission and one Email-owned
  provider/Sent boundary.
- [ ] Recipient, thread, attachment, signature, audience and binding context are frozen and freshly
  reauthorized without cross-conversation bleed.
- [ ] Accepted/unresolved/Sent outcomes are truthful and idempotently projected once into both
  domains; no alternate Ticket SMTP path remains for this workflow.
- [ ] Tests, migrations, UI/API, docs, deployment and `HR-2026-08-16-016` are complete while human
  review remains Pending.
