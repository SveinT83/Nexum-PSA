# Feature Slice: Email Mail Reporting And Advanced Productivity

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `../adr/2026-08-16-email-privacy-preserving-reporting-and-personal-productivity.md`
Owner: Svein / Codex
Human Review: `HR-2026-08-16-032`

## Goal

Complete Mail with personal saved views, favorites, snooze/reminders, explicit composer snippets, safe
keyboard workflows, and permission-filtered operational reports backed by local bounded aggregates.
No feature may create a competing provider state, copy mailbox content into analytics, or expose
private/personal mail or individual performance.

## Dependencies

This is the final completion-order slice. It starts only after orders 1-31 are stable, with special
dependency on canonical/read projections, unread baselines, provider reconciliation, live
invalidation, shared collaboration, rules/AI outcomes, Ticket boundaries, malware/search, lifecycle,
entity matching, and provider drivers. Re-read the final Report registry and permissions before
cross-module integration.

## User-Visible Behavior

### Personal productivity

- A user can save the current allowlisted Mail account/folder/view/filter/search/sort definition with
  a private name, pin/reorder it, open it from the left pane, rename it, or delete it.
- Favorites provide quick links to currently authorized accounts, folders, saved views, and exact
  conversations. Revoked or deleted targets disappear; a favorite never preserves access.
- `Snooze for me` hides one account-conversation from that user's normal views until a chosen time.
  It remains visible under `Snoozed`. New inbound mail wakes it by default and remains unread for the
  user. Snooze never changes provider Seen/flags/folder, another user, assignment, or Ticket state.
- A personal reminder may be attached to an authorized conversation. At due time it creates one
  private canonical notification and a visible Follow-up indicator. The user can complete, defer, or
  cancel it. It does not send mail or create a Task automatically.
- Private composer snippets insert sanitized authored text only after an explicit click/shortcut.
  They do not select recipients, source account, subject, attachment, signature, send, rule, or AI.
- Documented keyboard shortcuts navigate the current bounded list and invoke existing guarded actions.
  They never fire inside an editor/input/select/modal, are user-disableable, and have accessible
  non-keyboard equivalents.

### Reporting

Register Email-owned reports in the Report hub:

- **Mail operations:** inbound/outbound volume, unique conversations, currently authorized backlog
  and age buckets, evidence-supported first-response duration buckets, Sent reconciliation coverage,
  and missing-evidence counts;
- **Mail automation outcomes:** rule attempts/outcomes/retries/undo, Smart Inbox suggestion decisions,
  restricted auto-reply gated outcomes, malware/search projection health, and governed AI usage/cost
  totals from Integration without content; and
- **Mail provider health:** sync/reconciliation freshness, stale/blocked/conflict counts, queue age,
  folder/account coverage and binding/driver capability health, with configuration-only details
  separated from content aggregates.

Date range, account kind, exact currently accessible accounts, coarse direction, outcome, and safe
status are supported filters. Reports label timezone, included/excluded evidence, aggregate lag,
retention window, and incomplete data. They never claim Ticket SLA or individual productivity.

## Data Touched

Reserve migrations:

- `2026_08_16_176000_create_email_personal_productivity.php`;
- `2026_08_16_177000_create_email_reporting_facts_and_aggregates.php`; and
- `2026_08_16_178000_create_email_reporting_rebuild_runs.php`.

### Personal productivity tables

Create `email_saved_views`:

- owner user, name, schema version, allowlisted canonical query JSON/hash, sort, pinned/order, last-used
  time and timestamps;
- no cached results, message IDs, content, inaccessible counts, raw SQL, wildcard model/class names,
  routes, or arbitrary Livewire properties;
- unique owner/name and hard maximum 50 active saved views per user.

Create `email_user_favorites`:

- owner, target type (`account`, `folder`, `conversation`, `saved_view`) and exact target FK/ID,
  order/timestamps;
- model allowlist and unique owner/target; query-time authorization always applies.

Create `email_conversation_user_productivity`:

- user/account/conversation, snoozed-until, captured conversation generation, wake-on-new-message,
  reminder-at/status (`pending`, `due`, `completed`, `cancelled`), reminder generation, due-delivery key,
  completed/cancelled times and timestamps;
- one row per user/account/conversation, current access epoch, no provider or other-user state.

Create `email_user_composer_snippets`:

- owner, private name/shortcut, sanitized HTML/plain text, active/order, content hash and timestamps;
- hard maximum 100, bounded body, no attachment/recipient/subject/account/send/AI/rule fields.

