# ADR: Email Live Invalidation User Stream Fanout

Status: Accepted
Date: 2026-08-16
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Supersedes: `2026-08-16-email-private-live-invalidation-transport.md`
Feature Slice: `../feature-slices/2026-08-16-email-mail-private-live-invalidation-polling-fallback.md`

## Context

The original live-invalidation ADR chose self-hosted Reverb, opaque private events, durable account
and user versions, and polling fallback. Those decisions remain correct, but its account-channel
contract left two correctness gaps when the number of authorized mailbox users is not bounded:

- one account change had no durable recipient cursor or per-recipient completion evidence; and
- a browser would need one private subscription per authorized account.

Owner, grant, delegation, and break-glass records have no product-wide recipient cap. Taxonomy
definitions can also affect every authorized Mail user without belonging to one Email account.
Transport publication therefore needs a bounded, crash-resumable fanout, while the browser needs a
constant-size subscription contract. Ordinary inbound storage must also delay invalidation until its
private artifacts, canonical self-map, placement, and visible conversation projection are all ready.

## Decision

Retain self-hosted Reverb, Echo, opaque events, durable projection versions, automatic polling
fallback, loopback-only Reverb, exact origins, and the single Livewire-owned Alpine runtime. Reverb's
loopback `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` listen pair remains distinct from its public
application host/port/scheme. Replace the account-channel and implicit fanout parts of the
superseded ADR as follows.

### Source Streams And One Browser Stream

Use durable source streams for Email accounts and global Mail taxonomy, plus one durable stream for
each user. Account and global changes are never broadcast directly. They are durable fanout sources
that derive changes into currently relevant user streams. Direct personal-state and authorization
changes append to the affected user stream without account fanout.

Every browser subscribes to exactly one permanent private channel:

```text
private-email.user.{userId}
```

Channel authorization requires an authenticated, active, non-system session user whose exact ID is
the channel ID. It performs no content read, unread-baseline mutation, break-glass use recording, or
other side effect. Account access is re-evaluated with
`ResolveMailboxAccessDecision::CONTENT_VIEW` during catch-up and every content/count query, not at
the channel boundary.

The server-rendered manifest contains only the authenticated user ID and that user's decimal-string
stream version. It never materializes or subscribes to every authorized account. Presence and shared
draft coordination remain a later, separate ephemeral namespace.

### Durable Bounded Fanout

Appending a source change and freezing its fanout high-water marks occur inside the same database
transaction as the visible projection mutation. Account fanout freezes the current owner, source
time, monotonic account-audience generation, the global content-ability generation, and maximum
candidate IDs for grants, delegations, and break-glass access. Global Taxonomy fanout freezes the
source time, global active-user generation, global content-audience generation, global content-
ability generation, and active-user high-water mark. The owner path, global permission/role-
membership path, and every other authorization-capable path stamp a new enable generation; mutating,
re-enabling, future-starting, granting a role or permission, or reactivating an existing row never
reuses its old generation. A frozen candidate is eligible only when every required part of at least
one currently qualifying authority path has an enable generation no newer than the source's matching
audience/ability generation, became effective no later than the source time, and current
authorization still succeeds. Generic current `CONTENT_VIEW` is not sufficient: a later alternate
grant, delegation, ownership change, global ability, role membership, break-glass record, or user
reactivation cannot authorize an older account or global source event. Each worker invocation
processes at most 100 candidates in one source phase and never holds a database lock across Reverb or
filesystem I/O.

Candidate scans require explicit `(email_account_id, id)` cursor indexes for grants, delegations, and
break-glass access, plus `(status, id)` for the global active-user cursor. Every scan selects at most
100 raw rows after the durable ID cursor and advances the cursor across every inspected row, including
revoked, future, inactive, duplicate, and otherwise suppressed candidates. It may not express
`LIMIT 100` only after an eligibility filter that could scan an unbounded rejected prefix. Exact
access resolution must use indexed `exists`/`limit 1` predicates; it may not materialize expired
delegations or other unbounded access rows during fanout or delivery.

The exact-user access boundary has its own access paths; account fanout indexes are not a substitute.
For content-view delegation, add both
`(delegate_id, revoked_at, can_view, starts_at, id)` and
`(delegate_id, revoked_at, can_view, expires_at, id)`. For content-capable break-glass, add the
corresponding `(actor_id, revoked_at, can_view_content, starts_at, id)` and
`(actor_id, revoked_at, can_view_content, expires_at, id)` indexes. Any additional operation used by
catch-up or channel authorization needs an equivalent exact permission-bit branch and index. The
next boundary is the minimum indexed future start or expiry across those finite branches. Crossing
or invalidating a stored boundary starts a durable, high-water-frozen exact-user recompute that reads
at most 100 access rows per page; until it seals, catch-up fails closed to a bounded authorized view
refresh and may not use `skipRender()`. Expired-denial diagnostics use SQL-filtered `exists` or
`order by ... limit 1`; `ResolveMailboxAccessDecision` may never load an unbounded expired history to
distinguish a reason code.

