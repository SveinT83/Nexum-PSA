# Feature Slice: Email Livewire Mail Workspace And Personal State

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-006`

## Goal

Deliver the first technician-facing `/tech/mail` workspace over provider-authoritative mailbox
placements and add Nexum-owned personal read/unread state without changing provider `Seen`.

## User-Visible Behavior

Technicians with `email.inbox_view` and mailbox View grants can open `/tech/mail`.

The workspace has:

- a default `Unread for me` view over authorized provider Inbox placements;
- separate `Inbox` and `All mail` views;
- account and provider-folder navigation;
- message-list search across subject, sender, and stored plain-text body;
- compact message-list filters for all, personal unread, mailbox unread, flagged, attachments, and
  Ticket-linked mail;
- a dense message list with mailbox, folder, attachment, Ticket, provider unread, and personal unread
  badges;
- a reading pane with sanitized HTML/plain-text body, attachments listed by stored metadata, Ticket
  link when the related Ticket can be loaded, and a compact conversation placement list;
- explicit `Mark read for me` and `Mark unread for me` actions.

Opening a message records a durable personal opened receipt with timestamp, placement, and count, but
it does not mark the message read for the user and does not queue provider `Seen`.

The Work navigation, top Work dropdown, and Warroom/My Day shortcuts now point at `/tech/mail`.
The legacy `/tech/inbox` remains available as the fallback unrouted-INBOX screen.

## Scope

- Add `email_message_user_states` for per-user Mail opened and unread state.
- Add `EmailMessageUserState` model and `EmailMessage::userStates()`.
- Add `EmailMessage::ticket()` for safe Mail-pane Ticket links by `ticket_key`.
- Add module-owned Livewire component `tech.mail.workspace`.
- Add `/tech/mail` route and controller in the Email module.
- Add tech route permission mapping for `tech.mail.*`.
- Update operational navigation to expose Mail.

## Out Of Scope

- Reply, forward, composer, drafts, sent reconciliation, and outbox.
- Provider mutation actions such as mark provider seen, move, archive, trash, delete, or flag.
- Full conversation persistence table and multi-account conversation governance.
- Personal/grouped rule builder, reprocessing controls, AI assistant actions, and automatic replies.
- Attachment download from non-legacy Mail workspace placements.

## Data Touched

- `email_message_user_states`
- `email_messages.ticket_id` read relationship only
- `email_mailbox_placements` read queries only
- `email_folders` read queries only
- Tech route permission map

## Tests

- `/tech/mail` opens through the Email module and renders only authorized provider placements.
- Folder filtering shows authorized non-INBOX folders without leaking private mailboxes.
- Message-list filters limit the visible authorized placement list without changing mailbox scope.
- Opening mail creates an opened receipt but leaves `Unread for me` unchanged.
- Explicit `Mark read for me` removes the message from a fresh default unread workspace while `Inbox`
  still shows it.
- Existing Email Inbox, polling, folder placement, rule, Ticket routing, and inbound automation tests
  still pass.

## Done Criteria

- [x] `/tech/mail` is visible from operational navigation and protected by `email.inbox_view`.
- [x] Mail content is filtered by mailbox grants before rendering.
- [x] Default view uses Nexum-owned `Unread for me`, not provider `Seen`.
- [x] Provider unread remains labelled separately.
- [x] Opening a message records opened state without changing personal unread or provider state.
- [x] Personal read/unread actions are explicit, tested, and limited to the current user.
- [x] Legacy `/tech/inbox` and Email Inbox API behavior remain unchanged.
- [x] Migration was applied on Dev as batch 74.
