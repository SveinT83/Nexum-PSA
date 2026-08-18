# Feature Slice: Email Per-User Unread Baselines And Backlog Handover

Status: Done / Human Review Pending
Date: 2026-08-16
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADRs:
- `docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`
- `docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`
Owner: Svein / Codex
Human review: `HR-2026-08-16-003`

## Purpose

Make sparse Nexum **Unread for me** state deterministic at every mailbox-access boundary. A new
shared-mailbox grant or personal delegation must not flood a technician with old history, while mail
that first becomes visible after access starts must be unread for that technician regardless of the
provider's shared `Seen` flag. An explicit, previewed backlog handover may deliberately mark a bounded
historic selection unread for one currently authorized user.

## Baseline And Epoch Contract

- Add one Email-owned account/user read-baseline row with `baseline_message_id`, recorded time,
  source/provenance, and a monotonically increasing `access_epoch`.
- The baseline uses local account-scoped message IDs, not provider unread, `received_at`, mutable
  grant timestamps, or UID. It is the greatest existing account message ID immediately before the
  user first gains a new current View-access epoch.
- A matching user-state row in the current access epoch is authoritative. With no matching row, an
  account message at or below the baseline is read-for-me and a later message is unread-for-me.
- Existing access at migration receives epoch 1 with baseline `0`, preserving today's behavior where
  missing rows are unread. No migration silently marks current work read.
- A transition from no effective View access to View creates/increments an epoch and records a new
  baseline. Editing an existing still-effective grant must not move the baseline. Revocation stops
  access immediately but preserves prior epoch rows as audit/history. A later re-grant starts a new
  epoch; old state rows are ignored until an explicit action writes the new epoch.
- Concurrent or overlapping **ordinary** access sources (owner, shared grant, and delegation) are
  evaluated as one effective View transition. Adding/removing one ordinary source while another
  remains effective does not reset the baseline. Break-glass is excluded from the epoch resolver: a
  sole break-glass actor has no personal unread surface and never creates, increments, or rewrites an
  ordinary user's long-lived baseline.
- Personal owner onboarding records a baseline before any later inbound mail. Shared account grants
  and personal delegations use the same service. User/account disablement does not fabricate a new
  baseline.
- Historical import inserts idempotent current-epoch `is_unread=false` rows for newly imported
  history visible to current viewers. This is a baseline projection, not an acknowledgement, and it
  never changes an existing state or reads provider `Seen`.

## Read Resolution

One Email-owned resolver/scope is used by:

- Mail list and conversation aggregates,
- **Unread for me** filter and counts,
- selected-message/child presentation,
- personal rules and Smart Inbox where they inspect personal unread,
- API resources/filters that expose personal state, and
- explicit mark-read/mark-unread actions.

The implementation must not duplicate a default-true fallback in Blade, PHP collections, or SQL.
Provider `Seen` remains a distinct placement field and never participates in this resolver.

## Backlog Handover

- Requires current account access-management authority, a current View grant for the target user,
  one exact account, and a reason.
- Preview selects an explicit folder/date window and maximum count, defaults to 100 and has a hard
  maximum of 500. It stores a 15-minute immutable message-ID snapshot and authorization/baseline
  fingerprint. Admins without independent mailbox View see counts and scope metadata only, never
  subject, participant, filename, body, or snippet.
- Apply reauthorizes actor, target user, account, placements, baseline epoch, and exact snapshot under
  row locks. It writes only the selected target user's current-epoch `is_unread=true` state with
  `marked_unread_at`; it never changes provider `Seen`, other users, later arrivals, folders, rules,
  Tickets, notifications, AI, or remote operations.
- A changed grant/epoch, revoked user, inaccessible/moved message, expired preview, or partial
  snapshot makes the handover stale before mutation. Repeated apply is idempotent and retains durable
  per-item results.
- The UI describes the exact user, account, folders/date range, selected count, and the fact that
  only Nexum personal unread state changes.

## Data Contract

Add:

1. `email_account_user_read_baselines` unique by account/user with current epoch, baseline message ID,
   source, source reference, recorded actor/time, and timestamps.
2. `access_epoch` on `email_message_user_states`, defaulting to 1 for existing rows, with state
   uniqueness changed from message/user to message/user/epoch so revoke and re-grant history is not
   overwritten.