A unique source-change/user delivery row is created together with one derived user-stream change.
The transaction locks and rechecks the authoritative user authorization epoch, the applicable
account/global audience generation, the frozen global content-ability generation, and every part of
the qualifying access path before content-specific identifiers may be retained or published. Access,
role-membership, and permission writers serialize their epoch/generation change on the same authority
rows, so a revoke or late ability grant cannot commit between the final check and the identifier-
bearing append. Any post-source authorization-epoch change that cannot be attributed to a fully
source-time-qualified path also forces the conservative result. If that compare-and-swap cannot be
proven, the delivery may append only a generic, truncated authorization change and the browser must
perform a bounded current refresh. Duplicate candidates from several access sources converge on the
same delivery row. Revoked or inactive recipients are suppressed with a safe code. Authorization and
account-state mutations append a generic direct user change so a user who has just lost access still
receives an opaque prompt to remove rows and reauthorize.

Source publication is complete only after its frozen candidate cursor is sealed and every authorized
candidate has atomically appended its derived user change (or was currently unauthorized and safely
suppressed). Exhausting finite page attempts before that append moves the source to a visible blocked
operations state; it never advances the candidate cursor or pretends the source is published. Token
compare-and-swap claims, pending and abandoned-running recovery, finite attempts, and a scheduled
sweeper make crashes before dispatch, during a page, and after commit resumable. Delivery and
publisher jobs contain IDs and safe codes only. Failed jobs, application logs, and events never
contain subject, sender, body, filename, Ticket detail, provider metadata, credential references,
unread counts, canonical IDs, or raw exceptions.

Late grants do not replay old source events. The access mutation appends a direct user authorization
change, and bounded catch-up loads the current authorized page. Future-start, reactivation,
revocation, expiry, account disable, and user inactivity follow the same direct-user invalidation
rule. A permission or role-membership change affecting one user advances that user's authorization
epoch. A Spatie role-permission mutation that can affect an unbounded membership advances one global
authorization generation in O(1); every catch-up compares it and reauthorizes independently, while
an optional global source fanout supplies prompt hints in bounded pages.

### Event And Catch-Up Privacy

Reverb remains only a hint. The user-channel event contains schema version, decimal-string user
version range, allowlisted coarse change types, and a truncation flag. Account and record IDs are
included only when current authorization was rechecked immediately before publication; otherwise
the event is generic and truncated. The catch-up response applies the same rule.

No-change catch-up reads the user stream version, global authorization generation, and a bounded
exact-user access state containing a monotonic authorization epoch plus the next known start/expiry
boundary. It may call `skipRender()` only when those versions/epochs are unchanged, `now` is still
before that boundary, no boundary recompute is pending, and the tab completed a bounded authorized
refresh less than 120 seconds ago.
The 120-second connected safety check and every visible 15-second `poll`-mode tick always reauthorize
and refresh the current 25-row page/count/selection even if every version is unchanged. Crossing the
boundary forces the same refresh and recomputation when the scheduler, worker, or socket is late. A gap, pruned history, more than 250 versions,
truncation, crossed boundary, or mixed/revoked scope refreshes that bounded current view. Versions
and epochs remain decimal strings in Blade, JavaScript, events, and HTTP/Livewire payloads and are
never converted to a JavaScript `Number`.

Catch-up uses a dedicated authority/query path that cannot enter the monolithic Mail workspace
`render()`/navigation path. Each invocation reads at most the current 25-row list page plus the exact
currently selected placement/conversation. Periodic catch-up never enumerates every authorized
account, folder, sendable account, break-glass row, Taxonomy option, or remote-operation dashboard.
Counts come from transactionally maintained bounded projections or from explicitly capped
`limit + 1` queries that return a visible truncation marker; a periodic path may not run an exact
`count(*)` over an unbounded mailbox scope.

The existing account/folder navigation remains available through explicit user actions and a
controlled paged UI, not through the 15/120-second catch-up loop. Owner accounts, grants,
delegations, break-glass rows, and folders are read as raw ID pages of at most 100 rows with durable
or request-bound cursors that advance across suppressed rows. Add cursor indexes beginning with
`(owner_id, id)`, `(user_id, id)`, `(delegate_id, id)`, `(actor_id, id)`, and
`(account_id, id)` respectively. The exact selected account/folder and a hard-depth-capped ancestor
chain may be loaded directly after current authorization; no page may fall back to materializing the
full navigation collection.

