# Feature Slice: Email Server-Authoritative Folders And Placements

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-003`

## Goal

Deliver the second Mail full-client foundation slice: provider folder records, provider-authoritative
mailbox placement records, conservative compatibility with existing `email_messages`, and safe
multi-folder polling that does not turn non-Inbox mail into Tickets.

## User-Visible Behavior

Administrators can see discovered provider folders on Email account detail/list surfaces with sync
health and folder role. Existing Inbox behavior remains stable: the technician Inbox still shows only
authorized Inbox mail, not Sent, Archive, Trash, Drafts, or other provider folders.

The system now records where each stored message exists in the provider mailbox. New non-Inbox
placements are stored as mail cache/state but do not run legacy Ticket/Sales/Signal inbound routing.
Provider operation requests are persisted with an idempotency key and visible pending/failed state for
later move/read/delete UI slices.

## Scope

- Add provider folder records for each Email account.
- Backfill one Inbox folder and one placement for every existing Email message.
- Keep `email_messages` as the compatibility message/content record during the shadow period.
- Add placement records with account, folder, path, UID validity, UID, provider flags, provider seen
  state, sync status, and reconciliation timestamps.
- Add remote operation ledger records with idempotency keys, account/folder/placement scope, status,
  request metadata, attempts, and provider response/failure details.
- Discover folders and folder UID state during polling without mutating the provider.
- Poll enabled selectable folders forward-only from their own UIDVALIDITY/UIDNEXT baseline.
- Dispatch Ticket-ingress/rule processing only for Inbox placements on accounts with Ticket ingress.
- Keep the existing `/tech/inbox` and Email Inbox API limited to authorized Inbox messages.

## Out Of Scope

- Full canonical message deduplication or cross-account physical merge.
- Livewire `/tech/mail` conversation workspace.
- User-owned `unread for me`, opened-by history, or presence.
- Move/copy/read/unread/archive/trash/delete UI and provider mutation execution.
- IMAP IDLE/WebSocket transport choice and live invalidation.
- Drafts, sending, Sent reconciliation, and shared composers.
- Historical import, backlog handover, and explicit UID re-baseline tooling.

## Data Touched

- `email_accounts`
- `email_folders`
- `email_mailbox_placements`
- `email_remote_operations`
- `email_messages`
- IMAP polling jobs and store path
- Email Inbox UI/API queries

## Permissions

Existing mailbox content permissions from Feature Slice 1 remain authoritative. Folder discovery and
placement sync run only for active configured accounts. Technician Inbox/API reads still require
global Email read ability plus mailbox View access. Provider mutations represented by operation
ledger rows require a later guarded Action before they can be requested from UI/API.

## Tests

- Migration backfills Inbox folders and placements for existing messages.
- Polling discovers folders and baselines each enabled selectable folder without importing history on
  first run.
- Later polling imports new Inbox and non-Inbox placements from their own folder baselines.
- Only Inbox placements dispatch legacy inbound automation.
- Changed folder UIDVALIDITY fails closed for that folder without importing mail.
- Inbox UI/API do not expose authorized non-Inbox placements.
- Remote operation ledger enforces idempotent operation identity.

## Documentation

Email README, Email Knowledge, TODO, and human-review records were updated. Email Knowledge was
synced from Dev after the slice was completed.

## Done Criteria

- [x] Migration is additive and backfills current rows without provider calls or Ticket/message
  mutation.
- [x] Existing `/tech/inbox` behavior stays Inbox-scoped and account-authorized.
- [x] Folder discovery creates or updates provider folder records with role, UID state, selectable
  flag, sync status, and reconciliation timestamps.
- [x] First poll creates forward-only folder baselines without importing historical mail.
- [x] Subsequent polls import bounded new UIDs per enabled folder and write placements.
- [x] Non-Inbox placements do not run legacy Ticket/Sales/Signal routing.
- [x] Remote operation ledger prevents duplicate pending operations through idempotency keys.
- [x] Focused Email and Notification tests pass on Dev after migration.
