# Feature Slice: Email/Ticket Closed-Ticket Conversation Workflow

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-conversations-as-ticket-communication-channels.md`
Owners: Ticket / Email / Notification
Human Review: `HR-2026-08-16-020`

## Goal

Keep strong Email threading and Ticket evidence correct after a Ticket becomes Resolved or Closed,
while leaving every lifecycle decision to the published Ticket workflow. Strongly correlated inbound
mail stays on the existing primary Ticket; workflow may reopen, move to a follow-up state, or keep it
closed with a visible review item. Email never reopens a Ticket or creates a replacement case on its
own, and outbound from a closed Ticket never bypasses Ticket action policy.

## Current Defect And Dependencies

The current inbound link action writes a Ticket message and invokes a generic customer-reply trigger.
The default workflow only auto-resumes `waiting-customer`; the separate `closed -> in-progress`
transition has no inbound trigger. A closed reply can therefore be captured without a complete,
visible lifecycle decision. Conversely, the core action guard blanket-blocks customer replies on
closed Tickets before a published workflow can express an explicit reviewed path.

Orders 13-19 must be stable. This slice reuses deterministic correlation/capture, relationship role,
selected-conversation outbound, recipient/audience policy and portal enforcement. It changes no
provider state and introduces no alternate send or correlation heuristic.

## Typed Ticket Email Triggers

Define Ticket-owned workflow facts/actions for at least:

- `email_customer_reply_received` for an authorized customer-audience primary conversation;
- `email_external_reply_received` for a third-party/internal primary conversation;
- `email_customer_reply_requested` and `email_external_reply_requested` before outbound submit; and
- `email_delivery_reconciled` as a non-transitioning evidence event unless a published workflow
  explicitly uses it.

Email/correlation supplies the exact relationship, capture and source identifiers after durable
write. Ticket resolves the current published workflow version and returns a typed decision:
`transition`, `review_while_unchanged`, or `blocked`. Controllers/jobs cannot implement their own
closed/resolved behavior.

Default policy is conservative:

- strong inbound on an open/waiting Ticket follows the current configured trigger;
- strong inbound on Resolved or Closed is captured, but without an explicit eligible published
  transition the Ticket remains unchanged and a review item is created;
- outbound on Closed is blocked unless a published workflow action explicitly permits a transition
  before send or an exceptional send-while-closed policy with current authority and reason; and
- weak/ambiguous correlation never invokes lifecycle policy and stays in order-14 triage.

Workflow administrators see a warning that an inbound trigger cannot be terminal, send mail, publish
portal content or run a destructive action. A configured transition is validated/published through
the existing immutable workflow version boundary.

## Additive Data Model

Reserve migrations after order 19, currently `2026_08_16_149000` and `150000`.

Add `ticket_email_lifecycle_decisions`:

- opaque UUID, Ticket, relationship, source capture/message or outbound communication;
- direction and typed trigger, source/fingerprint, Ticket status/workflow/version before decision;
- result `transitioned`, `review_created`, `blocked`, `already_applied`, `stale`, or `failed`, selected
  transition/target state, resulting status/version, actor or approved account-bound system policy,
  idempotency key, safe reason and timestamps;
- one decision per trigger+source+workflow version. Retry cannot apply a transition twice.

Add `ticket_email_closed_review_items`:

- decision, Ticket/relationship/capture, status `pending`, `acknowledged`, `resolved`, `dismissed`, or
  `stale`, priority/category, optional assignee, safe reason, due/acknowledged/resolved timestamps and
  optimistic version;
- no Email body, subject, participants, attachment names, raw/provider identity or hidden account
  metadata. Authorized Ticket/Mail inspection follows the normal source/capture boundary.

Add append-only lifecycle/review events and idempotent Notification references. Down refuses while
decisions/reviews/events are durable. Migration/backfill changes no Ticket state and creates no review
for old mail automatically.

## Inbound Processing

For one strongly correlated inbound source:

1. order 14 resolves exactly one current primary relationship;
2. order 13 idempotently captures the source into Ticket in one transaction;
3. after commit, dispatch one Ticket lifecycle decision with IDs/fingerprints only;
4. lock Ticket/workflow/relationship/decision and revalidate capture, status and policy;
5. apply one allowed non-terminal transition through the normal Ticket workflow action, or create one
   review item while status stays unchanged; and
6. notify eligible internal recipients once after commit.

The captured Ticket evidence exists even if a later transition/review notification fails. The failed
decision is visible and retryable without recapture. A concurrent manual Ticket transition causes a
fresh policy evaluation; it never forces the stale original target state. New inbound does not clear
resolution/closure timestamps except through the normal committed status action.

Email does not create a new Ticket because the target is closed. A retired Ticket-key alias still
routes through order 14 to the surviving primary relationship. Ambiguous key/header/alias evidence
creates conflict, not a closed-case review.

## Outbound Processing

Order-16 preview asks Ticket workflow for the exact selected conversation action before a draft/send
control is shown. On Closed Tickets the default is unavailable. If the published workflow requires a
transition, that transition must commit and the Ticket/draft context must be revalidated before Email
reserves SMTP. Failure/staleness means zero provider calls.

A deliberately configured send-while-closed exception requires dedicated Ticket permission, bounded
reason, exact relationship/audience/recipient preview and an immutable decision event. It does not
change the Ticket status and cannot be used by break-glass, rules, AI, automatic reply or a generic
Email Send holder. Resolved Tickets follow their published action policy rather than being treated as
open by default.

An accepted/unresolved send remains under order-16 reconciliation; changing the Ticket lifecycle
after provider acceptance cannot make the delivery replayable. Sent reconciliation records evidence
but does not reopen/move the Ticket unless a separately published non-sending workflow trigger allows
an idempotent transition.

## Review Queue And Notification

Pending review is visible on the Ticket, assigned work views and the selected relationship card only
to users who may view the Ticket. Opening the item does not grant Mail access; inspecting live source
content additionally requires ordinary Mail View, while captured Ticket evidence follows Ticket
policy. Controls disappear when the action/target is unavailable.

Notification uses existing user preferences and current Ticket recipient policy, contains only safe
Ticket key/status/reason text and a relative Ticket URL, and never copies mail subject/body/sender or
attachment names. Delivery is idempotent and its failure is visible without changing lifecycle.

Acknowledging a review is not resolving the Ticket. Resolve/dismiss requires a bounded reason and
current Ticket authority; any status transition still uses the normal workflow action.

## Authorization, Bounds And Races

- Inbound system execution requires current account Ticket-ingress policy and relationship
  eligibility; it has no human mailbox grant and performs no Mail read beyond the already-authorized
  capture payload.
- Human review/outbound requires Ticket/Work Context plus ordinary Mail authority where live source
  or send is involved. Break-glass never transitions/sends/reviews Ticket context.
- At most one active review per Ticket+relationship+source decision. A burst of distinct messages
  creates distinct evidence but may be grouped visually, never silently dropped.
- Worker claims are token-owned, bounded and idempotent. Disablement, workflow version change,
  relationship transfer/suppression or Ticket deletion makes pending work stale.
- API routes use opaque IDs and exact Work Context/account scope with non-enumerating denials.

## Out Of Scope

- Always reopening, always creating a new Ticket, weak sender/subject correlation, automatically
  sending a reply, changing recipient/portal policy, provider Seen/move/delete, or making Closed
  Tickets generally editable.
- Retroactively processing historical closed replies during migration.

## Tests

- Strong customer and third-party inbound against open, waiting, resolved and closed statuses for
  explicit transition, follow-up state and review-while-closed policies.
- Default closed behavior creates one capture/decision/review/notification, no new Ticket and no
  status change; weak/ambiguous evidence stays only in correlation triage.
- Published workflow version changes, concurrent manual transition, duplicate/redelivered inbound,
  capture failure, transition failure and notification failure/retry without recapture/duplicate.
- Closed/resolved outbound default denial, transition-before-send, exceptional send-while-closed,
  stale draft/policy/access and accepted/unresolved outcome without replay.
- Ticket/Mail permissions, Work Context, account ingress, delegation/revocation, break-glass, API
  non-enumeration, disabled actor/account and relationship transfer/suppression.
- Review visibility/acknowledge/resolve/dismiss, captured-vs-live content boundary, safe notifications
  and no hidden account/message metadata.
- No provider read-state/move/delete, new Ticket, portal publication, broad rule, Contact/entity,
  AI/Signal or automatic reply side effects.
- Migration/down guards, workflow publish validation, queues/scheduler, route/cache/view and affected
  Email/Ticket/Notification/Portal tests.

## Documentation And Operations

Update Ticket workflow/admin and Email communication Knowledge, API/OpenAPI, closed-case incident
runbook, TODO, completion index and `docs/human-review.md`. Deploy additive migrations/permissions
with `umask 0002`, clear caches, rebuild group-writable views and restart Email/default workers.
Deployment does not process old replies, reopen Tickets, create reviews or send.

`HR-2026-08-16-020` remains Pending until a named reviewer checks customer/third-party inbound across
open/resolved/closed states, each workflow decision, review/notification, outbound denial/exception,
concurrency/idempotency, permissions/no-leak and unchanged provider/portal state.

## Done Criteria

- [ ] One Ticket-owned typed workflow decision handles every strongly correlated inbound/outbound
  lifecycle case; Email never changes Ticket status directly.
- [ ] Resolved/Closed policy is explicit and versioned: transition, follow-up or visible review,
  with conservative defaults and no replacement Ticket.
- [ ] Closed outbound cannot bypass workflow/recipient/Mail authority and accepted/unresolved sends
  remain non-replayable.
- [ ] Tests, migrations, permissions, UI/API, docs, deployment and `HR-2026-08-16-020` are complete
  while human review remains Pending.
