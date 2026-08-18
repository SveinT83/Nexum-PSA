# Feature Slice: Email Mail Provider Folder Create

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Let technicians create a new custom provider folder from Nexum and immediately project it into the
Mail workspace.

## User-Visible Behavior

When a technician selects one mailbox they can organize, the Mail sidebar shows a compact folder
create action. Entering a custom provider folder path creates the folder on the IMAP server, projects
the discovered folder into `email_folders`, selects the new folder in `/tech/mail`, and shows a
success message.

## Scope

- Add a create-only provider folder action guarded by mailbox Organize access.
- Add IMAP folder create support to `ImapClient`.
- Add a compact create form in the Mail sidebar when exactly one mailbox is selected and the user can
  organize it.
- Project the created provider folder through `EmailFolderProjector`.
- Keep rename/delete out of this slice because those operations are destructive or can strand remote
  state.

## Out Of Scope

- Renaming provider folders.
- Deleting provider folders.
- Creating system/special-use folders such as INBOX, Sent, Drafts, Trash, Archive, Junk, or Spam.
- Moving messages automatically into the new folder.
- Retrying failed provider folder creates through the remote-operation ledger.

## Data Touched

- Existing `email_folders`.
- Existing mailbox access grants.
- Provider IMAP folder list/state.

No new database table is required.

## Permissions

Folder creation requires effective mailbox Organize access for the selected mailbox. View-only
mailbox access can see existing folders but cannot open the folder-create form or submit the action.

## Tests

- A user with Organize access for the selected mailbox can create a provider folder and see the local
  folder projection.
- A View-only user cannot open the provider folder create form.
- Existing Mail folder, move, provider Drafts, draft attachment, and send tests continue to pass.

## Automated Verification

- `php -l app/Modules/Email/Actions/CreateProviderEmailFolder.php`
- `php -l app/Modules/Email/Services/ImapClient.php`
- `php -l app/Modules/Email/Livewire/Tech/MailWorkspace.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- `umask 0002; HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php --filter='mail_sidebar_creates_provider_folder_for_selected_organize_mailbox|mail_sidebar_requires_organize_access_before_creating_provider_folder'`
  passed with 2 tests and 11 assertions.
- `umask 0002; HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php`
  passed with 114 tests and 966 assertions.

## Documentation

- Email README and Knowledge overview describe create-only provider folder mirroring.
- Provider folder rename/delete is handled by the later
  `docs/feature-slices/2026-08-13-email-mail-provider-folder-rename-delete.md` slice.
- Human review tracks manual verification.

## Done Criteria

- Folder create is available only for one selected organize-authorized mailbox.
- Nexum calls the provider IMAP CREATE command before projecting the folder locally.
- The created folder is immediately selectable in the sidebar.
- Focused and full Mail tests pass on Dev.
