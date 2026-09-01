# Feature Slice: Email Mail Private Live Invalidation And Polling Fallback

Status: Implemented On Dev / Runtime Disabled / Human Review Pending
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `../adr/2026-08-16-email-live-invalidation-user-stream-fanout.md`
Superseded ADR: `../adr/2026-08-16-email-private-live-invalidation-transport.md`
Owner: Svein / Codex
Human Review: `HR-2026-08-16-008`

## Goal

Make authorized Mail views update promptly after committed Nexum changes while remaining correct
through lost, duplicate, delayed, or unavailable WebSocket delivery. Push carries only opaque
private invalidation hints; database projection versions and automatic polling remain authoritative.

## Dependencies

Implementation starts only after completion orders 2, 3, 5, 6, and 7 are stable. Provider IDLE is a
hint to the provider reconciliation pipeline and never broadcasts directly to browsers. The final
canonical resolver, credential readiness, provider reconciliation, access, unread, Ticket-link, and
Taxonomy actions must be re-read before their transaction boundaries are instrumented.

## 2026-08-21 Rework Audit

The foundation was incorrectly reported as complete after migration `130000` had already run on Dev.
The implementation still lacked a trigger-safe append, used wrong authority-table names, exposed an
unused global broadcast-auth surface, subscribed twice, and left disabled mode connected to Echo.
It also has incomplete fanout/delivery phases and unwrapped writer call sites. Runtime activation is
therefore prohibited. After database recovery, the final Dev ledger records foundation migration
`2026_08_16_130000` in batch 118, inert Order 9/12 markers `2026_08_19_140000` and `150000` in
batches 119 and 120, and the unrelated Mail permission repair in batch 121. Forward migration
`2026_08_21_110000` ran in batch 122 and repairs the first stream-version NULL transition. Forward
migration `2026_08_21_120000` ran in batch 123; it refuses to change guards while live mode is
enabled, quarantines incomplete base-authority writer guards that otherwise block ordinary mailbox
grants, and permits valid version/acknowledgement transitions within one database timestamp second.
The default-off writer/client gate, strict module auth and focused regression tests make continued
ordinary Mail use safe while the remaining contract is completed. The enabled publisher state
machine, stable call-site idempotency, exact conversation identifiers and several required outer
transactions remain incomplete.

## 2026-08-24 Static Publisher Rework

The authoritative Dev working copy now has a default-off replacement publisher contract. Source
changes freeze authority generations and candidate high-water marks in the same outer transaction;
all instrumented writers supply stable operation identities and exact positive conversation IDs.
Candidate and delivery work advances in raw pages of at most 100, retries without cursor advance,
blocks after three failed claims, and seals the source only after a terminal compact delivery
summary. Missing recipient authority state fails closed instead of being counted as suppression.

Catch-up reads at most 250 versions and 50 identifiers, treats invalid/gapped/pruned/overflow or
changed-authority state as generic and truncated, and accepts acknowledgement only with a signed
user/version/epoch/global-generation receipt echoed after a rendered response. A no-change response
may skip rendering only after a fresh bounded-refresh receipt and before the 120-second boundary.
The browser owns one private channel, enters visible polling after five seconds, uses visible
15/30/60-second backoff, performs 120-second safety catch-up, and pauses while hidden/offline. Exact
origins and one exact CSP socket origin replace wildcards and broad `ws:` / `wss:` sources.

Forward migration `2026_08_24_120000_repair_email_live_publisher_state_transitions.php` ran in Dev
batch 125 with live mode disabled and replaced the previously run projection guards. Its isolated SQLite bootstrap and actual
disposable MariaDB trigger replacement/valid-invalid transition contract pass; cleanup readback
found zero temporary schemas. Migration deployment does not authorize runtime activation.
The final isolated Order 8 SQLite matrix passes 32 tests / 161 assertions; adjacent provider
projection/deletion/reconciliation coverage passes 52 / 457; the actual MariaDB contract passes
1 / 14; Pint and `npm run build` pass.

## 2026-09-01 Authority And Bounded-View Completion

Account owners, grants, delegations, emergency access, user lifecycle, user roles, and the Mail
content permission now update durable authority generations inside their domain transactions.
Exact affected users enter a resumable two-phase access recompute; role-wide changes advance one
global generation and the minute maintenance job reconciles users in pages of at most 100. Future
delegation and emergency-access start/expiry boundaries are stamped once and invalidate the exact
account/user audience without relying on a browser request.

