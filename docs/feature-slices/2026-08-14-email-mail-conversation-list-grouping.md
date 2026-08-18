# Feature Slice: Email Mail Conversation List Grouping

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Make the `/tech/mail` message list behave like an email client by showing conversations instead of
one separate row for every placement in the same RFC thread.

## User-Visible Behavior

The message list now groups the current filtered mailbox/folder scope into account-scoped
conversation rows. The newest matching placement in the conversation becomes the visible row. When a
conversation has multiple matching messages, the row shows a compact message-count badge and
aggregate unread badges.

Clicking the row opens the newest placement. The reading pane still shows the existing Conversation
section, where the technician can switch to earlier messages in the same thread.

## Scope

- Group list rows by the existing conservative Email conversation key.
- Prefix the key with `account_id` so the same `Message-ID` in another mailbox remains a separate
  conversation.
- Keep current mailbox/folder/search/filter authorization and scoping before grouping.
- Show conversation count, personal unread count, and mailbox unread count on grouped rows.
- Keep the selected conversation row highlighted when the currently opened placement is an older
  message in that row.

## Out Of Scope

- New conversation database tables or migrations.
- Backfilling durable account-scoped conversation IDs.
- Merging conversations across mailboxes.
- Subject-only thread merging beyond the existing fallback key.
- Changing Ticket-number `TD-...` correlation, Ticket link behavior, or provider folder placement
  authority.

## Data Touched

- Existing `email_mailbox_placements` and related `email_messages` are read.
- No database rows, provider state, settings, or migrations are changed by this slice.

## Permissions

Conversation grouping runs after the existing mailbox View authorization and current
mailbox/folder/search/list filters. It does not broaden visibility, and Ticket access still does not
grant Mail access.

## Tests

- Two messages in the same RFC thread render as one conversation row with a two-message badge, and
  the reading pane still exposes both messages through the Conversation section.
- Messages with the same `Message-ID` in different authorized accounts render as separate
  conversation rows.

## Automated Verification

- `php -l app/Modules/Email/Livewire/Tech/MailWorkspace.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- Focused conversation list regressions passed with 2 tests and 11 assertions.
- Full `EmailModuleTest.php` passed with 136 tests and 1115 assertions.
- `php artisan optimize:clear` passed.
- `php artisan view:cache` passed.
- `php artisan knowledge:sync-docs --module=Email --push` processed 1 chapter and 1 article, and
  queued the BookStack push.
- One `php artisan queue:work --once --queue=default --tries=1` pass completed without CLI errors.
- `php artisan queue:failed` reported no failed jobs.
- `git diff --check` reported only pre-existing CRLF working-copy warnings in unrelated files.

## Documentation

- Email Knowledge describes the conversation-grouped message list.
- TODO records this slice as implemented while leaving durable account-scoped conversation migration
  as a later careful slice.
- Human review tracks manual verification under `HR-2026-08-14-002`.

## Done Criteria

- The Mail list header reports conversations.
- Multiple placements in the same account-scoped RFC thread appear as one row.
- Cross-account messages do not merge merely because they share a Message-ID.
- Selecting the row still opens a real provider placement and keeps the reading pane actions scoped
  to that placement.
- Focused and full Mail tests pass on Dev.
