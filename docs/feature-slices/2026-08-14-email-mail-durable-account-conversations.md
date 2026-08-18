# Feature Slice: Email Mail Durable Account Conversations

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Persist the account-scoped conversation identity behind `/tech/mail` so conversations no longer
depend only on per-request Livewire grouping.

## User-Visible Behavior

The Mail workspace should look the same as the previous conversation-list and conversation-reader
slices. The difference is that inbound storage, IMAP move projection, and Ticket conversation links
now write and reuse a durable `email_conversations` row per mailbox account and RFC thread.

## Scope

- Add `email_conversations` with account-scoped `conversation_key`, latest/first message pointers,
  placement counts, provider-unread count, attachment signal, and projected timestamps.
- Add nullable `email_conversation_id` to mailbox placements and Ticket conversation links.
- Backfill existing placements and compatible Ticket conversation links during migration.
- Project conversations whenever `StoreInboundMessage` upserts a placement.
- Preserve the conversation when provider move operations create a new mailbox placement UID.
- Make `/tech/mail` grouping and reader use `email_conversation_id` when available, with the old
  conservative header key as fallback.
- Keep `TD-...` Ticket correlation and existing message-level Ticket compatibility links unchanged.

## Out Of Scope

- Moving Email category/tag assignments from message scope to conversation scope.
- Changing provider folder/read/flag authority.
- Automatic conversation-wide read actions.
- Cross-account conversation merging.
- Subject-only thread expansion beyond the current fallback key.
- Ticket auto-routing by later linked conversation arrivals.

## Data Touched

Migration `2026_08_14_100000_create_email_conversations_table.php` creates
`email_conversations`, adds nullable `email_conversation_id` to `email_mailbox_placements` and
`email_ticket_conversation_links`, backfills current placements by account plus conversation key,
and backfills compatible Ticket links.

## Permissions

No permission names or grants change. Conversation creation is an Email data projection behind the
existing mailbox View/Organize/Send/Ticket guards. Ticket access still does not grant Mail account
access.

## Tests

- Inbound storage projects a durable conversation for root/reply messages and keeps identical
  message IDs in two accounts separated.
- Provider folder delete pre-move keeps the new target placement in the same durable conversation and
  refreshes the active placement count.
- Ticket conversation linking stores the durable conversation ID while preserving the legacy
  `conversation_key`.
- Existing conversation list account-isolation regression still passes.

## Automated Verification

- `php -l` passes for the new model, projector, migration, updated Mail workspace, updated folder
  projector, updated remote operation runner, updated Ticket link action, and Email feature tests.
- Focused durable conversation regressions passed with 4 tests and 36 assertions.
- `HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php` passed with 139
  tests and 1176 assertions.
- `HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/InboundAutomationTest.php` passed with
  14 tests and 81 assertions.
- `umask 0002; HOME=/tmp php artisan migrate` applied the migration on Dev after correcting the
  backfill attachment lookup to use the existing `email_attachments.message_id` column.
- Dev backfill verification found 141 conversations and 462 of 462 mailbox placements linked to a
  conversation. Dev currently had zero Ticket conversation links to backfill.
- `umask 0002; HOME=/tmp php artisan migrate:status | tail -12` shows
  `2026_08_14_100000_create_email_conversations_table` as batch 85 `Ran`.
- `umask 0002; HOME=/tmp php artisan optimize:clear` passed.
- `umask 0002; HOME=/tmp php artisan view:cache` passed.
- `umask 0002; HOME=/tmp php artisan knowledge:sync-docs --module=Email --push` processed one
  chapter and one article and queued the BookStack push.
- A pre-existing/retried `FetchImapAccount` failed-job was investigated as `MaxAttemptsExceeded`,
  retried, processed by the running worker, and `HOME=/tmp php artisan queue:failed` then reported
  no failed jobs.
- `git diff --check` reported only pre-existing CRLF working-copy warnings in unrelated files.

## Documentation

- Email module README describes the new `EmailConversation` projection and new placement/link
  columns.
- TODO records this slice as implemented while leaving conversation-scoped taxonomy migration and
  later automatic routing for future slices.
- Human review tracks manual verification under `HR-2026-08-14-006`.

## Done Criteria

- Existing Mail rows continue to render as account-scoped conversations.
- New inbound root/reply messages share a durable conversation inside one mailbox account.
- Matching messages in different mailbox accounts do not share a durable conversation.
- Provider moves keep the target placement in the same conversation.
- Ticket conversation links gain a durable conversation pointer without losing old compatibility
  behavior.
- The migration applies on Dev and focused Mail tests pass.