3. `email_unread_handover_runs` and `email_unread_handover_items` with actor, target, account, exact
   snapshot/fingerprint, bounded scope, status/counters, expiry, idempotency, and sanitized errors.

Baseline and handover events must be durable but metadata-only. They store no mail content or
provider secrets.

## Out Of Scope

- Provider read/unread mutation, automatic acknowledgement on open, cross-account implicit actions,
  conversation-wide acknowledgement (separate slice), bulk grant creation, or unread derived from
  provider `Seen`.
- Deleting old access-epoch rows or using a handover to grant mailbox access.

## Required Verification

- Existing-access migration parity; new grant no-history-flood; new inbound unread; provider Seen
  independence; personal owner and delegation paths; edit-without-reset; revoke/regrant new epoch;
  overlapping access sources; disabled/revoked negative cases; race and idempotency.
- SQL and PHP resolver parity across durable/legacy conversations, filters, counts, pagination, API,
  selected children, personal rules, and Smart Inbox.
- Historical import visible-but-read baseline for every current viewer without overwriting existing
  state; no Ticket/rule/notification/provider side effect.
- Backlog preview/apply caps, stale fingerprint, exact target isolation, current-epoch writes,
  unchanged provider state, later arrivals excluded, double-submit, and metadata-only Admin output.
- Full Email plus affected API, Notification, UserManagement, Ticket, Smart Inbox, and historical
  import regressions.

## Implementation Result

Implemented on the authoritative Dev working copy without running a live migration, provider call,
external action, commit, or push. The central nullable PHP/SQL resolver now owns list, selected-child,
conversation aggregate, filter, count, and explicit personal-state behavior. Ordinary owner/shared
grant/delegation transitions share one account-locked epoch service; personal direct grants fail
closed, break-glass never enters the service, scheduled delegation starts and unattended natural
gaps remain deterministic, and historical imports project insert-only read state in the resolved
current epoch. The metadata-only 15-minute handover UI performs exact bounded preview/apply with
durable sanitized run/item results and strict reauthorization.

The deployment boundary is rolling-schema safe. Before migration `104000` is recorded complete, an
exact legacy state table continues to use its original `(message,user)` row and missing-row-is-unread
meaning. The epoch path opens only after the complete columns/indexes, baseline table, migration
repository seal, and therefore the baseline backfill all exist. Any mixed/partial shape fails closed
before a personal-state write. Account/grant changes remain legal no-op epoch transitions on the
exact legacy schema, while partial transitions roll back. The Mail workspace also suppresses its
advanced-access panel until the complete `103000` delegation/break-glass/event-ledger trio is sealed.

Focused unread/baseline/handover verification passes **13 tests / 118 assertions**. The isolated
rolling-schema compatibility regression passes **4 / 26** and covers the sealed epoch schema,
the exact pre-`104000` legacy schema, mixed-shape fail-closed behavior, and locked current-actor
reauthorization. A read-only check against the authoritative Dev/Plesk MySQL schema resolved
`mode=legacy`, executed the scoped unread count successfully with **428** rows, and rendered the
25-row Mail workspace server-side for user 1 inside a rolled-back transaction. No migration or data
change was performed. Full historical-import plus delegation/break-glass verification passes
**30 / 340**; full
conversation-query plus historical-import regression passes **23 / 257**; and the focused Email
account/personal-owner/workspace regression passes **3 / 44**. Adjacent broad verification passes
**171 / 1,548** across Email/delegation/attachment recovery and **157 / 1,063** across Notification,
User Management, system-actor, and Ticket coverage. PHP lint, targeted Pint, route registration,
metadata-only rendered HTTP assertions, and migration rollback guards pass. Named human review
remains Pending under `HR-2026-08-16-003`.

## Deploy And Rollback

- Run additive migrations after historical namespace migrations, seed/update permissions, clear
  caches, rebuild views with `umask 0002`, and restart long-lived workers.
- Deploying the compatible code while the exact old schema is still active preserves legacy unread
  behavior. Do not treat a partially created `104000` shape as usable, and do not apply this single
  migration out of the documented dependency-ordered Mail migration set.
- Before migration, record current grant/owner/message/state counts. After migration, verify every
  current View user has exactly one epoch-1 baseline at zero and existing user-state rows are epoch 1.
- Rollback must restore the legacy missing-row interpretation before dropping epoch/baseline data.
  Never drop the baseline while code still applies the new resolver.