The forced refresh path now projects only current authorized account IDs, allowlisted navigation
metadata, explicitly capped counts, the selected conversation, and the current 25-row page. It no
longer invokes the monolithic full-workspace navigation render. Forward migration
`2026_09_01_100000_restore_email_live_authority_writer_guards.php` bootstraps missing authority rows,
adds account/user bootstrap triggers, and restores null-safe database writer guards while live mode
is disabled. It ran on Dev in batch 22 after both live flags were read back as false; bootstrap
read-back found zero accounts or users without authority state. Focused authority plus
delegation/break-glass coverage passes 19 tests / 225 assertions.

Runtime remains disabled. The remaining gate is operational and human verification with a real
provider: supervised Reverb, Apache/Plesk proxy/CSP, dedicated worker and scheduler health, socket
loss/polling fallback, two-user browser behavior, and named revocation/outage review under
`HR-2026-08-16-008`.

## User-Visible Behavior

- New inbound/Sent mail, manual unread changes, opened receipts, classification, Ticket links,
  account/access changes, and selected-thread changes appear without a manual page refresh.
- Reverb is silent while healthy. After five seconds of failure, Mail says that live updates are
  unavailable and checks every 15 seconds automatically.
- Hidden or offline tabs stop periodic polling and catch up immediately on return.
- While a user is scrolled away from the top, existing rows stay stable and an authorized `New mail
  (N)` control appears. Deleted or unauthorized rows are removed immediately.
- The selected conversation remains permission-checked and may stay visible after an unread filter
  changes until the user navigates away.

## Data Touched

Reserve migration `2026_08_16_130000_create_email_live_invalidation_foundation.php`, renumbered only
if the provider-reconciliation migration has occupied it first.

### `email_live_projection_streams`

- `stream_type` (`account`, `user`, or `global`) with exactly one account/user/global scope;
- unsigned-bigint `current_version` and `oldest_retained_version`;
- `last_changed_at` and timestamps;
- unique account-stream and user-stream constraints.

### `email_live_projection_changes`

- stream FK plus unique `(stream_id, version)`;
- nullable account FK;
- allowlisted `change_types_json`;
- at most 50 conversation IDs and 50 placement IDs;
- `truncated`, publication state, availability/claim/publish times, attempts, next attempt, and one
  sanitized error code;
- no Mail content, identity, filename, Ticket detail, provider metadata, credential reference, or raw
  exception.

Default retention is 72 hours and 10,000 terminal sealed changes per stream, but unfinished/blocked
fanout, resume cursors, and unacknowledged user-version evidence cannot be pruned. Catch-up reads at
most 250 versions. Add one-time start/expiry invalidation timestamps to delegation and break-glass records; migration
backfill does not emit retroactive browser noise. Add a bounded exact-user access state with a
monotonic authorization epoch and next start/expiry boundary. Account-audience, global active-user,
global content-audience, and global content-ability generations plus per-authority-path enable
generations ensure that mutating, granting an alternate path or global ability, or reactivating an
existing row cannot make an old source event eligible. Owner authority and permission/role-
membership authority are generation-stamped as well as grants, delegations, break-glass, and active-
user state.

### `email_live_projection_publications` and `email_live_projection_deliveries`

- one token-owned publication cursor per account/global source change, with owner/access-table/user
  high-water marks, source time, and the applicable audience generations frozen in the same
  transaction as the source version, including the global content-ability generation for both
  account and global sources;
- at most 100 raw candidate rows inspected per invocation, with the durable ID cursor advanced over
  every inspected eligible or suppressed row and without a database lock over Reverb;
- one unique source-change/user delivery that atomically locks and rechecks the authorization
  epoch/generation, attests a qualifying source-time authority path, and appends the derived
  user-stream change;
- source completion only after the frozen cursor is sealed and every authorized candidate's user
  change was atomically appended or the candidate was currently unauthorized and suppressed; and
- bounded pending/abandoned recovery, finite claims, visible blocked state without cursor advance on
  deterministic append failure, safe error codes, retention protection, and backlog age.

