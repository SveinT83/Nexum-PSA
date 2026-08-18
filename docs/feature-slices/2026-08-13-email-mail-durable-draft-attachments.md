# Feature Slice: Email Mail Durable Draft Attachments

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Make Mail composer draft attachments durable so saved drafts can be restored, sent, and synced to
provider Drafts without silently losing files.

## User-Visible Behavior

When a technician adds attachments to a Compose, Reply, Reply All, or Forward composer and saves the
draft, Nexum stores those files with the local draft. Reopening the same draft shows the saved
attachments. Sending the draft includes both restored draft attachments and any new temporary uploads.
Discarding or successfully sending a draft cleans up the saved draft attachment files.

Manual provider Drafts sync now includes durable draft attachments. Autosave remains local-only, but
it may persist selected draft attachments so they survive closing the composer.

## Scope

- Add `email_composer_draft_attachments` for Mail-owned draft attachment metadata.
- Store uploaded draft attachments through the verified Email private-storage writer on the
  established local disk under a draft-scoped `email/*` path.
- Restore saved attachment metadata in the Livewire composer.
- Allow saved draft attachments to be removed from the composer.
- Send saved draft attachments through the existing SMTP send action.
- Reauthorize any client-supplied attachment IDs against the exact active draft and current mailbox
  composer context before handing a stored file to SMTP.
- Include saved draft attachments in provider Drafts MIME append.
- Clean up saved draft attachment files and metadata when a draft is sent or discarded.

## Out Of Scope

- Inline image editing or CID rewriting.
- Attachment preview/download UI from the draft editor.
- Direct editing of imported provider Drafts placements.
- Shared draft locks, responder reservations, typing presence, or conflict UI.
- Provider folder create/rename/delete mirrored to IMAP.

## Data Touched

- Migration `2026_08_13_130000_create_email_composer_draft_attachments_table`.
- New model `EmailComposerDraftAttachment`.
- Existing `email_composer_drafts`, send pipeline, provider Drafts sync service, and Mail workspace.

## Permissions

Draft attachment save, restore, send, and removal use the same mailbox authorization as the owning
composer draft. Compose drafts require Send access; Reply, Reply All, and Forward drafts require View
and Send access for the selected placement's mailbox.

## Tests

- Saving a compose draft with an attachment stores metadata and file content.
- Restoring the draft shows the saved attachment.
- Provider Drafts sync includes the saved attachment filename in the appended MIME.
- Sending a restored draft passes the saved attachment to SMTP.
- Manipulating Livewire attachment IDs cannot send a same-user file from another draft/account.
- Successful send deletes saved draft attachment metadata and files.
- Existing draft, provider Drafts, and send tests continue to pass.

## Automated Verification

- `php -l database/migrations/2026_08_13_130000_create_email_composer_draft_attachments_table.php`
- `php -l app/Modules/Email/Models/EmailComposerDraftAttachment.php`
- `php -l app/Modules/Email/Models/EmailComposerDraft.php`
- `php -l app/Modules/Email/Services/EmailComposerDraftService.php`
- `php -l app/Modules/Email/Services/EmailProviderDraftSyncService.php`
- `php -l app/Modules/Email/Actions/SendEmailComposerMessage.php`
- `php -l app/Modules/Email/Livewire/Tech/MailWorkspace.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- `umask 0002; HOME=/tmp php artisan migrate`
- `umask 0002; HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php --filter='mail_workspace_persists_restores_sends_and_cleans_durable_draft_attachment|mail_workspace_manual_save_syncs_compose_draft_to_provider_drafts|mail_workspace_send_deletes_synced_provider_draft_copy_after_smtp_success|mail_workspace_saves_and_restores_new_compose_draft|mail_workspace_restores_reply_draft_and_marks_it_sent_after_smtp_success'`
  passed with 5 tests and 72 assertions.
- `umask 0002; HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php`
  passed with 112 tests and 955 assertions.
- The later Email private-storage contract verifies restrictive-umask directory/file modes, bounded
  `email/*` paths, and failed-write behavior; affected storage/send coverage passes 16 tests / 125
  assertions.
- The later exact-active-draft attachment isolation regression passes 1 test / 6 assertions.
- The complete expanded composer lifecycle file passes 4 tests / 26 assertions.

## Documentation

- Email README and Knowledge overview describe durable draft attachments and cleanup boundaries.
- TODO keeps direct provider Drafts editing and provider folder create/rename/delete as later slices.
- Human review tracks manual verification.

## Done Criteria

- Saved draft attachments survive composer close and restore.
- Sending a restored draft includes saved attachments.
- Only attachments belonging to the exact active authorized draft can reach SMTP.
- Provider Drafts sync includes saved draft attachments.
- Sent/discarded draft attachments are cleaned up.
- Focused and full Mail tests pass on Dev.
