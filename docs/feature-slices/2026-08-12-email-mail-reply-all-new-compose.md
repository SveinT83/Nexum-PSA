# Feature Slice: Email Mail Reply All And New Compose

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Add normal Mail composer workflows for Reply All and new outbound messages in `/tech/mail`.

## User-Visible Behavior

Technicians with effective mailbox Send access can start a new message from the Mail list header.
The composer exposes From, To, Cc, Subject, a rich HTML editor with source mode, and attachments.

Reply All is available beside Reply and Forward only when the computed Reply All recipient list has
more than one recipient after the selected mailbox's own address aliases are excluded. It keeps the
same reply threading headers as Reply, deduplicates To/Cc, and fills recipients from the source
message sender plus stored To/Cc recipients.

## Scope

- Extend `SendEmailComposerMessage` with `reply_all` and `compose` modes.
- Keep `SendEmailReply` as a reply-only compatibility wrapper.
- Reuse the rich HTML composer partial for Reply, Reply All, Forward, and Compose.
- Allow new compose to send from accounts where the actor has effective Send access, without
  requiring a selected mailbox placement.
- Log accepted outbound messages idempotently in `email_logs` with mode-specific codes.

## Out Of Scope

- Draft autosave and shared draft locking.
- Provider Sent folder append or reconciliation.
- Ticket evidence capture for outbound Mail messages.
- API multipart send endpoints.
- Automatic replies and AI-generated send actions.

## Data Touched

- `email_logs` for outbound `MAIL_REPLY_ALL_SENT` and `MAIL_COMPOSE_SENT` records.
- No schema migration.
- SMTP provider is called through the existing `SmtpAccountMailer`.

## Permissions

Reply All requires the same effective View and Send access as Reply because it reads source message
recipients and sends from that placement's account.

New compose requires global Email manage ability and effective mailbox Send access for the selected
sender account. It does not grant message content access to send-only accounts.

## Tests

- Feature test for Reply All recipient defaults, threading headers, SMTP payload, and Email log.
- Feature test for new compose from a send-authorized account without a selected message.
- Existing Reply, Forward, idempotency, authorization, and Mail workspace tests remain relevant.

## Documentation

- Email README / Knowledge overview updated.
- TODO active Mail workstream updated.
- Human review tracked in `HR-2026-08-12-014`.

## Done Criteria

- Reply All is visible only when selected mailbox Send access exists and additional recipients would
  actually receive the response.
- Reply All excludes self and deduplicates recipients across To and Cc.
- New compose can send without selecting a message.
- Outbound HTML is sanitized and plain text is generated through the existing path.
- Focused and full Email tests pass on Dev.