Add explicit `(email_account_id, id)` cursor indexes for grants, delegations, and break-glass rows, plus
`(status, id)` for active-user global fanout. Cursor queries page at most 100 raw IDs before applying
eligibility and advance through an all-revoked, all-future, or all-duplicate page; they may not scan
until 100 eligible users are found. Exact delivery/catch-up authority uses indexed `exists`/`limit 1`
predicates and never materializes an unbounded expired-access collection.
Boundary lookup additionally requires
`(delegate_id, revoked_at, can_view, starts_at, id)` and
`(delegate_id, revoked_at, can_view, expires_at, id)` for content-view delegations, plus
`(actor_id, revoked_at, can_view_content, starts_at, id)` and
`(actor_id, revoked_at, can_view_content, expires_at, id)` for content-capable break-glass. Add an
equivalent permission-bit branch/index before any other access operation participates in catch-up.
An invalidated or crossed boundary is recomputed through a durable exact-user high-water/cursor with
at most 100 rows per page. While that cursor is open, catch-up always performs the bounded current
view refresh and cannot `skipRender()`. Replace the current expired-delegation/break-glass collection
loads with operation-specific SQL `exists`/`order by ... limit 1` queries backed by these indexes.

Add the bounded navigation and count projections needed to keep periodic catch-up independent from
the existing monolithic Mail workspace render. Account/folder/access navigation reads raw pages of at
most 100 rows and requires `(owner_id, id)` for personal accounts, `(user_id, id)` for grants,
`(delegate_id, id)` for delegations, `(actor_id, id)` for break-glass, and
`(account_id, id)` for folders. Cursors advance over every inspected row, including rows that
current authorization suppresses. Counts are either transactionally maintained materialized values
or capped `limit + 1` results with an explicit truncation flag; periodic refresh never executes an
unbounded exact count.

## Durable Invalidation Contract

Implement an explicit `EmailLiveInvalidator`; broad model observers are forbidden. Every affected
domain action records its complete stream-change batch inside the same transaction as its projection
mutation and fails if invoked outside a transaction. The invalidator sorts stream locks in the total
order `global`, `account:{id}`, then `user:{id}`; callers may not append several streams in their own
lock order. Account/global source changes also freeze source time, the account or global content-
audience generation, the global active-user generation where applicable, and recipient high-water
marks plus the global content-ability generation in that transaction. Delivery locks and rechecks the
relevant authorization epoch/generations and complete source-time authority path, including global
permission/role membership, in the same transaction as an identifier-bearing user change. Every
access, permission, and membership writer uses the same epoch/generation authority lock; when that
compare-and-swap cannot be attested, only a generic truncated change is retained. After commit the
invalidator dispatches a publisher to the dedicated `email-live` database queue with a short delay.
A scheduled sweeper recovers committed pending rows missed between commit and dispatch.

The publisher claims contiguous user-stream versions, coalesces bounded identifiers/types, and emits
`email.projection.invalidated.v1` through a `ShouldBroadcastNow` event. Account/global source
publishers first derive user changes through their durable fanout cursors; they never broadcast a
source stream directly. The queue jobs own retry. Duplicate/out-of-order events are harmless;
broadcast failure never rolls back Mail state.

Exact payload keys are:

```json
{
  "schema": 1,
  "scope": "user",
  "from_version": "41",
  "to_version": "45",
  "change_types": ["mail_projection"],
  "conversation_ids": [456],
  "placement_ids": [789],
  "truncated": false
}
```

Versions are decimal strings. Safe change types are `mail_projection`, `personal_state`,
`authorization`, `collaboration`, `taxonomy`, `ticket_link`, and `account_state`.

## Channels And Authorization

- `private-email.user.{userId}`

Account/global source changes derive durable user-stream changes through frozen, bounded fanout;
browsers never subscribe once per account. Candidates come only from owner, grants, delegations,
break-glass, or the bounded active-user cursor for global Taxonomy changes. Delivery and catch-up
re-evaluate current `CONTENT_VIEW`, but current access alone cannot authorize an old event. At least
one current authority path must also have been enabled by the frozen source generation and effective
at the frozen source time; a late alternate grant or ownership path is ineligible.
Authorization performs no personal-state write and records no content-access use.

Add module route `POST /tech/mail/broadcasting/auth`, named `tech.mail.broadcast.auth`, behind `web`,
`auth`, `tech`, `2fa.required`, CSRF, and a dedicated throttle. Add an exact exemption in
`EnforceTechRoutePermission` so mailbox owners/delegates are not blocked by the broad route-name
fallback. Register channel callbacks from a new Email service provider through
`bootstrap/providers.php`; do not create `routes/channels.php`.

