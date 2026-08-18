# Feature Slice: Email Mail Advanced Collaboration

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `../adr/2026-08-16-email-conversation-collaboration-and-draft-review.md`
Owner: Svein / Codex
Human Review: `HR-2026-08-16-031`

## Goal

Add exact conversation responsibility, handoff, watchers, private internal collaboration notes,
member mentions, and version-bound shared-draft review without widening Mail access, duplicating
Ticket collaboration, or weakening send and draft concurrency safeguards.

## Dependencies

This slice follows the completion index. It requires stable implementations of:

- private live invalidation and current-authority revocation (order 8);
- reading/typing presence, shared-draft fencing, and stale composer protection (order 9);
- unified compose/draft/send/Sent API parity (order 11);
- Email/Ticket multi-conversation presentation and audience isolation (orders 13-20, especially 18);
- attachment malware quarantine (order 21), local advanced search (order 22), and lifecycle/hold/
  export/offboarding (order 23).

## User-Visible Behavior

- A compact collaboration surface on an authorized shared conversation shows the primary responder,
  watchers, unresolved draft-review state, and recent internal collaboration notes.
- An ordinary member can claim the conversation when mailbox policy permits. Organizers can assign,
  hand off, or clear responsibility to another current ordinary member with a bounded reason.
- Watching is explicit. It affects collaboration notifications only; it does not alter personal
  unread, provider Seen, account membership, Ticket subscription, or send authority.
- Authorized members may add internal notes and mention current ordinary members. Notes are clearly
  labelled `Internal to this mailbox` and never sent or shown in the customer portal.
- A shared-draft editor may request review from one or more current Send-authorized members. A
  reviewer sees the exact frozen sender, recipients, subject, body, clean attachment manifest, source
  and thread context, then approves or returns it with an internal comment.
- Approval never sends. Any later change visibly invalidates it. The actual sender still acquires the
  shared-draft lease and passes current authorization, stale-context, recipient, attachment, and
  outbound-idempotency checks.
- Unavailable or unauthorized controls disappear. Revoked members, expired delegates, and
  break-glass-only users are removed from collaborator choices and cannot read old note/review
  content.
- Mobile keeps the same workflow in a compact collapsed panel; it is not a reduced collaboration
  mode.

## Data Touched

Reserve migrations `2026_08_16_174000_create_email_conversation_collaboration.php` and
`2026_08_16_175000_create_email_shared_draft_reviews.php`.

### Conversation collaboration

Create `email_conversation_collaboration_states`:

- exact account and conversation FKs with unique pair;
- nullable primary responder, assignment generation, state (`unassigned`, `assigned`, `waiting`,
  `resolved`), and last-transition time;
- optional mailbox-policy version and optimistic row version;
- no subject, participant, snippet, body, provider, or Ticket content.

Create `email_conversation_collaborators`:

- state/account/conversation/user, role (`watcher` or `reviewer_pool`), active/revoked timestamps,
  creator/revoker and bounded reason;
- unique active membership by state/user/role;
- membership is a notification preference only and never an access grant.

Create `email_conversation_collaboration_notes`:

- state/account/conversation, author, immutable sanitized plain/HTML content, content hash, optional
  correction-of FK, status (`active`, `corrected`, `redacted`), redaction actor/reason/time, and
  timestamps;
- body limits, safe HTML policy, no remote content, no attachment support in this slice;
- deletion is forbidden while lifecycle/hold/audit references exist.

Create `email_conversation_collaboration_mentions`:

- note and mentioned user, immutable delivery key, delivery/read/revoked state and timestamps;
- unique note/user; no copied note body or mailbox content in Notification payload.

Create append-only `email_conversation_collaboration_events`:

- state/account/conversation, actor/affected user, event type, assignment generation, resource type/
  ID, safe reason, idempotency key, occurred time and bounded metadata;
- content hashes may be stored; note/draft/message content may not.

### Shared draft review

Create `email_shared_draft_review_requests`:

- shared draft/account/conversation/source, requester, status (`pending`, `approved`, `returned`,
  `stale`, `cancelled`, `expired`, `consumed`), expiry and policy version;
- frozen draft content version, fencing generation, source-context fingerprint, sender/recipient/
  subject/body and clean attachment manifest hashes, plus preview fingerprint;
- optional current decision generation and send reservation linkage; no duplicated draft body.

Create `email_shared_draft_reviewers`:

- request/reviewer, state (`pending`, `approved`, `returned`, `revoked`, `stale`), decision comment,
  decided/revoked time, decision fingerprint and unique request/reviewer;
- comments are internal Email content and follow note/lifecycle authorization and bounds.

Create append-only `email_shared_draft_review_events` for request, view, approve, return, invalidate,
cancel, expire, consume, and policy decisions. Store versions, hashes, actor, safe reason and
idempotency; never duplicate recipients/body/attachment names or provider errors.

Add mailbox collaboration policy fields in an Email-owned table rather than provider settings:

- assignment mode (`organizer_only` or `member_claim`);
- draft review (`optional` or `required_for_shared_send`), default `optional`;
- optional required reviewer count, hard maximum two;
- policy changes require account-manage plus ordinary Organize, are audited, invalidate incompatible
  pending reviews, and never retroactively fail a provider-accepted send.

## Actions And Invariants

Implement Email-owned, transactionally locked actions for:

