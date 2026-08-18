# Feature Slice: Email Mail Multi-Conversation Ticket Links

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Allow one Ticket to collect several independent email conversations without replacing the existing
`TD-...` subject correlation or removing the source email from its mailbox.

## User-Visible Behavior

The Mail action menu can open a `Link existing Ticket` panel for organize-authorized, non-draft
messages when the user also has `ticket.update`. The technician enters a Ticket key such as
`TD-2026-000001` or a numeric Ticket ID. Nexum links the selected email to that Ticket through the
existing Ticket email-message action, copies attachments as before, and records a Mail-owned
conversation-link row.

Messages in the same RFC thread share a conversation key derived from `In-Reply-To` or `References`.
The Mail reader shows when multiple active Ticket conversation links exist for the selected
conversation.

## Scope

- Add `email_ticket_conversation_links`.
- Add `EmailTicketConversationLink` model.
- Add a Mail action that wraps the existing `LinkInboundEmailToTicket` action.
- Link new Tickets created from Mail into the conversation-link ledger.
- Add a collapsible Ticket link panel in `/tech/mail`.

## Out Of Scope

- Removing a Ticket link from the UI.
- Linking one email to several active Tickets.
- Ticket timeline redesign.
- Automatic AI-controlled Ticket updates.

## Data Touched

- New `email_ticket_conversation_links`.
- Existing `email_messages.ticket_id`.
- Existing `ticket_messages`, `ticket_events`, and Ticket attachments created by
  `LinkInboundEmailToTicket`.

## Permissions

Creating a new Ticket from Mail still requires `ticket.create` and mailbox Organize access. Linking
an existing Ticket requires `ticket.update` and mailbox Organize access. Opening an already linked
Ticket still requires `ticket.view`.

## Tests

- Two messages in the same RFC thread can be linked to the same existing Ticket.
- Both messages create active conversation-link rows with the same conversation key.
- Existing Ticket message capture still records both inbound emails.

## Automated Verification

- Focused Mail regressions including this slice passed on Dev with 6 tests and 50 assertions.
- Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Dev migration, cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, no failed jobs, route registration, and git diff checks were also completed.

## Done Criteria

- Mail can link multiple conversations to one Ticket through guarded user action.
- Source email remains in the provider mailbox placement.
- Existing Ticket-number subject correlation remains unchanged.
