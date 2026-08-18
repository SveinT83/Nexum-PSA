# Feature Slice: Email Mail Reply Composer With Attachments

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-008`

## Goal

Add the first real Mail reply path to `/tech/mail`: a selected-message Reply action with editable
To, Cc, subject, message body, and attachments, sent through the selected mailbox account.

## User-Visible Behavior

Technicians with `email.inbox_view`, `email.inbox_manage`, mailbox View access, mailbox Send access,
and an active SMTP configuration see a Reply button in the Mail reading pane. Reply opens a compact
composer with To, Cc, Subject, Message, and Attach controls. The To field defaults to the selected
message sender, Subject defaults to `Re: ...`, and the user may add comma, semicolon, or newline
separated To/Cc recipients.

Sending uses the selected mailbox's SMTP configuration, preserves standards-based reply headers when
the source message has a Message-ID/References chain, attaches up to five files, and writes an
idempotent outbound Email log before reporting success. Users without mailbox Send access do not see
the Reply action and cannot invoke it successfully.

## Scope

- Extend `SmtpAccountMailer` with a backward-compatible multi-recipient send method and optional
  reply threading headers.
- Add a shared `SendEmailReply` Action for authorization, recipient parsing, attachment payload
  preparation, idempotency, SMTP send, and sanitized Email logging.
- Add an `email_logs.idempotency_key` column so successful reply submits cannot send twice.
- Add Reply UI to the existing Livewire Mail workspace.
- Add tests for successful To/Cc/attachment reply, missing Send grant, and successful-submit
  idempotency.

## Out Of Scope

- Drafts, autosave, shared draft locks, and stale draft conflict handling.
- Reply All, forward, new message compose, signatures, and rich HTML editing.
- Provider Sent folder append/reconciliation or local Sent placement projection.
- Ticket evidence capture, Ticket timeline projection, portal audience handling, or Ticket reply
  replacement.
- API endpoint for multipart reply sending.
- Permanent delete, automatic replies, and AI-generated drafts.

## Data Touched

- `email_logs.idempotency_key`
- Existing `email_logs` outbound records
- No provider folders, mailbox placements, Ticket records, Signals, or provider read state are
  changed by this slice.

## Permissions

Reply requires:

- `email.inbox_view`
- `email.inbox_manage`
- effective mailbox View access
- effective mailbox Send access

Mailbox Organize is not required for sending a reply. Opening, personal `Unread for me`, provider
`Seen`, flags, folders, and Ticket state remain separate.

## Tests

- Mail workspace sends a reply with To, Cc, one attachment, and In-Reply-To/References headers.
- View-only or no-Send mailbox grants do not expose the Reply control and cannot start reply.
- `SendEmailReply` returns the existing successful outbound log for a repeated idempotency key rather
  than sending the same message twice.

## Documentation

Email README, Email Knowledge, TODO, and human-review records are updated.

## Done Criteria

- [x] Reply is visible only when the selected mailbox can be viewed and used as an SMTP sender.
- [x] To and Cc fields accept validated email recipients.
- [x] Attachments can be added to the SMTP send payload.
- [x] Source Message-ID/References are preserved as reply headers when available.
- [x] Successful reply sends create idempotent outbound Email logs.
- [x] Reply does not mutate provider `Seen`, personal `Unread for me`, folders, Tickets, or Signals.
- [x] Focused tests pass on Dev after migration.
