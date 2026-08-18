# Feature Slice: Email Mail Provider Folder Rename, Move, And Delete

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Let technicians safely create, rename, move, and delete custom provider folders from Nexum while
keeping the IMAP server authoritative for folder state.

## User-Visible Behavior

When the technician can organize at least one mailbox, the Mail sidebar shows a gear button on the
right side of the Folders header. The gear opens the folder manager for the currently selected
mailbox, the selected folder's mailbox, the last managed mailbox, or the first organize-authorized
mailbox. If more than one mailbox can be organized, the modal header includes a mailbox selector.

The modal lists the mailbox folders as an expandable tree with parent folders collapsed by default,
shows mail/rule/operation/subfolder blockers, and exposes rename/move/delete actions only for custom
folders that are safe to mutate. Folder rows without available actions show the reason, such as
system folder, container folder, or has subfolders. New custom folders can be created at mailbox root
or inside a selected parent folder. Safe custom leaf folders can be moved to root or below another
folder. After a folder is created, the manager stays open and expands the relevant parent path so the
new folder can be inspected without switching the message list into that folder. If a custom folder
contains mail, delete is blocked until the technician moves those
messages to another selectable folder from the same modal. Nexum never offers a destructive delete
that removes the messages with the folder.

## Scope

- Replace the old inline provider-folder create control with a mailbox-scoped folder manager modal.
- Show provider folders as an expandable tree with parent folders collapsed by default so large
  folder lists remain compact.
- Let users create custom provider folders at mailbox root or inside an existing parent folder.
- Add provider folder rename, move, and delete actions guarded by mailbox Organize access.
- Use IMAP folder create, rename, and delete support through `ImapClient`; folder move is executed as
  a provider rename from the old path to the new path.
- Execute rename/move/delete through the existing idempotent remote-operation ledger.
- Reproject local folder paths, mailbox placements, and current personal rule target paths after a
  successful provider rename or move.
- Mark a successfully deleted custom folder as nonselectable and sync-disabled locally after the IMAP
  server accepts the delete.
- Require folder mail to be moved before delete, with a bounded batch move action inside the modal.

## Out Of Scope

- Deleting system/special-use folders such as INBOX, Sent, Drafts, Trash, Archive, Junk, or Spam.
- Recursive folder rename/move/delete for folders with children.
- Deleting provider mail together with a folder.
- Bulk folder operations across multiple mailboxes.
- Automatic rule rewriting beyond the current editable rule definition that targets the renamed
  folder by ID.

## Data Touched

- Existing `email_folders`.
- Existing `email_mailbox_placements`.
- Existing `email_remote_operations`.
- Current `email_rules.actions_json` rows that reference a renamed folder by `target_folder_id`.
- Provider IMAP folder list/state.

No new database table or migration is required.

## Permissions

Folder management requires one unambiguous mailbox and effective mailbox Organize access. View-only
mailbox access can see existing folders but cannot open or submit folder-management actions.

## Tests

- A user with Organize access can rename a custom provider folder and see local folder, placement,
  and personal rule target paths updated after provider acknowledgement.
- A user with Organize access can create a custom provider folder inside a selected parent folder.
- Creating a custom folder under INBOX uses the mailbox provider's known delimiter and does not run
  an IMAP expunge after CREATE.
- A user with Organize access can move a safe custom provider leaf folder to a selected parent
  folder and see the operation recorded as a provider folder move.
- A folder containing mail cannot be deleted until the mail is moved to another selectable same-
  account folder.
- A moved-empty custom folder can be deleted after provider acknowledgement.
- System folders and rule-referenced folders are blocked from unsafe folder actions.
- Existing provider folder create and provider message move tests continue to pass.

## Automated Verification

- `php -l app/Modules/Email/Actions/ManageProviderEmailFolder.php`
- `php -l app/Modules/Email/Actions/RunEmailRemoteOperation.php`
- `php -l app/Modules/Email/Services/ImapClient.php`
- `php -l app/Modules/Email/Livewire/Tech/MailWorkspace.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- Focused folder-manager regressions passed with 13 tests and 85 assertions.
- Full `EmailModuleTest.php` passed with 131 tests and 1090 assertions.
- `php artisan optimize:clear` passed.
- `php artisan view:cache` passed.
- `php artisan knowledge:sync-docs --module=Email --push` passed and queued the BookStack push.
- One `php artisan queue:work --once --queue=default --tries=1` pass completed without CLI errors.
- `php artisan queue:failed` reported no failed jobs.
- `git diff --check` reported only pre-existing CRLF working-copy warnings in unrelated files.

## Documentation

- Email README and Knowledge overview describe the provider folder manager.
- TODO marks provider folder management as implemented and keeps later cleanup/retry/undo work
  separate.
- Human review tracks manual verification under `HR-2026-08-13-032`.

## Done Criteria

- The Folders header gear appears only for one unambiguous organize-authorized mailbox.
- Rename/move/delete actions are hidden or blocked for provider system folders and unsafe custom
  folders.
- Parent folders are collapsed by default; subfolders are visible after expansion and leaf custom
  folders retain their actions.
- Nexum calls the provider IMAP operation before changing local folder state.
- A folder with active mail must be emptied through explicit move actions before delete.
- Focused and full Mail tests pass on Dev.
