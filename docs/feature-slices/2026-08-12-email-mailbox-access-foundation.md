# Feature Slice: Email Mailbox Access Foundation

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-002`

## Goal

Deliver the first Mail full-client foundation slice: safe personal/shared/system mailbox ownership,
explicit mailbox grants, Ticket-ingress isolation, account-scoped Email rules, and scoped Inbox/API
read paths.

## User-Visible Behavior

Admins choose whether an Email account is shared, personal, or system-owned. Personal accounts require
one owner and cannot be enabled for automatic Ticket ingress. Shared and system mailboxes expose a
controlled user grant matrix for View, Organize, and Send. Account configuration, health testing, and
grant administration remain Admin actions, but they do not by themselves grant mailbox content access.

Technicians and API users see only unread Inbox records from accounts they may view. Spam/archive,
delete, and manual polling require both the global Email manage permission and the mailbox Organize
grant. Inbound Email notifications use the same mailbox access decision.

Email rules are scoped to selected shared/system mailboxes with Ticket ingress enabled. Existing
legacy rules are migrated to explicit account pivots for existing accounts, and personal accounts do
not inherit legacy rules or default Ticket routing.

## Scope

- Add mailbox `account_kind`, `owner_id`, and `ticket_ingress_enabled` account policy fields.
- Add explicit per-user mailbox grants for `view`, `organize`, and `send`.
- Add Email rule to account pivot records and Admin rule account selection.
- Add one shared `MailboxAccess` service used by Tech Inbox, Email Inbox API, attachment downloads,
  spam/archive/delete actions, manual polling, and inbound notification recipients.
- Preserve current shared/system Ticket routing for existing accounts through additive backfill.
- Force personal-account Ticket ingress off through the Admin account form and runtime policy.
- Keep workflow default sender lookup from selecting personal accounts.

## Out Of Scope

- Full `/tech/mail` Livewire conversation workspace.
- Provider folder discovery and canonical mailbox placements.
- Personal rule draft/publish builder.
- Break-glass, delegation expiry, group grants, and access-history screens.
- Drafts, sending, Sent reconciliation, and provider folder mutation UX.
- AI summaries, cleanup, or automatic replies.

## Data Touched

- `email_accounts`
- `email_account_user_grants`
- `email_rule_accounts`
- `email_rules`
- Existing Email Inbox UI/API queries and notification recipient resolution.

## Permissions

Existing global permissions remain request ceilings:

- `email.inbox_view` is required before any mailbox `view` grant can expose content.
- `email.inbox_manage` is required before any mailbox `organize` grant can mutate local/provider
  state or queue manual polling.
- `email.account_manage` still governs account configuration and grant administration.
- API abilities `email.read` and `email.update` continue to gate API routes, but account grants now
  further restrict the returned or mutated mailbox data.

## Tests

- Email module feature suite covers scoped UI/API reads, inaccessible show 404, personal-owner access,
  personal no-Ticket-ingress, Admin grant persistence, account-scoped rules, spam rule scoping, and
  existing Ticket routing regressions.
- Inbound automation suite covers preclassification UI with account scope and existing trusted-source
  behavior.
- Notification inbound Email suite covers mailbox-grant filtered notification recipients.

## Documentation

Email README, Email Knowledge, Integration API Knowledge, TODO, and human-review records are updated.
Email and Integration Knowledge were synced on Dev and BookStack push jobs were queued.

## Done Criteria

- [x] Migration is additive and backfills existing accounts/rules without remote IMAP, read-state,
  Ticket, or message mutation.
- [x] Admin can create/edit shared/system accounts with explicit user grants.
- [x] Admin-created personal accounts require one owner and force Ticket ingress/default workflow
  scopes off.
- [x] Inbox UI, show, attachment download, spam/archive, delete, API list/show/spam, and manual poll
  use the same mailbox authorization.
- [x] Inbound notifications do not go to users without mailbox access for unrouted mail.
- [x] Email rules are scoped to selected shared/system Ticket-ingress mailboxes.
- [x] Personal mail is stored but not automatically classified into Signals, Email rules, Sales, or
  Tickets by the legacy inbound automation path.
- [x] Existing Ticket-key/header/default Ticket routing remains covered for existing shared/system
  account behavior.
- [x] Email and Notification test suites pass on Dev after the migration.