Create append-only `email_productivity_events` for snooze/wake/reminder due/defer/complete/cancel and
safe snippet metadata transitions. Store IDs, generations, idempotency, safe reason and occurred time;
never store saved search text, snippet body, message content, recipient, or provider error in events.

### Reporting tables

Create `email_reporting_fact_events`:

- fact schema/type, account, optional self-only user, optional conversation, source type/ID,
  occurred date/time, integer count/duration/bytes/cost-unit values, allowlisted coarse dimensions,
  source projection version and unique idempotency key;
- no address/domain/name, subject, search query, snippets, bodies, headers, attachment/note/draft text,
  Ticket detail, canonical/raw/storage identity, provider response, credential or exception;
- immutable correction events rather than in-place history edits.

Create `email_reporting_daily_aggregates`:

- scope (`account` or `user`), exact account/user, local reporting date/timezone, metric, safe dimension
  hash/JSON, count/sum/min/max and bounded response/age histogram buckets;
- aggregate generation, fact high-water, rebuilt/corrected time and unique scope/date/metric/dimension;
- no actor grouping for shared-account/team reporting.

Create `email_reporting_projection_states` with high-water, oldest/newest fact, lag, health, rebuild
generation and safe error. Raw facts default to 90-day retention and daily aggregates to 24 months,
both installation-lowerable. Lifecycle deletion/revocation removes identifying scope and rebuilds
affected aggregates; no report is a legal hold or hidden archive.

### Rebuild workflow

Create bounded `email_reporting_rebuild_runs` and items:

- actor, exact account/date/metric scope, status, cap, frozen source high-water/count/fingerprint,
  progress, generation, expiry, cancellation and sanitized errors;
- preview is read-only and reports counts/estimated facts without content;
- apply requires current authority, processes stable chunks, is idempotent/resumable and never calls a
  provider, AI model, search extractor, rule, Notification, Ticket, or outbound action;
- default 31 days/100,000 source events and hard 366 days/1,000,000; cap-plus-one fails honestly and
  requires narrower scope; installation may lower bounds.

No migration automatically backfills history. Initial deploy starts facts prospectively. Historical
reporting requires explicit preview/apply per account/date and preserves an honest coverage start.

## Reporting Semantics And Privacy

Email emits facts explicitly after committed domain transitions; broad Eloquent observers are
forbidden. A queue projector on a dedicated bounded queue aggregates facts idempotently, and a
scheduled sweeper catches missed dispatch. Report delay never blocks normal Mail actions.

First-response duration uses an evidence-supported inbound generation followed by the first
reconciled/accepted outbound in the same exact account-conversation. Ambiguous correlation, repaired
or missing time evidence, outside-provider replies without verified Sent projection, and access/
lifecycle gaps are excluded and counted as missing evidence. Reports never call the result SLA.

Authorization rules:

- Report hub entry requires `report.view`.
- Content-derived account metrics additionally require current ordinary View for each exact account;
  inaccessible accounts are absent, not zero-labelled.
- Personal-account data is owner-only except an explicitly active ordinary delegation under the
  personal-mail policy; configuration Admin and break-glass-only access do not qualify.
- User-scope facts and personal productivity reports are visible only to that same active user.
- Provider-health configuration details require `email.account_manage` plus
  `email.mailbox_sync_manage`; if content counts are shown, ordinary View is also required.
- API requires `report.read` and `email.read` ceilings plus the same exact runtime decisions.
- Export, scheduled delivery, client portal publication, external BI, and cross-account comparison are
  not included.

No report groups by responder, sender/recipient/domain, presence, opened-by actor, note author,
reviewer, or break-glass actor. Assignment/watch/review may supply account-level queue/outcome counts
only. Small cohorts are not exposed through drill-down; exact operational lists reuse normal
permission-filtered Mail queries instead of aggregate reconstruction.

## Actions, UI, And API

Implement Email actions for canonicalizing/saving/applying views, favorites, snooze/wake, reminder
transitions, snippet insert, fact record/correct, aggregate projection, rebuild preview/apply/cancel,
and retention cleanup. Every productivity action reauthorizes the exact current account/conversation
and current access epoch. New inbound wake happens in the committed projection transaction and emits
one personal live invalidation; it never marks read.

Add dense `Saved views`, `Favorites`, and `Snoozed` sections to Mail's left navigation. Keep secondary
sections collapsed by default and hide empty/unavailable controls. The reader's follow-up control is
compact; the composer snippet picker is searchable and explicit. Existing praised mobile stacking is
preserved.