Revocation, account disablement, access start/expiry, grant re-enable, and user reactivation increment
the affected user stream and authorization epoch. One-user role membership/permission changes do the
same; a role-permission mutation with an unbounded membership advances a global authorization
generation in O(1), which every catch-up compares. The browser removes inaccessible account rows and
state after catch-up while retaining its one user channel. Every Livewire content/count query
independently reauthorizes, even if a stale socket delivered an opaque event.

## Browser And Livewire Contract

Create `resources/js/email-mail-live.js` and import it from `resources/js/app.js`. It may initialize
Echo/Pusher but never Alpine. The quarantined implementation now tree-shakes Echo/Pusher from a
disabled production build. An enabled client build exposes only a lazy initializer, and the
server-rendered Mail workspace calls it only when its independent runtime/table gate passes; neither
flag can open a socket alone. Collaboration/presence additionally requires its separate server gate.
Subscribe once from a server-rendered user ID/user-version manifest and use the module CSRF/session
auth endpoint. Authorized account IDs are resolved only by bounded, current server catch-up and never
become an unbounded browser subscription manifest.

Catch up on connect, reconnect, `online`, `pageshow`, and visibility resume. Run a visible connected
safety check every 120 seconds. Enter fallback after five seconds of connection/auth failure and poll
every 15 seconds while visible, using jittered 15/30/60-second HTTP-error backoff. Hidden/offline tabs
perform no periodic poll.

No-change catch-up executes through a dedicated authority/query method or child component that can
never enter the full `MailWorkspace::render()`/navigation path. It may `skipRender()` only while user
version, global authorization generation, and authorization epoch are unchanged, `now` is before the
next access boundary, no boundary recompute is pending, and the last bounded authorized refresh is
younger than 120 seconds. The visible connected 120-second safety check and every visible 15-second
`poll`-mode tick always reauthorize and refresh at most the current 25-row page, the exact selected
placement/conversation, and bounded or explicitly truncated counts. A crossed start/expiry boundary
forces the same refresh even when the scheduler is late. Changed streams refresh that bounded view. A
gap, pruned history, impossible version, over-250 window, truncation, or crossed boundary never loads
the full mailbox.

The existing account/folder navigation UX remains available, but full navigation is refreshed only
after an explicit user action through controlled raw pages of at most 100 rows. Periodic catch-up does
not enumerate all authorized accounts, folders, access records, sendable accounts, Taxonomy options,
or remote-operation dashboard rows. The exact selected account/folder and its hard-depth-capped
ancestor chain may be loaded independently after current authorization.

Modes:

- `reverb`: primary private hints plus safety catch-up;
- `poll`: intentional fallback-only operation;
- `off`: emergency/manual-only, never accepted as finished production behavior.

## Packages, Configuration, And Operations

- Composer: `laravel/reverb:^1.10`, lock exact version. Install/package-publish deliberately rather
  than accepting `install:broadcasting` route scaffolding, because domain routes must remain in the
  Email module and `routes/channels.php` is forbidden by project architecture.
- npm: current compatible stable `laravel-echo` and `pusher-js`, lock exact versions.
- Add `config/broadcasting.php`, `config/reverb.php`, `config/email_live.php`, explicit `.env.example`
  keys, Email provider/routes/controller/events/jobs/models/services/tests, JS/Vite integration,
  scheduler commands, health/config UI, and documentation.
- Reverb secrets are separate from Email provider credentials. Only public app key/host/port/scheme
  enter client assets. Normalize real environment-file permissions to `0640` or tighter before
  adding secrets.
- Bind Reverb with `REVERB_SERVER_HOST=127.0.0.1` and `REVERB_SERVER_PORT=8080`; keep those listen
  settings distinct from public `REVERB_HOST`/`REVERB_PORT`/scheme used by Laravel and the browser.
  Apache terminates TLS and proxies exact `/app` and `/apps` paths. Allowed origins and CSP socket
  origin are exact, never `*` or broad `ws:`/`wss:`.