- claim, assign, hand off, clear, watch, and unwatch;
- add note, append correction, authorized redaction, and mention resolution;
- request/cancel/expire/review a frozen shared draft;
- invalidate review after any draft/source/access/attachment/provider-binding change;
- enforce optional required-review policy inside the unified outbound reservation; and
- reconcile collaborators immediately after access/offboarding changes.

Every action captures one `now()`, locks exact account/state/draft rows in a consistent order,
reauthorizes current ordinary membership, and uses idempotency keys. Assignment does not lock reading
or sending. A reviewer decision is valid only for the exact frozen fingerprint and current member.
Any stale, unavailable, changed, unclean, unauthorized, or ambiguous condition fails without send,
provider write, personal-state mutation, Ticket mutation, or misleading success.

Notes and review comments are Email-owned private content. They enter order 21 content safety where
applicable, order 22's permission-filtered local index as explicit `collaboration_note` documents,
and order 23 lifecycle/hold/export/offboarding/erasure workflows. They are never AI input without a
separate governed workload/data-class approval.

## Permissions

- Current ordinary View sees collaboration state/notes for the exact account-conversation.
- Current ordinary Organize assigns/hands off/clears, corrects/redacts under policy, and changes
  conversation collaboration state.
- An active ordinary member may claim/release self or watch/unwatch when account policy permits.
- Current ordinary View plus Send is required to see shared draft content, request review, review, or
  satisfy a review policy. Approval does not grant Send.
- Account-manage plus current ordinary Organize changes mailbox collaboration policy.
- Personal accounts keep owner privacy. Any personal collaboration additionally requires an explicit
  active ordinary delegation and owner-enabled conversation sharing; no direct personal grant,
  Ticket access, configuration role, API token alone, or break-glass source qualifies.
- API ceilings (`email.read`, `email.update`) intersect exact current mailbox authority and cannot
  select or mention inaccessible users.

## UI And API

Add a compact, default-collapsed `Collaboration` panel to the selected Mail conversation. Its header
shows primary responder, watcher count, and pending review count. Use shared Bootstrap controls,
accessible labels, dense rows, and explicit internal-content badges. Do not displace the message body
or expose empty/unavailable controls.

Expose versioned Email API list/show/mutation endpoints for assignment, watchers, notes/mentions, and
draft reviews. Nested route binding is account/conversation/draft scoped and returns non-enumerating
denials. Resources never serialize inaccessible member lists, canonical IDs, raw paths, provider
evidence, note content to metadata-only callers, or draft content without current Send.

Ticket order 18 may render a link/summary only through the same Email query/actions and dual-domain
authorization. It must not copy notes into Ticket, reuse Ticket assignments, or publish them.

Notifications use the existing canonical Notification action after commit with immutable delivery
keys. Payloads contain safe labels and an opaque relative Mail URL only. Delivery rechecks active
recipient and ordinary View; opening reauthorizes again. Revocation suppresses unread notification
content and removes the actor from future selection.

## Tests

- Shared/system/personal owner/delegate matrices, current ordinary View/Organize/Send intersections,
  direct-personal-grant, Ticket-only, Admin/config-only, API-only and break-glass denials.
- Atomic claim/assign/handoff/clear, member-claim policy, duplicate/idempotent requests, competing
  organizers, revoked/disabled assignee, watcher isolation and no unread/Seen changes.
- Note sanitization/bounds, immutable correction, authorized redaction/tombstone, mention eligibility,
  notification idempotency/revocation, search/lifecycle/hold/export/erasure integration, and no portal/
  provider/Ticket/AI exposure.
- Review freeze, exact hashes, expiry, multiple reviewers/policy quorum, approve/return/cancel,
  requester/reviewer offboarding, content/attachment/source/recipient/binding drift, malware status,
  stale fencing token, concurrent save/review/send and ambiguous SMTP outcome.
- Required-review policy off by default; enabling is authorized/audited; no approval reuse across
  versions; approval never sends or grants authority; accepted SMTP is never rolled back by a later
  local collaboration failure.
- Private opaque invalidation, revocation, reconnect/fallback, no note/draft content in events/logs/
  Notification metadata, and no presence heartbeat persistence/reporting.
- Mail and Ticket responsive UI/API no-leak behavior, plus affected Email, Notification,
  UserManagement, Ticket, Search, retention, DSAR, offboarding and outbound regressions.

## Documentation And Operations

Update Email README/Knowledge, UserManagement collaboration/offboarding docs, Ticket boundary docs,
Notification behavior, local search and lifecycle runbooks, API/OpenAPI, TODO, completion index, and
`docs/human-review.md`.

Deploy additive migrations with `umask 0002`, seed no broad content permissions, rebuild caches/
views/assets, restart Email/default/live workers, and verify scheduler expiry/reconciliation. No
migration creates assignments, watchers, notes, reviews, notifications, or sends. Roll back
application behavior first; schema down refuses while review/send/lifecycle/audit evidence depends on
the tables.

## Done Criteria

- [ ] All listed foundations are stable and their authorization/lifecycle boundaries are reused.
- [ ] Assignment, watchers, notes, mentions, and draft review are exact account-conversation state
  and grant no access or send authority.
- [ ] Review is bound to one immutable draft/source/audience/attachment generation and invalidates on
  every meaningful change.
- [ ] Ticket/portal/provider/personal-state/AI boundaries and private event payloads remain intact.
- [ ] Focused, affected-module, API, live transport, search/lifecycle, responsive and operational
  checks pass.
- [ ] Docs/TODO/index/Knowledge and `HR-2026-08-16-031` are updated; human review remains Pending.
