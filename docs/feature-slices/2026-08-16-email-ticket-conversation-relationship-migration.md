# Feature Slice: Email/Ticket Conversation Relationship Migration

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-conversations-as-ticket-communication-channels.md`
Owners: Email / Ticket
Human Review: `HR-2026-08-16-013`

## Goal

Replace message-level Ticket ownership as the operational relationship with a first-class,
account-scoped Email-conversation relationship, explicit primary/reference roles, selected-message
captures and append-only lifecycle evidence. Existing `TD-...` correlation remains supported. The
provider source stays in Mail, Ticket access never grants mailbox access, and migration never chooses
a primary Ticket when legacy evidence conflicts.

## Current Defect And Compatibility Boundary

The current `email_ticket_conversation_links` row is anchored to one message and the current
`LinkEmailConversationToTicket` action can silently demote another Ticket's primary link and unlink
other active message links. `email_messages.ticket_id` is also used as both captured-evidence
compatibility and a reason to hide mail from legacy Inbox/API paths.

This slice stops those behaviors without destructively removing the legacy columns/table. Existing
fields remain read-compatible during the rollback window, but they no longer authorize Mail access,
hide a real provider placement, or silently decide conversation ownership.

The 2026-08-24 safety repair documented in the Order 13 implementation summary replaces only the
unsafe legacy-link backfill with a bounded frozen preview/apply ledger against the existing
authoritative compatibility table. It does not complete this broader relationship/capture/event
schema, shared-action, dual-write or read-path target, and it deliberately refuses conflicts owned by
Orders 14-15.

## Dependencies

Implementation starts after completion orders 5, 7 and 12 are stable. It uses canonical source
occurrences, account-scoped durable conversations, current mailbox access, exact placement actions,
provider reconciliation, personal acknowledgement, and the accepted Ticket conversation ADR.

Before edits, re-read `LinkInboundEmailToTicket`, Ticket create/merge/not-Ticket actions, inbound
correlation/rules, Ticket timeline/resources/portal policy, retention, Notification and every query
that treats `email_messages.ticket_id` as authority or excludes linked mail.

## Additive Data Model

Reserve migrations after order 12, currently `2026_08_16_137000` and `138000`; renumber only if an
earlier dependency occupies them.

### `email_ticket_conversation_relationships`

One durable row per Ticket and account-scoped Email conversation:

- Ticket, Email account, Email conversation and stable public UUID;
- role `primary` or `reference`, status `active`, `unlinked`, `transferred`, `conflict`, or `retired`;
- nullable `active_primary_conversation_id` equal to the conversation ID only while this row is its
  active primary, with a unique index enforcing at most one active primary Ticket per conversation;
- audience default (`customer` or `internal`), automatic-routing eligibility, source/provenance,
  bounded reason code, actor or account-bound system policy, optimistic lock version, linked,
  unlinked and transferred timestamps;
- optional legacy-link/import provenance, but no copied body, subject, recipients, attachment names,
  provider UID, private path or mailbox credential.

A unique Ticket+conversation identity prevents duplicate relationship rows. Relinking updates the
same durable identity under a lock and appends an event; it does not erase history.

### `email_ticket_message_captures`

One explicit evidence record for a source message captured into one Ticket relationship:

- relationship, Ticket, Email account/conversation, exact source message and selected source
  placement, optional resulting Ticket message/attachment-set identifiers;
- direction, effective audience, capture source/policy, immutable source/evidence and attachment
  manifest hashes, status, idempotency key, actor/system policy and timestamps;
- unique Ticket+source-message capture identity. A retry returns the existing record and cannot
  create a second Ticket timeline message.

The capture records provenance. Ticket owns the captured Ticket message/evidence and its audience;
Email remains the source of current mailbox content and placement.

### `email_ticket_conversation_relationship_events`

Append-only metadata events for create, promote, reference, transfer-preview/apply, unlink,
audience-default change, capture, migration, conflict and retired-key association. Events record
relationship/Ticket/conversation/account IDs, actor/system policy, safe reason, prior/new role/status,
idempotency/dedupe key and timestamp. They contain no ordinary mail or Ticket body.

### Migration runs and items

Add bounded `email_ticket_relationship_migration_runs` and items, or an equivalent durable migration
ledger, for preview/apply/progress. Freeze the legacy link/message cohort, exact source hashes,
conversation IDs, Ticket IDs and counts before apply. Statuses distinguish `ready`, `already_mapped`,
`missing_conversation`, `missing_ticket`, `primary_conflict`, `audience_conflict`, `account_conflict`,
`applied`, `stale`, and `failed` with safe codes.

## Deterministic Backfill

Migration apply is explicit, resumable and idempotent; the schema migration itself does not recapture
messages, call providers, send notifications, acknowledge mail, publish portal content, or run
rules.

For each exact account+conversation+Ticket group:

1. resolve and lock current conversation and Ticket identities;
2. preserve every legacy row as import provenance;
3. choose the strongest unambiguous role only when all active evidence agrees;
4. create one relationship and one capture for each uniquely evidenced already-captured source;
5. link an existing Ticket message only when current provenance proves the exact source identity;
6. leave legacy `email_messages.ticket_id` intact as compatibility evidence during rollback; and
7. append migration events without changing Ticket tags/classification/audience.

If more than one Ticket claims primary ownership, accounts/conversations disagree, audience evidence
conflicts, or provenance is incomplete, create no active primary. Record conflict/reference shells
and a migration item for order 14 triage. Never select the newest/oldest Ticket, lowest ID, current
`ticket_id`, or a mutable subject as an automatic winner.

Backfill must not update Email/Ticket business timestamps merely to record the new projection. A
concurrent link/capture writer wins through source CAS or causes the item to become stale for a fresh
preview.

## Relationship Actions

Create shared cross-domain actions with explicit Email and Ticket policy collaborators:

- preview/create a primary relationship with selected-message capture;
- preview/create a secondary reference without capture or read/provider mutation;
- promote a reference only when no competing active primary exists;
- preview/transfer a primary relationship (actual conflict resolution belongs to order 14);
- unlink while retaining captures/evidence; and
- query one relationship/timeline projection under both domains' current permissions.

All actions lock the exact conversation and affected relationships. They reauthorize actor,
installation, account, active placement/message, Ticket Work Context, role, audience and current
lock versions. Route/model binding is non-enumerating across both domains.

Primary conversion requires ordinary Mail View, provider Organize for the selected source
acknowledgement, and the exact Ticket create/link authority. It atomically creates the Ticket (where
requested), relationship and selected capture. After commit it:

- marks only the selected source read for the actor through current-epoch personal state;
- records one provider Seen operation for that selected active-account placement; and
- publishes only the existing opaque/private projection invalidations.

A provider Seen failure does not roll back the valid Ticket relationship/capture and is shown as
pending/failed remote work. An actor without Organize may create only a clearly labelled proposal or
secondary reference where Ticket policy permits; it is not presented as handled/converted.

A secondary reference never captures future mail, participates in automatic routing, acknowledges
mail, changes recipients/audience, or grants Mail access.

## Read-Path Cutover And Dual Write

- `/tech/mail`, Mail API, raw source and attachment access always authorize from the source account
  and placement. A relationship badge/link is additive.
- Linked provider Inbox mail is no longer excluded merely because `email_messages.ticket_id` or a
  capture exists. It remains visible whenever the selected Mail view/unread/folder predicates match.
- Ticket timeline/query paths read first-class relationships/captures first, with a bounded
  compatibility fallback only while migration is incomplete.
- New inbound/link actions dual-write the first-class relationship/capture and legacy compatibility
  fields during the rollback window through one action. No controller writes `ticket_id` directly.
- Automatic routing uses only one active primary relationship after current account/conversation,
  audience and system-policy reauthorization. References never route.
- Ticket access alone never reveals Mail existence, counts, participants, attachments, raw source or
  conversation content. Mail access alone does not reveal inaccessible Ticket details.

Logical legacy-read retirement is complete only after the migration ledger has no unresolved/stale
items and parity checks prove all current writers/readers use the new boundary. Physical removal is a
later forward migration after named review and rollback expiry.

## Classification And Audience

Existing Ticket tags and classifications survive migration. A new relationship does not copy Email
tags into Ticket or Ticket tags into Email. Create Ticket may use a separately previewed approved
mapping, but this slice introduces no implicit mapping.

Customer audience is never inferred merely from an existing public Ticket message, Client link,
sender domain or legacy `audience=customer` default. Migration preserves proven current audience;
uncertain/mixed evidence becomes internal/conflict pending later explicit review. Historical portal
publication is unchanged and no capture is retroactively published.

## Bounds And Operations

- Preview default 100 relationships/messages, hard cap 500, cap-plus-one overflow and 15-minute TTL.
- Apply handles at most 25 items or about ten seconds per queue claim with tokenized leases and
  restart-safe progress; payloads contain IDs/fingerprints only.
- Cancellation stops new claims without undoing completed relationships/captures.
- A scoped parity command/report compares legacy link/message evidence with relationships/captures
  by account/conversation/Ticket and reports conflicts without content.
- No live provider read/write is needed for migration. Provider Seen occurs only for a new explicit
  primary conversion after its durable operation reservation.

## Out Of Scope

- Conflict resolution UI/decision policy (order 14), not-Ticket/merge transfer (order 15), Ticket
  compose/send (order 16), third-party recipients (order 17), multi-conversation UI (order 18),
  portal publication (order 19), and closed-Ticket workflow (order 20).
- Physical deletion of `email_messages.ticket_id` or legacy link rows/table.
- Retagging Tickets, auto-publishing messages/attachments, changing provider folder, deleting mail,
  or acknowledging a whole conversation.
- Treating a Ticket key, Ticket access, canonical correlation, sender or subject as Mail authority.

## Tests

- Schema uniqueness for one relationship per Ticket+conversation and one active primary per
  conversation; concurrent create/promote/transfer races; append-only events and strict down guards.
- Deterministic migration of one/many messages, one Ticket with several conversations, reference and
  audience provenance, rerun/resume/cancel and no timestamp/business-state drift.
- Multiple-primary, account/conversation, audience, missing source/Ticket and concurrent-writer
  conflicts create no chosen primary and no partial capture.
- Exact capture idempotency and Ticket-message provenance, failure after Ticket write, duplicate job,
  missing attachment/evidence, and no duplicate timeline message.
- Primary versus reference routing, promote/unlink semantics, existing-primary denial and explicit
  transfer preview; no silent demotion/unlink like the legacy action.
- Source remains in Mail Inbox/folder/all-mail according to normal predicates after link/capture;
  Ticket-link does not grant Mail UI/API/raw/attachment/search access.
- Selected-source personal/provider acknowledgement only, other conversation messages/future arrivals/
  accounts/users unchanged, provider failure truthfulness and no operation for a reference.
- Personal/shared/system, owner/grant/delegation/revocation, break-glass exclusion, Ticket Work
  Context/client/site denial and non-enumerating route/API resources.
- No tag/classification copy, no portal/audience change, no rule/Signal/AI/Notification send, no
  provider move/delete and no migration-generated customer communication.
- Legacy dual-write/parity, compatibility rollback, route/cache/view, queues, retention/hold, and
  affected Email/Ticket/Portal/Notification/API tests.

## Documentation And Operations

Update Email and Ticket README/Knowledge, Ticket merge/correlation documentation, API/OpenAPI where
relationships are exposed, TODO, completion index and `docs/human-review.md`.

Deploy additive schema with `umask 0002`, clear caches, rebuild group-writable views and restart
Email/default workers. Run metadata-only migration preview and require exact cohort/conflict counts
before apply. Deployment never links, captures, acknowledges, changes provider state, sends or
publishes automatically.

`HR-2026-08-16-013` remains Pending until a named reviewer checks migration preview/apply/parity,
conflict preservation, source Inbox visibility, primary/reference cardinality, selected capture,
personal/provider acknowledgement, dual write/rollback, mailbox/Ticket isolation, audience/tags,
worker health and sanitized evidence.

## Done Criteria

- [ ] First-class relationship/capture/event schema and bounded migration ledger are additive,
  idempotent, conflict-preserving and rollback-guarded.
- [ ] New shared actions never silently demote/unlink another relationship and dual-write legacy
  compatibility through one boundary.
- [ ] Mail remains placement-authorized/visible; Ticket routing uses only one current active primary;
  reference/capture/audience semantics are explicit and tested.
- [ ] Migration/read-path parity, permissions, provider/personal side effects, tests, docs and deploy
  gates are complete while physical legacy removal stays deferred.
- [ ] `HR-2026-08-16-013` is registered and remains Pending for named human review.
