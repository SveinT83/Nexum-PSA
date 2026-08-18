# Feature Slice: Email/Ticket Third-Party Recipient Policy

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-conversations-as-ticket-communication-channels.md`
Owners: Ticket / Email / Contact
Human Review: `HR-2026-08-16-017`

## Goal

Add an explicit, guarded way to reply to or start a supplier, vendor, partner or other third-party
Email conversation from a Ticket without weakening the established customer-reply policy. Every send
is anchored to one Ticket and one Email account/conversation intent, shows the exact From/To/CC/BCC
and portal audience, reauthorizes both domains, and never invents or auto-creates a Contact.

## Current Boundary And Dependencies

The normal Ticket customer-reply path is intentionally limited to a Published Ticket, its active
same-Client Contact and validated CC behavior. It must stay that way. A manual address in the current
composer is not sufficient authority for a new supplier conversation, and the default Ticket Email
account is not proof that an actor may send from an existing mailbox.

Implementation starts after orders 6, 9, 11, 13, 16 and current Contact/Relationship authority are
stable. It reuses the order-16 selected-conversation/outbound boundary and order-11 Email submission;
it creates no alternative transport. Order 19 remains the owner of Customer Portal publication.

## Recipient Classes

The shared resolver returns one of these typed results for every normalized address:

- `ticket_customer`: the active selected primary Contact or another active Contact belonging to the
  same Ticket Client and allowed by the existing customer policy;
- `known_third_party`: an active authorized Contact, Supplier, Vendor or governed Relationship record
  outside the Ticket customer audience;
- `conversation_participant`: a participant proven by the selected authorized Email conversation;
- `manual_external`: an address supplied by the actor with no authoritative eligible record; or
- `blocked`: malformed, duplicate/alias conflict, suppressed, no-reply/system, organization-denied,
  unsafe domain or otherwise policy-ineligible.

Resolution is deterministic and permission-filtered. An address matching a hidden Contact/entity is
not disclosed as a match. Sender/domain similarity, AI suggestions, prior Ticket free text and subject
tokens never grant recipient eligibility.

## Dedicated Actions

Provide separate preview/apply actions for:

1. replying within an already linked third-party conversation, deriving recipients only from the
   selected source message under order 16; and
2. starting a new third-party conversation from a Ticket, selecting an authorized Email account and
   explicit recipients without a source thread.

Neither action is the ordinary `Reply to contact` endpoint. New-thread preview freezes Ticket/Work
Context, account/binding, From, recipients, audience, body/draft, attachments, signature and policy
versions. It creates a new Email conversation only after the Email outbound reservation succeeds; it
does not fake a provider conversation before a durable Message-ID exists.

Manual recipients require the actor to enter the normalized address again or provide an equivalent
non-replayable confirmation token after viewing the exact preview, plus a bounded business reason.
Changing any recipient, role, audience, account, body/attachment manifest, provider binding or policy
invalidates confirmation. BCC is never inherited and is allowed only when an explicit organization
policy and separate BCC confirmation permit it.

## Additive Data Model

Reserve migrations after order 16, currently `2026_08_16_145000` and `146000`.

Add `ticket_email_recipient_previews`:

- opaque UUID, Ticket, optional relationship/source message, selected Email account, actor;
- operation, From hash, ordered normalized To/CC/BCC hashes and typed recipient-policy results;
- effective audience, Work Context/account/provider-binding/policy/draft fingerprints, expiry and
  status `ready`, `blocked`, `confirmed`, `applied`, `stale`, `cancelled` or `expired`;
- safe blocker counts/codes, idempotency key and timestamps.

Add `ticket_email_recipient_decisions` as append-only recipient-level evidence with preview,
recipient role/type, authoritative source identifier where permitted, decision, safe reason,
manual-confirmation hash/version, actor and timestamp. Ordinary rows/logs never store body, attachment
names, credentials or provider responses. Exact addresses remain encrypted or in the already-owned
Email outbound recipient record and are exposed only to authorized compose/inspection paths.

Down refuses while a preview/decision is bound to an outbound communication or immutable audit.
Schema migration sends nothing and creates no Contact.

## Permissions And Policy

Add a dedicated domain permission such as `ticket.email_third_party_send` and API ability with the
same meaning. Every apply also requires:

- Ticket View plus Reply/Update under the exact Work Context;
- ordinary Mail Send for the chosen account, and ordinary View when replying to a source;
- current recipient visibility/use authority in Contact/Client/Supplier/Relationship where a record
  is selected; and
- the order-16 audience, attachment, draft-lock and provider-binding checks.

The permission is not assigned to broad Technician roles by default. Break-glass, Ticket ownership,
Client access, seeing an address in mail, or an API token alone does not grant send authority.

Organization policy may restrict domains, address classes, To/CC/BCC counts, external/internal
mixing, attachment types, account kinds and confirmation lifetime. Defaults are fail-closed for
manual/BCC recipients and do not silently fall back to the customer-reply path.

## Audience And Ticket Semantics

- Third-party/supplier communications are `internal` to the Ticket portal audience by default.
- Sending, linking a resulting conversation, adding a public Ticket reply, selecting a customer
  Contact in CC, or later classifying the entity never publishes it to Customer Portal.
- The exact effective audience and recipient classes are visible in preview. Portal publication, if
  desired, is a separate order-19 action over captured evidence.
- A new third-party conversation creates one primary/reference relationship according to the explicit
  preview; it cannot silently become the customer thread or replace an existing primary.
- No selected or manual address creates/updates a Contact, Client, Supplier, Vendor or Relationship.
  The UI may offer a separately authorized Contact workflow after send, with no retroactive effect.

## Idempotency And Failure

Apply locks and revalidates the preview, recipient decisions, Ticket, account, relationship/draft and
outbound submission, then calls order 16 once. The preview is single-use but idempotent for the same
key/fingerprint. Different recipients/payload with the same key returns conflict.

Pre-provider denial preserves the draft and marks no recipient as contacted. Accepted/unresolved
SMTP follows the Email outbound reconciliation contract and blocks another send. A recipient that
becomes inactive/hidden or a permission/policy/provider-binding change before execution makes the
preview stale with no provider call.

## Bounds And Privacy

- Default 20 total recipients, organization hard maximum 100 and cap-plus-one denial.
- Preview TTL at most 15 minutes; manual/BCC confirmation at most five minutes and one apply.
- UI/API list only safe recipient labels the actor can currently view; non-enumerating denial hides
  records/accounts/Tickets outside current scope.
- Events and notifications use counts/types/safe IDs, not addresses, subject, body, attachment names
  or hidden entity labels.
- Private live invalidation publishes only opaque Ticket/Email projection versions.

## Out Of Scope

- Marketing/bulk delivery, auto-replies, automatically discovering recipients, Contact creation,
  send-as/on-behalf expansion, portal publication, entity matching (order 25), or provider mutation
  other than the explicit outbound send/Sent workflow.
- Weakening the existing customer reply Published/same-Client constraints.

## Tests

- Existing same-Client customer reply behavior remains unchanged and cannot invoke third-party
  policy without its dedicated permission.
- Selected third-party reply and new thread with known Contact/Supplier/Vendor/Relationship,
  participant and manual address; deterministic classes and hidden-record non-disclosure.
- Exact From/To/CC/BCC/audience preview, dedupe/aliases, no older-conversation recipient bleed,
  domain/count policies, manual/BCC confirmation and invalidation on every drift.
- Mail Send/View, Ticket/Work Context and Contact/Relationship permissions; revoked delegation,
  disabled actor, break-glass denial, API scope and non-enumerating routes.
- One outbound submission, idempotent retry, changed payload conflict, pre-send failure, accepted/
  unresolved reconciliation and no duplicate Ticket message/relationship.
- No Contact/entity creation or mutation, customer-thread replacement, provider read-state change,
  rules/AI/Signal loop or customer Notification.
- Third-party content stays portal-internal through send/link/Sent/capture; order-19 publication is
  required and no retroactive exposure occurs.
- Migration/down guards, sanitized evidence, queues, cache/view and affected
  Email/Ticket/Contact/Portal/API regressions.

## Documentation And Operations

Update Ticket/Email/Contact Knowledge, API/OpenAPI, recipient-policy/security runbook, TODO,
completion index and `docs/human-review.md`. Deploy additive schema/permissions with `umask 0002`,
clear caches, rebuild group-writable views and restart Email/default workers. Keep the permission
unassigned until named review; deployment sends nothing.

`HR-2026-08-16-017` remains Pending until a named reviewer verifies customer-path isolation, every
recipient class, exact preview/confirmation, account/Ticket/entity authorization, idempotent send and
unresolved recovery, internal portal audience, no Contact creation, queue health and sanitized logs.

## Done Criteria

- [ ] Third-party reply/new-thread are dedicated actions with exact recipient/audience preview,
  typed deterministic policy and fresh Ticket+Mail+entity authorization.
- [ ] Manual/BCC recipients require bounded single-use confirmation; changed context fails closed.
- [ ] The Email-owned outbound path is used once, no Contact is auto-created, and third-party content
  remains portal-internal until a separate explicit publication.
- [ ] Tests, migrations, permissions, UI/API, docs, deployment and `HR-2026-08-16-017` are complete
  while human review remains Pending.
