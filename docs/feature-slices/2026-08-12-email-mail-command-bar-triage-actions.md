# Feature Slice: Email Mail Command Bar Triage Actions

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-010`

## Goal

Clean up the `/tech/mail` reading-pane command bar so the common actions stay compact while less
common provider/global actions move into a More menu.

## User-Visible Behavior

The command bar shows Reply, Forward, one personal Mark read action, Spam, Ticket, Trash, and More.
The visible Mark read action only changes the current user's Nexum `Unread for me` state. Provider
read/unread, flag/unflag, and archive actions move into the More menu.

Trash is an icon-only visible action. Spam is an icon-only visible action that updates the spam rule
and, when an Archive folder is available, archives the provider placement through the existing remote
operation path. Ticket is an icon-only visible action for creating or linking a Ticket from the
selected email when the user has Ticket create permission; linked email shows an Open Ticket icon
instead.

## Scope

- Reorder and compact the Mail reading-pane action bar.
- Add Livewire actions for Mark read for me, Spam, and Create/Link Ticket from selected mail.
- Reuse existing `MarkEmailAsSpam`, provider Archive, and `CreateTicketFromInboundEmail` behavior.
- Keep More menu limited to implemented actions only.
- Add focused tests for the compact command bar, spam action, and Ticket action.

## Out Of Scope

- Arbitrary Move to folder.
- Generic Add rule UI.
- Link-to-existing-ticket picker.
- Provider Junk-folder move.
- Bulk actions and keyboard shortcut customization.

## Data Touched

- Existing `email_message_user_states`
- Existing `email_rules`, Email tags, and `email_remote_operations` for Spam
- Existing Ticket records and linked Email/Ticket message rows for Ticket action
- No new database migration is required.

## Permissions

- Mark read for me requires mailbox View access.
- Provider/global actions, Spam, Archive, and Trash require mailbox Organize access.
- Ticket action requires mailbox Organize access and `ticket.create`.
- Open Ticket requires `ticket.view`.

## Tests

- Mail command bar exposes one personal Mark read action and moves provider-read actions to More.
- Spam creates/updates the spam rule and archives the provider placement when Archive is available.
- Ticket action creates/links a Ticket only when the user has `ticket.create`.

## Documentation

Email README, Email Knowledge, TODO, and human-review records are updated.

## Done Criteria

- [x] Main command bar has one visible Mark read button for personal read state.
- [x] Provider read/unread and flag/archive actions are in More actions.
- [x] Trash, Spam, and Ticket actions are visible as compact icon actions when authorized.
- [x] No unfinished Move-to-folder or Add-rule controls are visible.
- [x] Focused tests pass on Dev.
