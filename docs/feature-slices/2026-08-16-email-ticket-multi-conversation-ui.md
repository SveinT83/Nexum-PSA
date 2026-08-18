# Feature Slice: Email/Ticket Multi-Conversation UI

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 2
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-conversations-as-ticket-communication-channels.md`
Owners: Ticket / Email
Human Review: `HR-2026-08-16-018`

## Goal

Show several independent real Email conversations on one Ticket without rendering them as one fake
thread. Each conversation keeps its own account, participants, recipient history, subject/thread,
relationship role, audience, draft/presence and reply action. Ticket-owned captured evidence remains
available under Ticket policy, while live mailbox content still requires current Mail access.

## Dependencies And Current Gap

Orders 8-9 and 13-17 must be stable: private invalidation, presence/shared drafts, first-class
relationships/captures/conflicts, merge compatibility, selected-conversation outbound and recipient
policy. The current Ticket page and timeline assume Ticket messages are the communication record and
do not have a safe, account-authorized representation of multiple Email relationships.

This slice is presentation/query integration over those durable records. It introduces no new
provider write and no content-copy migration. Add schema only if implementation proves a required
user-owned view preference; do not invent a second message/timeline store.

## Ticket Conversation Workspace

Add a Bootstrap-native Ticket communication section with:

- one compact relationship list/card per authorized Email conversation;
- an explicit label for account/mailbox, primary/reference role, internal/customer audience,
  conversation subject, safe participants, latest authorized activity and draft/attention state;
- separate unread-for-me and provider status wording where either is shown;
- a selected-conversation reader that renders only that conversation's authorized source
  occurrences in chronological order;
- separate Ticket-owned captured timeline evidence, clearly labelled as a retained case copy rather
  than live mailbox state; and
- actions for open in Mail, reply/reply all, new related third-party conversation, audience preview,
  unlink/correction or conflict review only when the exact backend action is currently available.

The UI never concatenates messages from two relationships into one thread, reuses participants from
another card, or silently selects a different relationship for reply. Primary role controls automatic
future correlation, not visual prominence or access. References are visibly read-only unless a
separate promote/transfer action succeeds.

Default selection is deterministic: an explicit opaque relationship URL parameter when authorized,
otherwise the active relationship with the latest authorized activity, with a stable tie-breaker.
Changing selection resets compose/disclosure state and revalidates draft ownership. Browser history
and deep links preserve the relationship identity without exposing hidden IDs/content.

## Authorization And Two Content Owners

The query returns two independently authorized projections:

1. **Live Email projection:** requires current ordinary Mail View for the relationship account and
   exact active source placements. It may expose authorized Mail subject, participants, message body,
   attachments, raw links, per-user unread and provider state.
2. **Captured Ticket projection:** requires current Ticket View/Work Context and uses Ticket-owned
   messages/attachments/audience. It remains available according to Ticket retention after Mail
   access or provider placement is gone, but it does not grant a link back to hidden Mail.

Ticket access alone must not reveal whether hidden Email relationships/messages/accounts exist,
their count, latest time, participants, subject, draft, unread or conflict state. Mail access alone
must not reveal inaccessible Ticket data. When only a captured Ticket copy is authorized, present it
as normal Ticket evidence without an inferred live-mail indicator.

Break-glass may view exact Mail content only through its existing audited Mail boundary. It cannot
surface the Ticket relationship workspace, drafts, send controls, personal state, AI or Ticket
context. Delegation/revocation is rechecked on every Livewire request and API page.

## Query And Pagination Contract

Create one shared Ticket/Email projection query/action used by web/API that:

- starts from Ticket relationships under current Ticket scope, then independently intersects each
  with ordinary Mail authority before any Email content query;
- resolves canonical content while retaining the exact source placement as authorization/route
  identity;
- paginates relationships and each selected thread in SQL with durable ordering/tie-breakers;
- does not paginate placements and accidentally duplicate a conversation;
- eager-loads only the selected bounded thread/capture page and permitted metadata; and
- reports opaque projection versions for private invalidation/catch-up without payload content.

Default relationship page 20, hard 100. Default message page 50, hard 200. A Ticket above a cap uses
normal pagination, never truncation presented as complete. Content is sanitized using the same Mail
reader and attachment quarantine policy. No UI query calls the provider, indexes content, marks read,
runs rules or mutates Ticket.

## Shared Drafts, Presence And Actions

Order-9 presence/draft keys include exact account+conversation+relationship and never Ticket alone.
An authorized responder in Ticket is visible to authorized Mail users for the same conversation and
vice versa. Presence does not expose the Ticket number/user to someone lacking Ticket context; it
uses the lowest common safe label.

Compose always passes selected relationship/source and frozen version to order 16. Selection drift,
relationship transfer/unlink, access loss, new latest message or draft lock conflict makes the
composer stale and blocks send until refreshed. A draft for conversation A never appears or sends
from conversation B.

## Responsive And Accessible UI

- Desktop uses an equal-height relationship list/reader layout that consumes available workspace
  height before inner scrolling; mobile keeps the established natural stacked Mail behavior.
- Relationship selector is keyboard operable, has a visible selected/focus state and accessible role,
  count and audience text. Information is not color-only.
- Long subjects, account labels, participants and attachments wrap/truncate with full accessible
  names; no horizontal page overflow.
- Loading, empty, restricted, stale, conflict and unavailable-action states are honest and do not
  expose a disabled button that only errors.
- Opening live mail does not mark unread-for-me/provider Seen. Explicit acknowledgement stays in the
  Email action.

## API

Expose permission-filtered Ticket relationship summaries and one selected conversation/capture page
through explicit abilities. Resources never serialize raw canonical IDs, private paths, hidden
account/Ticket IDs or unavailable relationship counts. Reply/action links are emitted only when the
same execution-time policy is currently available; absence is not authorization for later use.

## Out Of Scope

- Audience publication (order 19), closed-Ticket decisions (order 20), search across hidden Mail,
  physical message copying, merging conversations, automatically changing primary role, or changing
  provider state.
- A new global activity/timeline model or durable presence history.

## Tests

- One Ticket with customer, supplier and external-provider conversations renders three separate
  cards/threads with exact account/participants/headers/attachments and no cross-thread bleed.
- Primary/reference roles, deterministic selection/deep links, transfer/unlink/conflict/stale state,
  pagination with multiple placements and stable latest ordering.
- Full/partial/no Mail access, Ticket-only captures, Mail-only source, revoked delegation, disabled
  user, break-glass isolation and non-enumerating HTTP/API/Livewire behavior.
- Ticket retention after provider/Mail removal shows only captured evidence; live mail never appears
  from Ticket authority and captured evidence is not mistaken for provider state.
- Shared draft/presence exact conversation key, cross-surface update, concurrent responders,
  selection reset and stale send blocking.
- No mark-read/Seen, provider call, relationship/capture mutation, portal publication, rules/AI/Signal
  or Notification side effect from render/pagination.
- Desktop equal-height/scroll, mobile stack, keyboard/screen-reader, zoom, dark theme, long strings,
  empty/unavailable states and no disconnected controls.
- Routes/API resources, projection versions, cache/view and affected Email/Ticket/Portal tests.

## Documentation And Operations

Update Ticket and Email Knowledge with several-conversation navigation, live-versus-captured content,
reply selection and access behavior; update API/OpenAPI, TODO, completion index and
`docs/human-review.md`. Clear caches, build frontend assets where order 8/9 requires them, rebuild
group-writable views with `umask 0002` and restart relevant workers. No migration/provider action is
implied if no schema is added.

`HR-2026-08-16-018` remains Pending until a named reviewer checks multi-conversation isolation,
partial authorization, live/captured distinction, selection/deep links, draft/presence, desktop and
mobile behavior, accessibility, API and unchanged read/provider/portal state.

## Done Criteria

- [ ] Each relationship is a visibly and technically separate conversation; no message, recipient,
  attachment, draft or action bleeds across threads.
- [ ] Live Email and captured Ticket projections are independently authorized before content query
  and remain honest under revocation/provider deletion.
- [ ] Pagination, private invalidation, shared draft/presence, responsive UI and API use one tested
  projection/action contract.
- [ ] Docs, automated verification and `HR-2026-08-16-018` are complete while human review remains
  Pending.
