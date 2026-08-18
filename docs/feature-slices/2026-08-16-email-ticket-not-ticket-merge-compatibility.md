# Feature Slice: Email/Ticket Not-Ticket And Merge Compatibility

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-conversations-as-ticket-communication-channels.md`
Owners: Ticket / Email
Human Review: `HR-2026-08-16-015`

## Goal

Turn `Mark as not Ticket` into a conversation-scoped routing correction and make Ticket merge
atomically transfer first-class Email relationships, captures and retired Ticket-key aliases. Mail
is never "returned" to Inbox because its provider placement never left Mail. Captured evidence is
preserved, unrelated Ticket conversations remain linked, and a multi-Ticket merge cannot partially
complete.

## Dependencies

Orders 13-14 relationship migration and conflict triage must be stable. Re-read current
`MarkTicketAsNotTicket`, `MergeTickets`, bulk controller/UI, Ticket Work Context/portal policy,
relationship/capture actions, Email rules, retention, tasks/time/cost/attachments and redirect logic.

## Not-Ticket Correction

The action starts from one exact active primary Email/Ticket relationship, not from every historical
Email message on a Ticket.

- Preview shows the selected account-scoped conversation, relationship, current/future routing
  effect, captures retained, Ticket impact and any separately proposed rule.
- Apply records a conversation suppression, unlinks only that active primary relationship and turns
  off automatic Ticket routing for that conversation.
- Provider mail, folders, flags, personal unread/opened state, captured Ticket messages/attachments,
  other Ticket relationships and the Ticket itself remain unchanged.
- If the Ticket has no remaining relationship, it remains a normal Ticket until an explicit
  Ticket-owned close/delete action. The action never soft-deletes it automatically.
- A suppression applies only to one Email account+conversation and policy generation. It is not a
  sender-wide or subject-wide rule and does not suppress an unrelated later conversation.
- The existing `not-ticket` tag and historical rules remain compatibility facts. New correction does
  not silently create or update a global `from + subject` rule. A separately previewed deterministic
  rule may be offered through order 10.

Add `email_ticket_conversation_suppressions` with account/conversation, source relationship/Ticket,
status, reason, actor/system policy, policy version, applied/revoked timestamps and append-only events.
Enforce one active suppression per account+conversation. Relinking/promoting requires explicit
suppression revoke/override with current authority and reason; it never disappears as a side effect.

## Atomic Merge Preview

Replace controller loops over `MergeTickets::handle()` with one preview/apply action for the complete
selected source set and one surviving target. Reserve migrations after order 14, currently
`2026_08_16_141000` and `142000`, for:

- `ticket_merge_runs`: opaque UUID, actor, target, exact sorted source IDs, frozen source/target Work
  Context and state fingerprints, counts, expiry, idempotency, status/progress and safe error;
- `ticket_merge_items`: every Ticket-owned record group and every Email relationship/capture/alias
  transfer or collapse decision, before/after identities, audience/role result, conflict/denial and
  immutable fingerprint; and
- `email_ticket_correlation_aliases`: retired source Ticket key → surviving Ticket, source merge run,
  status, actor/reason and timestamps. Alias is a correlation fact only, never authorization.

Preview is metadata-only, expires after 15 minutes, uses cap-plus-one and has installation-lowered
defaults. It shows source/target clients, sites, portal identity, workflows, open/closed states,
relationship roles/audiences, duplicate captures and blockers without exposing Mail content to an
actor lacking ordinary Mail View.

## Merge Apply

Apply locks all source/target Tickets in sorted order, then every affected relationship, capture,
alias and Ticket-owned child row in one database transaction. It reauthorizes the actor and target
Work Context and verifies the frozen fingerprint before changing anything.

For Email relationships:

- transfer every source primary and reference to the target;
- when target already references the same source-primary conversation, collapse into one target
  primary atomically;
- when duplicate target/source references exist, retain one active strongest role and append events
  preserving both provenance trails;
- collapse duplicate exact message captures idempotently without deleting either original Ticket
  message/evidence; retain source provenance and one authoritative active capture identity;
- abort the entire merge on competing/uncertain primary ownership, account mismatch, unresolved
  correlation conflict, suppression conflict, audience conflict or stale relationship; and
- route future correlated mail only to the surviving target after commit.

Customer-visible evidence requires provably compatible source/target Client, Site and portal
identity. An internal-only relationship/capture stays internal. No merge may promote internal
content, infer customer audience, retroactively publish, or hide an incompatibility behind the
"stronger" role. Uncertain identity aborts before any Ticket child row moves and creates an
authorized order-14 conflict/review fact.

After every transfer is valid, move existing Ticket messages, attachments, tasks, time, costs,
allocations, tags and other approved child records using their domain actions/invariants. Preserve
current Ticket merge behavior and old-link redirect, but write one durable run/event for the whole
multi-source operation. Create one retired alias for each source Ticket key. Soft-delete sources only
after all child and relationship updates succeed.

No merge sends/recaptures mail, mutates provider state, changes personal unread, runs inbound rules,
reconciles Sent, creates Contacts or publishes portal content. Private invalidations occur only after
commit.

## Authorization

Not-Ticket requires current Ticket Update plus ordinary Mail View of the exact relationship; any
relationship mutation follows order 13 authority. It does not require provider Organize because it
does not change provider state. Break-glass cannot suppress or unlink.

Merge requires current merge authority for every Ticket, target Work Context authority, and ordinary
Mail View for every Email relationship whose transfer/collapse/audience must be reviewed. An actor
without Mail access cannot use Ticket merge to infer or move a hidden relationship; the entire merge
is unavailable/non-enumerating or must first be handled by a separately authorized operator.

API tokens are bound to exact source/target Tickets, Work Context and accounts. List/show/apply use
opaque IDs and non-enumerating binding.

## Bounds And Failure Semantics

- At most 20 source Tickets, 500 Ticket child items and 500 Email relationship/capture items per
  merge preview; overflow requires a narrower merge and is never silently truncated.
- One apply transaction must remain bounded by those caps and installation policy. No per-source
  commits are allowed.
- Reusing the same idempotency/fingerprint returns the completed run. A different target/scope with
  the same key conflicts.
- Deadlock/DB failure rolls back every source, target, child, relationship, capture and alias change.
  Retry revalidates the original frozen run and cannot duplicate merge notes/events.
- Cancellation is preview-only; once the atomic transaction begins it either commits completely or
  rolls back.

## Out Of Scope

- Automatically resolving relationship/audience conflicts, splitting a Ticket, unmerging after the
  transaction, physical evidence deletion/redaction, provider mutation, Ticket send, portal
  publication or broad not-Ticket rules.
- Treating a retired Ticket key as proof of access or overriding a contradictory active key/header.
- Physical removal of legacy Email `ticket_id`, link rows or merge fields.

## Tests

- Not-Ticket affects one selected conversation, retains provider Mail and captures, leaves other
  relationships/Ticket/users/provider state unchanged, and never creates a broad sender/subject rule.
- Suppression uniqueness, revoke/relink, concurrent correction, stale relationship, reference-only,
  last-relationship behavior and legacy tag/rule compatibility.
- Two/many-source merge in one transaction; injected failure at every Ticket/Email child family
  proves zero partial moves/deletes/aliases/events.
- Primary/reference transfer, target-reference promotion, duplicate relationship/capture collapse,
  provenance retention, future routing to target and no duplicate capture.
- Same/different/missing Client, Site and portal identity; customer/internal audience preservation;
  unresolved primary/correlation/suppression conflict aborts and creates no partial merge.
- Retired aliases route a later exact old key only after normal authorization; active-key/header
  disagreement enters conflict; no alias authorization leak.
- Current Ticket/Mail permissions, revoked delegation, break-glass exclusion, hidden relationship,
  API account/Work Context binding and non-enumeration.
- Idempotency, concurrency/deadlock retry, stale preview, cap plus one, source already merged/deleted,
  target changed/closed and safe error evidence.
- No send/provider/read/rule/AI/Notification/portal side effects; private invalidation after commit.
- Migration/down guards, legacy redirects/parity, route/cache/view, and affected
  Ticket/Email/Portal/Task/Time/Storage/Taxonomy tests.

## Documentation And Operations

Update Ticket merge/not-Ticket and Email correlation Knowledge, technical READMEs, API/OpenAPI, TODO,
completion index and `docs/human-review.md`. Deploy additive migrations with `umask 0002`, clear
caches, rebuild group-writable views and restart default/Email workers. Deployment neither corrects
a relationship nor merges a Ticket automatically.

`HR-2026-08-16-015` remains Pending until a named reviewer checks scoped correction, suppression,
last/multi-conversation Ticket behavior, atomic multi-source merge, role/audience/conflict handling,
aliases, redirect/idempotency, permissions/no-leak, workers and unchanged provider/portal state.

## Done Criteria

- [ ] Not-Ticket is an explicit account-conversation suppression/unlink that preserves Ticket and
  mailbox evidence and creates no broad implicit rule.
- [ ] One frozen merge run transfers all Ticket and Email state atomically with target
  reauthorization, provenance, audience and conflict guards.
- [ ] Retired aliases are correlation-only, duplicate relationships/captures collapse safely, and
  future routing uses only the surviving target.
- [ ] Tests, migrations, UI/API, docs, deployment and `HR-2026-08-16-015` are complete while human
  review remains Pending.
