# Feature Slice: Email Mail Move To Folder

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Let authorized technicians move a selected Mail placement to any selectable provider folder in the
same mailbox.

## User-Visible Behavior

The selected message's More menu shows Move to folder when the account has at least one selectable
target folder other than the current folder. Opening it reveals a compact folder selector and Move
button. A successful provider move hides the source placement and projects the returned target UID
as an active placement in the selected folder.

## Scope

- Add a `move` provider operation to `PerformEmailRemoteOperation`.
- Reuse `RunEmailRemoteOperation::applyMove()` for custom folder moves.
- Add Livewire UI state and action methods for the move panel.
- Extend the mailbox placement operations API with `operation=move` and `target_folder_id`.
- Keep Archive and Trash on their existing provider-special-folder behavior.

## Out Of Scope

- Bulk moves.
- Permanent provider delete.
- Creating provider folders from Nexum.
- Rule-driven move suggestions.
- The More-menu Add rule workflow. That remains held until the UI can show whether a selected
  message has already been processed by a rule.

## Data Touched

- `email_remote_operations` records the idempotent move request and provider response.
- `email_mailbox_placements` hides the source placement and creates/updates the target placement
  when the provider returns a target UID.
- No schema migration.

## Permissions

Move requires the same effective global and mailbox Organize authorization as provider read/unread,
flag, archive, and trash actions. The target folder must belong to the same account, be selectable,
and differ from the source folder.

## Tests

- Livewire feature test for selecting a target folder, provider move call, hidden source placement,
  target projection, and remote-operation ledger.
- API feature test for `operation=move` with `target_folder_id`.
- Existing archive/trash/provider-operation authorization tests remain relevant.

## Documentation

- Email README / Knowledge overview updated.
- TODO active Mail workstream updated.
- Human review tracked in `HR-2026-08-12-014`.

## Done Criteria

- Move-to-folder is hidden when no valid target folder exists.
- Moving to the same folder or another account's folder is rejected server-side.
- Successful moves keep provider folder state authoritative.
- Focused and full Email tests pass on Dev.
