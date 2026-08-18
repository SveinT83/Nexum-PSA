# Feature Slice: Email/Ticket Audience And Portal Enforcement

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-conversations-as-ticket-communication-channels.md`
Owners: Ticket / CustomerPortal / Email
Human Review: `HR-2026-08-16-019`

## Goal

Make portal visibility for Email-backed Ticket communication explicit, item-scoped, previewed and
audited. Customer delivery, a public Ticket, a relationship link or a `customer` conversation label
never publishes mail automatically. Supplier/third-party/internal correspondence stays internal by
default, and historical Ticket-message visibility is preserved exactly during migration.

## Dependencies And Boundary

Orders 13 and 16-18 must be stable: first-class relationships/captures, selected-conversation send,
recipient classification and the multi-conversation workspace. Order 21 clean attachment evidence is
required before publishing a recovered/Email attachment. CustomerPortal remains the owner of portal
membership and visibility; Email remains the live mailbox owner; Ticket owns captured case evidence.

Portal never reads an Email message, canonical source, raw file or attachment directly. Content must
first be an explicitly authorized Ticket capture. Publishing changes only CustomerPortal/Ticket
visibility of that retained copy and cannot widen Mail access.

## Audience Model

Keep these concepts separate:

- **delivery recipients:** addresses to which an Email was sent;
- **relationship default:** a reviewed suggestion for future captured items (`internal` or
  `customer_candidate`), never an automatic publication rule;
- **capture audience:** immutable/effective Ticket audience for one captured message/attachment set;
- **portal publication:** explicit visibility of exact Ticket-owned items to one Client and optional
  Site scope; and
- **portal notification:** a post-commit notification to members already entitled to the publication.

Sending to a customer, including a customer in CC, marking a Ticket Published, changing the
relationship default, linking an existing conversation or later matching a Contact does not change
an existing capture/publication. Third-party and mixed-recipient relationships default to internal.
Uncertain Client/Site/recipient evidence is internal/review, never customer-visible.

## Additive Data Model

Reserve migrations after order 18, currently `2026_08_16_147000` and `148000`.

Add `ticket_email_audience_policies`:

- Ticket relationship, Client and nullable Site scope;
- default `internal`, `customer_candidate` or `review_required`, source/evidence hash, policy version,
  status, actor/reason, optimistic version and timestamps;
- one current policy per active relationship plus append-only policy events.

Add `ticket_email_portal_publication_runs`:

- opaque UUID, Ticket/relationship, actor, exact Client/Site portal scope, operation `publish` or
  `unpublish`, frozen policy/membership/item fingerprints, status, expiry, idempotency, safe reason
  and timestamps;
- bounded counts for messages, attachments, denied/changed items and eligible portal members; no
  body, subject, participant/address, filename, raw path or mailbox identity in metadata listings.

Add `ticket_email_portal_publication_items`:

- run, exact Ticket message/capture and optional Ticket-owned attachment;
- before/after visibility, content/attachment manifest hash, malware/content-policy status, audience
  result, status/safe denial and applied timestamp;
- unique run+resource and durable publication identity so retry cannot notify/publish twice.

Add append-only publication events and link resulting CustomerPortal audit/notification IDs. Down
refuses while publication evidence is active. Migration never changes `portal_visible_at`, copies
content, publishes, notifies or calls Email/provider.

## Historical Backfill

Run a bounded metadata-only preview/apply after schema deployment:

1. freeze first-class relationship/capture and existing Ticket-message portal visibility;
2. preserve every historical message/attachment visibility exactly as-is;
3. derive `customer_candidate` only when authoritative same-Client/Site customer policy agrees for
   the whole relationship and no mixed/third-party/internal evidence exists;
4. assign `internal` for proven supplier/third-party/internal relationships; and
5. assign `review_required` for mixed, missing or conflicting provenance.

Backfill does not publish/unpublish, notify, rewrite Ticket message timestamps/audience, or infer from
sender domain, Ticket Published state, an outbound customer recipient or an AI/entity suggestion.
Conflict items remain visible only to authorized internal reviewers.

## Publication Preview And Apply

Preview starts from one relationship and explicitly selected captured Ticket messages/attachments.
It shows the authorized reviewer:

- exact content excerpts or full content through the normal Ticket safe renderer;
- attachment names/types/sizes and clean/quarantine/missing evidence;
- current and proposed internal/customer status;
- exact Client/Site portal scope and current eligible membership summary;
- third-party/manual/mixed recipient warnings and policy denials; and
- whether notifications will be emitted after commit.

Apply locks run, Ticket, relationship, captures, messages, attachments and portal scope; reauthorizes
everything; verifies the 15-minute fingerprint; then changes the selected Ticket-owned resources in
one bounded transaction. It never publishes an entire historical relationship from a checkbox or a
mutable relationship default. Unpublish is equally explicit and audited; it stops future portal
access/notification but cannot claim a member never saw or downloaded prior content.

Notifications dispatch idempotently after commit and contain only normal customer-facing Ticket
information. A notification failure does not roll back publication and is visible/retryable without
republishing. Adding a later message/attachment does not inherit publication automatically; it needs
its own policy-approved explicit action.

## Authorization And No-Leak Rules

- Add dedicated `ticket.email_portal_publish` permission plus existing Ticket Update and
  CustomerPortal scope management. It is not implied by Mail Send/View, relationship ownership,
  Ticket Published state or generic admin configuration.
- Preview of content requires current Ticket content access. Publishing a not-yet-captured Email
  source additionally requires ordinary Mail View and the order-13 capture action first.
- Break-glass cannot capture or publish. A portal user can never invoke publication.
- The selected Client/Site must match Ticket Work Context and every customer-visible captured item;
  mismatch or uncertainty aborts the whole run without partial publication.
- Portal list/show/download queries start from current portal membership and published Ticket-owned
  items. They do not query or route to Email as a fallback and return non-enumerating denials.

## Bounds And Lifecycle

- Preview defaults to 25 messages/attachments, hard cap 100 each, cap-plus-one denial and 15-minute
  expiry. Apply handles one exact run atomically within those bounds.
- Deleted/revoked provider mail does not remove a separately captured/published Ticket copy; Ticket
  retention and legal hold apply. An uncaptured live Email item cannot remain in portal.
- Ticket/capture deletion, legal hold, DSAR and unpublish actions record separate lifecycle outcomes;
  this slice does not promise erasure from backups or a recipient's prior download.
- Operational logs/events are metadata-only and sanitized. Private invalidation sends opaque portal/
  Ticket projection versions, not content or audience details.

## Out Of Scope

- Automatically publishing based on recipients, Client/Contact match, relationship default, Ticket
  status, AI classification or a future message.
- Portal access to live Mail, raw source, Email attachment routes, drafts, provider state or hidden
  relationship counts.
- Bulk retroactive publication, external file-provider sharing or auto-replies.

## Tests

- Historical public/internal Ticket messages and attachments remain byte-for-byte visibility
  equivalent after backfill; mixed/missing evidence becomes review without visibility change.
- Customer, supplier, third-party, mixed recipient and internal-note relationships get correct
  candidate/default state with no auto-publication.
- Exact selected publish/unpublish, later-arriving message unchanged, no relationship-wide checkbox,
  idempotent retry and stale content/policy/membership/Client/Site drift.
- Clean/quarantined/missing/changed attachment, partial-selection denial and atomic rollback on any
  item/audit failure.
- Ticket/Portal/Mail permissions, Work Context, Client/Site membership, revoked actor/delegation,
  break-glass and portal-user denial; non-enumerating list/show/download.
- Notification once after commit, failed notification retry without republish, no notification on
  preview/denial and current portal preferences respected.
- Provider deletion/Email revocation behavior, Ticket retention/hold, no raw/live Email fallback and
  no Mail/provier/rule/AI/Signal mutation.
- Migration/down guards, safe events/API, route/cache/view and affected
  Email/Ticket/CustomerPortal/Notification/Storage tests.

## Documentation And Operations

Update Ticket, CustomerPortal and Email Knowledge, API/OpenAPI, portal publication/incident/lifecycle
runbooks, TODO, completion index and `docs/human-review.md`. Deploy additive migrations/permissions
with `umask 0002`, clear caches, rebuild group-writable views and restart default/Email workers.
Deployment/backfill changes no visibility. First Dev publish uses clearly internal test evidence after
current portal memberships and Notification settings are confirmed.

`HR-2026-08-16-019` remains Pending until a named reviewer verifies historical preservation,
customer/third-party defaults, exact preview, selected atomic publish/unpublish, membership and
Client/Site isolation, attachment policy, notifications, lifecycle/no-live-Mail access and sanitized
evidence.

## Done Criteria

- [ ] Delivery, relationship default, capture audience, portal publication and notification are
  distinct persisted and enforced concepts.
- [ ] Historical visibility is preserved; new publication is exact-item, explicit, previewed,
  currently reauthorized, atomic and non-retroactive.
- [ ] Portal reads only published Ticket-owned evidence and never gains Mail authority or a live-Mail
  fallback.
- [ ] Tests, migrations, permissions, UI/API, docs, operations and `HR-2026-08-16-019` are complete
  while human review remains Pending.
