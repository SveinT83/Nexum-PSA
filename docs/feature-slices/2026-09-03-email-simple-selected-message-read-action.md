# Feature Slice: Simple Selected-Message Read Action

Status: Done
Date: 2026-09-03
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Make read/unread behavior in the ordinary Mail reader familiar and direct without weakening
per-user shared-mailbox state or personal mailbox provider synchronization.

## User-Visible Behavior

The selected message shows one `Mark as read` or `Mark as unread` button. It acts immediately without
a preview, confirmation panel, migration wording, or extra server-read choice.

Shared/system mail changes only for the signed-in technician. A personal mailbox owner also updates
the provider. Failures to update the personal provider are reported without pretending the server
changed.

## Scope

- Direct selected-message read/unread Livewire actions.
- One ordinary reader control.
- Shared two-user isolation.
- Personal-owner provider Seen/Unseen mirroring through the existing ledger.
- Removal of ordinary conversation acknowledgement preview/apply controls.
- RFC, ADR, Knowledge, module README, TODO, and human-review reconciliation.

## Out Of Scope

- Removing the guarded default-off conversation acknowledgement API and ledger.
- Bulk read/unread.
- Changing opening behavior, folder unread counts, Ticket state, or Notification state.
- Database or permission changes.

## Data Touched

`email_message_user_states` and, only for a personal mailbox owner, existing
`email_remote_operations` plus the selected placement's provider projection. No migration.

## Permissions

Existing mailbox View and personal-state authority apply. Existing personal-owner mailbox authority
controls provider mirroring. No new permission is introduced.

## Tests

The isolated SQLite MailWorkspace regression passes 44 tests / 555 assertions, including shared
two-user isolation, personal-owner provider mirroring, and ordinary-reader UI assertions. A deliberate
MySQL-configured probe confirms the test bootstrap fails before any assertion or reset.

## Documentation

The parent RFC, a superseding ADR, Email Knowledge, module README, TODO, and human review
`HR-2026-09-03-002` describe the final behavior.

## Done Criteria

- [x] One direct selected-message read/unread action is implemented.
- [x] Shared and personal authority paths are separated automatically.
- [x] Ordinary preview and provider Seen controls are removed.
- [x] Automated regressions pass with isolated SQLite.
- [x] Dev Blade views are rebuilt.
- [x] Documentation and review tracking describe the final behavior.
