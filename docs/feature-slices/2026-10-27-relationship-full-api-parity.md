# Feature Slices: Full Relationship API Parity

Status: Done On Dev
Date: 2026-10-27
Parent: ../rfc/2026-10-27-relationship-full-api-parity.md
Owner: Codex. Approver and intended reviewer: Svein Tore.

RFC approved by Svein Tore on 2026-10-27; scoped Main promotion expressly authorized.
RL01-RL05 are implemented and verified. Final current-Main candidate: 338 / 7406;
final Dev scoped suite: 29 / 2821. Required handbook publication/read-back is verified.
Human scenarios remain Pending for production; scoped Main promotion is authorized. Recheck shared contributor files
before every slice; Knowledge parity and pre-existing Ticket changes are preserved.

## RL01: Safe reads, form context and authority
Goal/user behavior: discover all supported configuration, lookups, health/history
and eligible records through JSON with equivalent native access.
Scope: route loading, query/resources, contexts, pagination and parent-scoped detail.
Out of scope: writes or external calls.
Data: read existing relationships, links/events and authorized reference records.
Permissions: explicit read/manage/sync-context abilities and native Admin/source rights.
Tests: positive routes and exact projections, narrow context, forbidden/revoked/
other-customer cases, no read-side mutation or credential disclosure.
Documentation: matrix, README/API guide draft.
Done: all mapped reads have typed HTTP evidence and permission tests.

## RL02: Shared configuration and credential mutations
Goal/user behavior: create/update all existing settings and rotate selected secrets.
Scope: shared validation/actions, strict typed input, encrypted command storage,
idempotency, transactional read-back, If-Match and omitted-field preservation.
Out of scope: provider sends or new relationship types.
Data: existing configuration/credentials plus additive command schema; no backfill.
Permissions: native manage/Admin, manage scope and credential scope when applicable.
Tests: every form setting/direction/active prerequisite, stale update, concurrent/
replayed rotation, encryption, rollback and revoked token/actor.
Documentation: exact migration, compatibility and safe credential setup guide.
Done: local write/read-back and native database uniqueness/locking verified on Dev.

## RL03: Preview and guarded Documentation/Knowledge push
Goal/user behavior: preview one exact export and execute once with truthful outcome.
Scope: source/destination/version binding, source authority, durable outbound claim,
existing sync actions and safe immutable operation/event read-back.
Out of scope: generic retry/conflict resolution or provider activation.
Data: command attempt, existing link/event/health; remote effects only via fake
transport during automated verification unless separately authorized.
Permissions: native sync/Admin plus source access, explicit sync/provider scopes,
default-off API outbound switch; preview is not execution authorization.
Tests: success, rejection, cross-client/internal content, unpublished/stale Knowledge,
conflict, disabled capability/switch, timeout/malformed response, no blind resend,
concurrent browser/API attempts and no secret-bearing remote errors.
Documentation: troubleshooting, unknown-outcome reconciliation owner/action.
Done: exact operation outcomes verified without weakening source revision/publication.

## RL04: Existing Ticket and machine-transport parity
Goal/user behavior: existing escalation, replies, statuses, selected attachments and
signed content intake remain compatible and discoverable.
Scope: reuse five Ticket APIs, five ingress operations and complete typed OpenAPI.
Out of scope: duplicate Ticket lifecycle, broad role grants or wire breaking changes.
Data: existing Ticket/message/status/domain records and sync evidence.
Permissions: existing Ticket scope/rights and separate machine HMAC/token authentication.
Tests: published/unpublished Tickets, target/client authority, replay, public/private
reply, mapped workflow, attachment policy, signature/timestamp and divergence.
Documentation: two-instance test plan and explicit bearer/machine auth distinction.
Done: runtime/schema equality and positive contract evidence for every reused operation.

## RL05: Cross-domain verification, handbook and handoff
Goal/user behavior: complete supported coverage is reviewable and operating guidance
matches implemented Dev behavior.
Scope: affected test suites, actual HTTP/schema/read-back checks, native database,
trusted HTTPS, lint/format, Knowledge source sync and BookStack content/access read-back.
Out of scope: Main/production promotion or human sign-off by AI.
Data: scoped fixtures, docs, coverage/test evidence and human-review checklist.
Permissions: preserve handbook audience and provider/runtime activation switches.
Tests: Relationship plus affected Ticket/Documentation/Knowledge/Integration regressions;
two-instance human checks remain explicitly Pending.
Documentation: TODO, RFC/slices, coverage, verification, API/handbook and website handoff.
Done: all matrix rows verified, publication read back or exact blocker/owner recorded,
HR-2026-10-27-RELATIONSHIP-API created Pending with concrete checks and future
Main/production gate; no unsupported claim of complete handbook or production state.
