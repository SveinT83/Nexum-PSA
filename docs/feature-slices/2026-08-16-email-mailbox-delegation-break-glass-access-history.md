# Feature Slice: Email Mailbox Delegation, Break-Glass, And Access History

Status: Done
Date: 2026-08-16
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADRs:
- `docs/adr/2026-08-11-email-owned-mail-client-domain.md`
- `docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`
Owner: Svein / Codex
Human review: `HR-2026-08-16-002`

## Purpose

Complete the personal-mailbox privacy contract by adding explicit, expiring owner delegation and a
separate emergency break-glass path. Ordinary Email/account administration must continue to reveal
only configuration and sanitized health. It must never become implicit personal-mailbox content
access.

The slice also adds a metadata-only access history so activations, revocations, expiry, and sensitive
break-glass use can be reviewed without copying message content into audit records.

## Authorization Contract

- A personal mailbox owner retains ordinary access through ownership.
- Only that active owner may create or revoke an ordinary delegation for the owned personal mailbox.
  Administrators cannot create a personal delegation on the owner's behalf.
- A delegation names one active human user, exact `view`, `organize`, `send`, and optional raw-source
  operations, a reason, a start, and an expiry. It cannot grant an operation the owner does not
  currently hold and cannot exceed 31 days. Renewal creates a new record and audit event; no record
  extends itself silently.
- Shared/system primary grants remain the existing account grant matrix and are not replaced by
  personal delegations.
- Break-glass requires the distinct `email.break_glass_activate` permission, an active human actor,
  one active personal account, a reason, exact operations, and an expiry no more than 120 minutes.
  The initial slice permits content view/search, allowed attachment download, and separately guarded
  raw-source view. It never permits send, provider organize/mutation, rule publication, export,
  permanent deletion, account configuration, provider credentials, or delegation management.
- An active break-glass access may be revoked by its activating actor, the current active personal
  mailbox owner, or another active human security operator holding `email.break_glass_activate`.
  `email.break_glass_audit` is read-only and never grants revocation authority by itself.
- Raw source additionally requires `email.raw_source_view`. Metadata-only access history requires
  `email.break_glass_audit`. Mailbox configuration alone grants neither permission.
- Every UI, API, search, attachment, raw-source, Livewire, and queued action uses the same current
  `MailboxAccess` decision. Disabled users/accounts, revocation, and expiry take effect on the next
  decision; a queued action must reauthorize and cannot rely on the original request.
- Account-list/search counts and private events remain hidden until an active delegation or
  break-glass record is effective. Ticket linkage never widens access.

## Data Contract

Add Email-owned records for:

1. `email_mailbox_delegations`: account, owner, delegate, exact operation booleans, reason,
   starts/expires, creator, revocation actor/reason/time, and timestamps. History is retained; only
   one current effective delegation per account/delegate is allowed by the locked action.
2. `email_break_glass_accesses`: account, actor, exact allowed operations, reason, starts/expires,
   revocation actor/reason/time, owner/security notification timestamps, and timestamps.
3. `email_mailbox_access_events`: append-only metadata containing account, actor, affected user,
   delegation/break-glass reference, stable event/operation/resource type, opaque numeric resource
   ID where required, reason code, sanitized metadata, and occurrence time.

No event stores subject, sender/recipient, filename, snippet, body, raw header/source, search term,
credential, provider response, attachment bytes, AI input/output, or Ticket content.

## Workflow And UI

- Mail exposes a small **Mailbox access** page to owners of personal accounts. It previews the exact
  delegate, operations, reason, and expiry; lists current/recent delegations; and supports explicit
  revocation. Controls disappear when ownership or authority is lost.
- Email Admin exposes a separate **Emergency mailbox access** page only to break-glass operators.
  It states the privacy impact, requires typed account confirmation and reason, limits duration and
  operations, and never previews mailbox content before activation.
- A metadata-only **Mailbox access history** page is separate and requires
  `email.break_glass_audit`. The account owner may see activation/revocation/expiry events affecting
  their mailbox without gaining audit access to other accounts.
- Active break-glass access is prominently labelled in Mail and includes its expiry and a revoke
  action. It cannot look like an ordinary permanent grant.
- Sole break-glass access exposes no personal unread badges, filters, counts, opened receipt, or
  mark-read/mark-unread action and never starts or resets an ordinary unread access epoch.
- Activation notifies the mailbox owner and active security recipients holding
  `email.break_glass_audit` after commit through the existing internal Notification boundary. The
  notification contains actor, account, operations, reason, and expiry but no mail content. The
  first slice always notifies; delayed legal notice requires a later separately reviewed policy.

## Audit Use Events

- Create immutable events for delegation created/revoked/expired-at-use; break-glass
  activated/revoked/expired-at-use; mailbox/message view; attachment download; raw-source view; and
  search execution while break-glass is the effective access source.
- Repeated render/authorization checks do not themselves create audit noise. A use event is recorded
  only at the actual controller/Livewire action boundary and is idempotent for the same access,
  resource, operation, and short request window.
- Audit failure blocks raw source, attachment download, and search under break-glass. It may not
  silently grant content when durable evidence cannot be written.

## Out Of Scope

- Bulk export, legal-hold access, delayed owner notice, second approval, permanent deletion, provider
  mutation, send-on-behalf, external SIEM export, public API activation, group delegation, or
  impersonation.
- Replacing shared mailbox primary grants or implementing unread backlog handover (next slice).

## Required Verification

- Personal owner/delegate/admin-negative matrix for each operation; no admin content by configuration.
- Maximum duration, no overlap/renewal mutation, expiry, immediate revocation, user/account disable,
  cross-account isolation, forged route binding, and execution-time reauthorization.
- Break-glass permission matrix, exact account confirmation, no send/organize/export, raw-source
  double guard, hidden inactive/expired access, and no account existence leakage.
- Mail list/show/search/attachment/raw/Livewire and API negative tests; Ticket linkage must not widen
  access.
- Metadata-only event assertions and forbidden-content scans; audit-write failure must fail closed at
  sensitive use boundaries.
- Owner/security Notification tests with after-commit behavior, deduplication, revocation, and no
  mail content.
- Desktop/mobile/keyboard/focus, visible emergency state/expiry, and immediate disappearance after
  revocation.
- Full Email plus affected Notification, UserManagement, Ticket, API, and permission regressions.

## Deploy And Rollback

- Additive migrations and permission/role seeds are required. Pause/restart long-lived workers when
  serialized authorization code changes, clear caches, rebuild views with `umask 0002`, and verify
  Notification plus Email worker health.
- Existing accounts receive no delegation or break-glass record. The capability is unavailable
  until permissions are seeded and explicitly assigned.
- Rollback first revokes active records and verifies no queued work relies on them. Dropping audit
  history requires an explicit retention/export decision and is not an ordinary application rollback.