### Transactional Writer And Store Boundary

Only an explicit `EmailLiveInvalidator` may append changes. Broad model observers are forbidden. It
requires an existing outer transaction and accepts the complete change set as one batch. The batch
sorts and locks streams in the canonical total order `global`, `account:{id}`, then `user:{id}`;
callers may not acquire several stream rows through sequential caller-defined appends. It allocates
versions without gaps and leaves rollback with neither a version nor a publication job. Dispatch
occurs only after the outermost commit; a sweeper recovers a commit-to-dispatch crash.

Before ordinary provider ingestion is instrumented, it must use the same hidden, repairable storage
boundary as reconciliation: commit the message, hidden `inbound_store_pending` placement,
conversation pointer, intended raw path, and attachment path references before private bytes; verify
raw/attachments and the canonical self-map; then use one short final database transaction to flip
the exact placement visible, refresh the conversation projection, and append invalidation. Inbound
rules and provider deletion run only after that activation commits. Existing unrelated active
occurrences remain immutable.

Visible writers in Email, Ticket links, Taxonomy assignments/definitions, mailbox access, and
personal unread/opened state must call the invalidator explicitly. Reconciliation-ledger-only and
hidden repair mutations do not emit browser changes.

Retention applies only to sealed source changes and terminal delivery evidence after their compact
summary is durable. It may never prune an unsealed/blocked publication, a cursor needed for resume,
or a user change newer than that stream's advertised oldest retained version. The nominal 72-hour /
10,000-change limits yield to unfinished fanout and backlog alarms.

## Rationale

- One user channel gives every tab a constant subscription and version contract regardless of how
  many mailboxes the user may access.
- Durable source and delivery rows make more than 100 recipients, duplicate candidates, retries,
  revocation, and worker loss deterministic.
- A global source stream gives Taxonomy one authoritative invalidation seam without an unbounded
  synchronous user loop inside Taxonomy actions.
- Direct user authorization changes remove revoked content without leaking account identity through
  a stale account channel.
- Hidden inbound activation prevents browsers from refreshing into a message whose private files or
  canonical projection are incomplete.

## Consequences

Positive:

- Browser subscription count and catch-up authority remain constant per tab.
- Account and global changes can reach an unbounded audience through bounded, crash-safe work.
- Reverb loss, duplicate events, delayed fanout, and polling fallback converge on the same user
  stream version.
- Content and access revocation remain database-authoritative and fail closed.
- Frequent catch-up cannot amplify an unbounded Mail workspace render; full navigation remains an
  explicit, paged user operation.

Negative:

- The live foundation needs source changes, fanout/publication state, and per-user delivery evidence
  in addition to the stream table.
- Access, Taxonomy, Ticket, personal-state, and Email projection writers need an explicit coverage
  inventory and cross-module tests.
- Ordinary inbound storage requires a hidden final-activation phase before live invalidation can be
  correct.
- Global changes may produce substantial but bounded background work and require backlog alarms.

## Alternatives Considered

- **One account channel per authorized mailbox.** Rejected because account count and browser
  subscription count are unbounded, and revocation still needs a separate user signal.
- **Broadcast account changes directly and let Reverb fan out.** Rejected because there is no durable
  per-recipient completion, reauthorization, or crash-resume boundary.
- **Resolve every recipient synchronously in the mutation transaction.** Rejected because access
  fanout is unbounded and database locks must never span transport work.
- **Maintain only a materialized current audience table.** Rejected as the sole authority because
  stale audience rows could grant delivery. Frozen candidate cursors plus current
  `CONTENT_VIEW` reauthorization remain authoritative; a materialized table may later be an indexed
  optimization only.
- **Polling only.** Retained as the correctness fallback, but not as the finished primary experience.

## Follow-Up

- Update the related Feature Slice, TODO, completion index, Knowledge documentation, and
  `HR-2026-08-16-008` to this channel/fanout contract.
- Add guarded, additive schema for source/user/global streams, changes, publication cursors, and
  per-user deliveries, including rollback refusal while evidence exists.
- Prove transaction rollback, high-water paging, duplicate candidates, late grant/update,
  future-start, user reactivation, revocation, role-wide permission changes over 100 users,
  scheduler-down boundary expiry, deterministic delivery failure without false source sealing,
  retention pressure over unfinished fanout, abandoned claims, indexed exact current authorization,
  canonical multi-stream lock order, decimal-string versions, privacy-negative payload assertions,
  one-channel browser lifecycle, polling fallback, and absolute query bounds with more than 100
  authorized accounts, folders, and access rows plus a large mailbox.
- Keep `HR-2026-08-16-008` Pending until a named human reviews the real Reverb, proxy, CSP, scheduler,
  workers, revocation, outage, mobile, and browser behavior.
