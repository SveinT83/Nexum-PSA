# Feature Slice: Email Mail Forward And Rich HTML Composer

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-009`

## Goal

Add a selected-message Forward action to `/tech/mail` and replace the plain reply textarea with a
Mail-owned rich HTML composer that can send formatted email without introducing the future shared
content editor platform.

## User-Visible Behavior

Technicians with `email.inbox_view`, `email.inbox_manage`, mailbox View access, mailbox Send access,
and an active SMTP configuration see Reply and Forward actions in the Mail reading pane. Both actions
open the same compact composer with To, Cc, Subject, formatting controls, optional HTML source mode,
and Attach controls.

Reply keeps the current defaults: To is the selected message sender and Subject defaults to `Re: ...`.
Forward starts with an empty To field, Subject defaults to `Fwd: ...`, and the editor includes a safe
forwarded-message block containing the original sender, recipients, date, subject, and readable body.
Users may format body text with common inline and list controls before sending.

## Scope

- Generalize the Mail sending action so Reply and Forward share authorization, recipient parsing,
  HTML sanitization, text fallback generation, attachment payload preparation, idempotency, SMTP
  sending, and outbound Email logging.
- Keep Reply threading headers for replies and send Forward as a new outbound message linked to the
  original source only through the Email log context.
- Add a local Bootstrap/Alpine rich HTML editor to the Livewire Mail workspace.
- Add Forward UI to the existing Mail reading pane.
- Add tests for rich HTML reply sending, forward defaults/body/logging, and missing Send grant.

## Out Of Scope

- The future reusable WordPress-like shared HTML content editor for Marketing, Email templates,
  Documentation, Knowledge, and other content surfaces.
- Drafts, autosave, shared draft locks, stale draft conflict handling, and signatures.
- Reply All, new message compose, templates, AI-generated drafts, and automatic replies.
- Automatically reattaching original inbound attachments to forwarded messages.
- Provider Sent folder append/reconciliation or local Sent placement projection.
- Ticket evidence capture, Ticket timeline projection, portal audience handling, or Ticket reply
  replacement.
- API endpoint for multipart reply or forward sending.

## Data Touched

- Existing `email_logs.idempotency_key`
- Existing `email_logs` outbound records
- No new database migration is required.
- No provider folders, mailbox placements, Ticket records, Signals, or provider read state are
  changed by this slice.

## Permissions

Reply and Forward require:

- `email.inbox_view`
- `email.inbox_manage`
- effective mailbox View access
- effective mailbox Send access

Mailbox Organize is not required for sending. Opening, personal `Unread for me`, provider `Seen`,
flags, folders, and Ticket state remain separate.

## Tests

- Mail workspace sends a reply using rich HTML while preserving text fallback, Cc, attachments, and
  reply threading headers.
- Mail workspace opens Forward with `Fwd: ...`, safe forwarded-message content, empty To, and sends
  through SMTP with no reply threading headers.
- View-only or no-Send mailbox grants do not expose Reply/Forward controls and cannot start either
  composer.
- Successful-submit idempotency remains enforced independently for Reply and Forward.

## Documentation

Email README, Email Knowledge, TODO, and human-review records are updated.

## Done Criteria

- [x] Reply and Forward use one shared Mail composer path.
- [x] The composer supports common rich-text formatting controls and an HTML source mode.
- [x] Outgoing HTML is sanitized before SMTP send and a plain-text fallback is generated.
- [x] Forward includes original readable message context but does not automatically attach original
      inbound attachments.
- [x] Successful sends create idempotent outbound Email logs with distinct Reply/Forward codes.
- [x] Reply and Forward do not mutate provider `Seen`, personal `Unread for me`, folders, Tickets, or
      Signals.
- [x] Focused tests pass on Dev.