- Install supervised `nexum-mail-reverb.service` and `nexum-mail-live-worker.service`. The worker runs
  database queue `email-live` with bounded retry/runtime. Install one shared Laravel scheduler
  runner; do not retain the account-1-only cron as the correctness mechanism.

Health includes service status/restarts, loopback socket, public TLS WebSocket handshake,
authenticated private subscription, scheduler/worker heartbeats, oldest pending change, retries,
queue backlog/failed jobs, and a forced polling-fallback smoke. `/up` alone is insufficient.

## Tests

- Commit versus rollback, no pre-commit broadcast, post-commit crash recovery, retry/coalescing,
  duplicate/out-of-order versions, gaps, pruning, and bounded catch-up.
- More than 100 owner/grant/delegation/break-glass or global candidates, frozen high-water cursors
  and audience generations, duplicate-source convergence, late grant insert/update, future-start,
  user reactivation, alternate-authority grant after source time, revocation-versus-delivery
  transaction races, late one-user role membership/global permission enablement, indexed cursor
  plans, and crash recovery before/after each page. An all-revoked or all-future backlog proves each
  query reads at most 100 raw IDs, advances its cursor, and uses the declared index without scanning
  for 100 eligible recipients.
- Exact payload keys plus negative subject/address/body/filename/Ticket/provider/credential/unread/
  canonical/error assertions.
- Exact session-user channel access; inactive/system/cross-user denial; current bounded account
  recipient resolution; owner/grant/delegate/break-glass derivation; start/expiry sweep idempotency.
- Auth route middleware, CSRF, 2FA, throttle, and route-permission exemption.
- Targeted Livewire refresh, boundary-aware `skipRender`, selected-row pinning, new-mail list
  stability, scheduler-down start/expiry behavior, and immediate removal of inaccessible rows.
- Role-wide permission revoke with more than 100 members advances one global authorization
  generation and converges under socket/scheduler loss; deterministic delivery failure cannot seal
  its source, while the 120-second safety refresh still converges the browser.
- Retention pressure beyond 72 hours/10,000 rows preserves unfinished fanout, resume cursors, and
  unacknowledged user changes until sealing/summary.
- Reversed Ticket/Taxonomy/Email multi-stream batches prove the canonical lock order and cannot
  deadlock or leave partial versions.
- More than 100 authorized accounts, folders, delegations, and break-glass rows plus a large mailbox
  prove with query listeners and MySQL/MariaDB/SQLite plan checks that no-change, 15-second, and
  120-second catch-up never calls the full workspace render, reads at most 100 raw navigation rows,
  reads at most 25 list rows plus the exact selection, uses the declared cursor indexes, and never
  performs an unbounded exact count.
- `reverb`, `poll`, degraded, hidden, offline, reconnect, visibility-resume, and HTTP-backoff states;
  no second Alpine runtime.
- User-only unread invalidation remains separate from provider Seen.
- Email plus affected Integration, Notification, Ticket, Taxonomy regressions, `npm run build`, routes,
  config/view cache, and actual Dev private-channel/fallback smoke.

## Deploy And Rollback

Deploy packages and built assets, then additive schema with `umask 0002`. Configure secrets/origins,
test and reload Apache, install scheduler and live worker, start Reverb, rebuild caches, restart Reverb
and long-lived workers, and perform health/private-channel smoke before browser review.

Operational rollback keeps `EMAIL_LIVE_ENABLED=false` and `VITE_EMAIL_LIVE_ENABLED=false`, rebuilds
config and production assets, gracefully stops Reverb, and returns to ordinary Mail polling. The
planned 15-second degraded fallback is not yet implemented and must not be claimed as a current
rollback mode. Keep projection tables; they contain rebuildable invalidation history, not mailbox
state. Database rollback follows application rollback only if explicitly required.

## Done Criteria

- [ ] Order 7 and all listed dependencies are stable before implementation edits their actions.
- [ ] ADR, packages, additive schema, explicit transactional invalidator, private channels, browser
  catch-up, and polling fallback are implemented without content-bearing events.
- [ ] Reverb, Apache, scheduler, worker, CSP/origin, secret-permission, health, outage, and revocation
  behavior pass automated and controlled Dev checks.
- [ ] Email/affected-module tests and asset build pass; docs/TODO/index/Knowledge and
  `HR-2026-08-16-008` are updated; the review remains `Rework Needed` until the enabled runtime is
  completed and then explicitly reviewed by a named human.