Email-owned report controllers, queries, definitions, views and tests live in the Email module and
register through `config/reports.php`; Report retains the shared hub. Report pages use shared
Bootstrap components, safe summary cards/tables, clear evidence/lag labels, accessible charts without
color-only meaning, bounded pagination, and no raw-mail drill-down for metadata-only operators.

Expose versioned API CRUD/use endpoints for the user's saved views/favorites/snooze/reminders/snippets
and read-only report summary/series endpoints. Never accept raw SQL, arbitrary column/operator names,
unbounded date ranges, result caching, or caller-supplied user identity. All writes are idempotent and
all nested resources return non-enumerating denials.

## Out Of Scope

- Scheduled/delayed send, undo-send, automatic Task/Ticket creation, bulk provider mutation, automatic
  snippet insertion, automatic recipients, or new unattended AI.
- External analytics/BI/warehouse, cross-tenant comparisons, sender/domain analytics, content search
  reporting, public/client reports, scheduled report email, PDF/CSV export or portal publication.
- Technician leaderboards, scores, rankings, utilization, performance review, presence/open tracking,
  keystroke/time-on-message telemetry, or historical break-glass content analytics.
- Shared saved views/snippets or generic Report builder/delivery; these require their own policy.

## Tests

- Saved-view schema allowlist/version migration, URL/state parity, renamed/deleted/revoked targets,
  max limits, unsafe operator/property/raw SQL rejection, personal isolation and no result snapshot.
- Favorite type binding, ordering, deleted target, access revocation and cross-account no-leak.
- Snooze/wake timing, new inbound generation, no wake on unrelated/provider flag change, Snoozed view,
  unread/Seen/assignment/Ticket isolation, DST/timezone, scheduler loss and idempotent catch-up.
- Reminder due/defer/complete/cancel, one canonical Notification, revoked/offboarded user, no Task/send,
  and live invalidation; snippet sanitization/private scope/explicit insert and no recipient/subject/
  attachment/signature/send mutation.
- Keyboard focus/modal/editor safety, repeat/debounce, permissions, non-keyboard equivalents,
  screen-reader labels, desktop/mobile and no second Alpine runtime.
- Fact transaction/rollback, idempotency/correction, explicit allowlist and negative content/address/
  domain/search/note/draft/provider/credential fields; aggregate ordering, timezone, lag, retention and
  deletion correction.
- Response-evidence semantics across inbound/outbound/Sent reconciliation, ambiguous/missing/repaired
  time, duplicate provider copies and multiple conversations/accounts; no SLA claim.
- Owner/shared/delegate/personal/config-only/break-glass/API matrices, access removal, small-cohort/
  drill-down protection, no inaccessible zero/count leakage, and no per-user leaderboard.
- Rebuild preview/cap-plus-one/fingerprint/expiry/apply/resume/cancel/concurrency, zero provider/AI/rule/
  Notification/Ticket/send effects and honest prospective coverage.
- Email and Report registry/routes/API/UI plus affected Integration, Notification, Ticket, Search,
  lifecycle/offboarding, provider-driver, collaboration, rules/AI and retention regressions.

## Documentation And Operations

Update Email README/Knowledge, Report README/Knowledge, UserManagement privacy/offboarding docs,
Notification settings, API/OpenAPI, reporting/privacy/retention/rebuild runbooks, TODO, completion
index and `docs/human-review.md`.

Deploy additive migrations with `umask 0002`, seed/report-register permissions, rebuild caches/views/
assets, and restart Email/default/report workers plus scheduler. Verify projection lag, retention,
failed jobs and a preview-only rebuild before any historical apply. Deployment creates no favorites,
views, snoozes, reminders, snippets, historical facts, reports, notifications, provider calls, AI
workloads, or sends.

Rollback disables projector/reminder dispatch and UI first while preserving facts/productivity state.
Schema down refuses while notifications, lifecycle/audit, rebuild or retained aggregate references
exist. No rollback may re-enable a revoked user or replay a reminder/send.

## Done Criteria

- [ ] All completion orders 1-31 are stable and final authorization/lifecycle/report boundaries are
  reused.
- [ ] Saved views, favorites, snooze/reminders, snippets and shortcuts are personal, reversible,
  permission-filtered and independent of provider/other-user state.
- [ ] Reports use local bounded content-free facts/aggregates, current ordinary Mail authority and no
  individual performance grouping.
- [ ] Rebuild, correction, lag, retention, deletion, revocation and missing-evidence behavior are
  implemented and observable.
- [ ] Focused, affected-module, API, UI/accessibility, scheduler/worker, retention and controlled Dev
  verification pass.
- [ ] Docs/TODO/index/Knowledge and `HR-2026-08-16-032` are updated; human review remains Pending.
