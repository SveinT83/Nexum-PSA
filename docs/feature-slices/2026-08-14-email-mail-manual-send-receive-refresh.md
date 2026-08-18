# Feature Slice: Email Mail Manual Send/Receive And Folder Refresh

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Give technicians an Outlook-style manual sync action from `/tech/mail` without creating a second
mail-fetching path beside the existing IMAP polling job.

## User-Visible Behavior

The Mail message-list header shows a compact `Send/receive` button when the technician can organize
at least one mailbox. Clicking it queues the existing `FetchImapAccount` job for every active
mailbox the user may organize.

When a folder is selected and the technician can organize that folder's mailbox, the Folders header
also shows a refresh icon. Clicking it queues a refresh for the selected folder's mailbox. The queued
job still discovers the account's provider folder list before fetching mail, so folders renamed,
created, or deleted in another IMAP client are reconciled through the same provider-authoritative
sync path as normal polling.

Compose, Reply, Reply All, Forward, and provider-draft editing continue to use the shared
`mail-composer-form` partial and the same Mail AI toolbar when AI drafting is allowed.

## Scope

- Add message-list header manual `Send/receive` for organize-authorized mailboxes.
- Add selected-folder `Refresh folder` action that queues sync for the folder's account.
- Reuse `FetchImapAccount` and the configured Email batch size.
- Keep IMAP work out of the Livewire request; the UI only queues jobs and reports status.
- Add regression coverage for authorized mailbox selection and view-only denial.

## Out Of Scope

- Running IMAP fetch synchronously inside the browser request.
- Historical import, catch-up import, or UID re-baseline controls.
- A separate send outbox; normal SMTP sends still occur when the user presses Send in the composer.
- Per-folder-only IMAP fetch that skips account-wide folder discovery.
- Replacing the current message list with grouped conversation rows.

## Data Touched

- Existing `common_settings` row `emailhub.batch_size` is read.
- Existing `email_accounts` and `email_folders` authorization/scope data are read.
- Existing queue receives `FetchImapAccount` jobs.

No migration or new table is required.

## Permissions

Manual sync uses the same boundary as the existing Inbox `Check now` action: global
`email.inbox_manage`, mailbox View, and mailbox Organize. View-only users can browse authorized mail
but cannot queue mailbox synchronization from Mail.

## Tests

- Organize-authorized Mail header `Send/receive` queues `FetchImapAccount` only for active
  mailboxes the user may organize.
- Selected-folder refresh queues `FetchImapAccount` for the selected folder's account.
- View-only folder access hides and rejects direct selected-folder refresh.

## Automated Verification

- `php -l app/Modules/Email/Livewire/Tech/MailWorkspace.php`
- `php -l app/Modules/Email/Livewire/Tech/MailSidebar.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- Focused manual sync regressions passed with 3 tests and 14 assertions.
- Full `EmailModuleTest.php` passed with 136 tests and 1116 assertions after moving `Send/receive`
  from the sidebar to the message-list header.
- `php artisan optimize:clear` passed.
- `php artisan view:cache` passed.
- `php artisan knowledge:sync-docs --module=Email --push` processed 1 chapter and 1 article, and
  queued the BookStack push.
- One `php artisan queue:work --once --queue=default --tries=1` pass completed without CLI errors.
- `php artisan queue:failed` reported no failed jobs.
- `git diff --check` reported only pre-existing CRLF working-copy warnings in unrelated files.
- The same cache, Knowledge, queue, failed-job, and diff checks were rerun successfully after moving
  `Send/receive` to the message-list header.

## Documentation

- Email Knowledge and README describe Mail header manual sync and selected-folder refresh.
- TODO records the slice as implemented and leaves true conversation grouping as the next careful
  slice.
- Human review tracks manual verification under `HR-2026-08-14-001`.

## Done Criteria

- `Send/receive` is visible only when at least one organize-authorized mailbox is available.
- `Refresh folder` is visible only for a selected folder whose mailbox the user may organize.
- Both actions queue existing `FetchImapAccount` jobs and do not connect to IMAP inside Livewire.
- Account-wide provider folder discovery remains part of the refresh path.
- Focused and full Mail tests pass on Dev.
