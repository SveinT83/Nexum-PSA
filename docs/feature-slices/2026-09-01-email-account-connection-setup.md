# Feature Slice: Simple Email Account Connection Setup

Status: Implemented On Dev - Production Human Review Pending
Date: 2026-09-01
Parent: `../rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `../adr/2026-09-01-email-account-owned-imap-smtp-configuration.md`
Owner: Codex
Human review: `HR-2026-09-01-001`

## Goal

Replace provider-first and legacy-migration administration with one final account-owned IMAP/SMTP
setup and repair workflow.

## User-Visible Behavior

- Email Accounts always offers Add account to an authorized administrator.
- Add and Edit show ordinary incoming and outgoing server settings.
- Save and test checks authenticated IMAP and SMTP through the bounded Email worker.
- A passing check activates the account when Active was requested.
- A failure leaves the same account editable and inactive with safe protocol-specific guidance.
- Password fields are blank on edit and preserve the stored password unless a replacement is entered.
- Ordinary navigation no longer exposes Email Providers or Legacy mailbox migration.

## Scope

- Email account controller, form, index and connection health presentation.
- Encrypted account-owned password storage.
- Background connection-check and activation job.
- Deprecation/redirect of ordinary provider administration.
- Tests, Knowledge, TODO and human-review updates.

## Out Of Scope

- OAuth driver setup.
- Destructive removal of historical Integration provider/migration records.
- Sending a real message to a recipient; SMTP authentication proves write capability without
  transmitting mail.
- Bulk credential migration.

## Data Touched

- Existing `email_accounts` endpoint, encrypted credential, binding and health columns.
- Existing queue and failed-job infrastructure.
- Historical Integration provider records remain unchanged unless an account is explicitly re-saved.

## Permissions

- Existing Email account binding/administration permission remains required.
- Account configuration never grants mailbox content access.
- Existing per-account view/organize/send grants remain unchanged.

## Tests

- Create stores encrypted account-owned credentials and dispatches the bounded check.
- Edit preserves blank passwords and replaces entered passwords.
- Integration-bound account can be corrected in place by re-entering complete settings.
- Stale queued checks cannot activate a newer configuration.
- Passing IMAP/SMTP activates only when requested; failure remains inactive and editable.
- UI contains no provider/staged/legacy-migration workflow.
- Existing Mail runtime, Ticket sending and permission tests remain green.

## Documentation

- Amend the parent RFC.
- Add the superseding ADR.
- Update Email and Integration Knowledge.
- Reconcile `docs/TODO.md` and `docs/human-review.md`.

## Done Criteria

- [x] Approved RFC amendment and accepted ADR are present.
- [x] One account-owned Add/Edit form is implemented.
- [x] Background IMAP/SMTP test and safe activation are implemented.
- [x] Provider/migration lifecycle is absent from ordinary UI.
- [x] Relevant Dev tests pass.
- [ ] Production browser review confirms desktop and narrow layouts.
- [x] TODO and human-review evidence are current.
