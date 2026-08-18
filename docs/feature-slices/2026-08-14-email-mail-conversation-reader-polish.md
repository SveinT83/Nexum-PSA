# Feature Slice: Email Mail Conversation Reader Polish

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Make the `/tech/mail` reading pane behave like a threaded mail reader while keeping all actions
scoped to one selected provider placement.

## User-Visible Behavior

When a selected mail belongs to a visible account-scoped conversation, the reader shows the
conversation as a compact thread. The selected message is expanded with its body, attachments, AI
summary panel, and selected-message metadata. Other messages in the thread stay collapsed as compact
rows. Clicking another row selects that provider placement, expands it, and makes Reply, Reply All,
Forward, Ticket, AI, read state, provider flag, move, trash, and rule actions apply to that selected
placement.

## Scope

- Replace the separate reader header/body plus bottom Conversation list with one threaded reader
  surface.
- Keep the selected/opened placement as the only expanded thread item.
- Keep collapsed thread rows visible enough to understand sender, recipients, mailbox, folder,
  timestamps, read state, flags, drafts, and reconciliation state.
- Keep the command bar, composer, classification, Ticket link, move, and personal rule panels bound
  to the selected placement.
- Reuse the existing conservative conversation placement query from the list-grouping slice.

## Out Of Scope

- New conversation database tables or durable account-scoped conversation IDs.
- Cross-account conversation merging.
- Bulk conversation actions.
- Automatically marking every message in a conversation read.
- Changing Ticket-number `TD-...` correlation, Ticket capture, provider folder authority, or IMAP
  synchronization behavior.

## Data Touched

- Existing `email_mailbox_placements`, `email_messages`, attachments, classifications, Ticket links,
  and sent reconciliations are read for the selected visible thread.
- No database rows, migrations, settings, provider operations, queues, or external systems are
  changed by this slice.

## Permissions

The threaded reader uses the already authorized `conversationPlacements()` result. It does not widen
mailbox access, does not disclose inaccessible account copies, and does not let Ticket access grant
Mail access. Mutating actions remain authorized through the selected placement exactly as before.

## Tests

- A two-message RFC-thread conversation renders as one list row.
- Selecting the latest message expands only the latest message body while the older message is shown
  collapsed.
- Selecting the older placement expands that older body and updates the selected placement.
- Same `Message-ID` values in different accounts remain separate conversation rows.

## Automated Verification

- `php -l app/Modules/Email/Views/Livewire/Tech/mail-workspace.blade.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- Focused conversation reader/list regressions passed with 3 tests and 23 assertions.
- Full `EmailModuleTest.php` passed with 136 tests and 1120 assertions.
- `php artisan optimize:clear` passed.
- `php artisan view:cache` passed.
- `php artisan knowledge:sync-docs --module=Email --push` processed 1 chapter and 1 article, and
  queued the BookStack push.
- One `php artisan queue:work --once --queue=default --tries=1` pass completed without CLI errors.
- `php artisan queue:failed` reported no failed jobs.
- `git diff --check` reported only pre-existing CRLF working-copy warnings in unrelated files.

## Documentation

- Email Knowledge describes the threaded reader and selected-placement action scope.
- Email README describes the compact reader thread behavior.
- TODO records this slice as implemented while leaving durable account-scoped conversation migration
  as a later careful slice.
- Human review tracks manual verification under `HR-2026-08-14-003`.

## Done Criteria

- The reader shows a compact conversation thread when multiple placements are visible.
- Only the selected/opened placement renders body and attachments.
- Clicking another visible thread row selects that placement.
- The command bar and panels still act on the selected placement only.
- Focused and full Mail tests pass on Dev.
